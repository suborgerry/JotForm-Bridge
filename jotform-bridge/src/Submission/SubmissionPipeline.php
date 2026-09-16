<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\ConditionalLogic;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The whole server side of a submission, from integration slug to Jotform.
 *
 * The REST controller owns none of this: it unwraps the HTTP request, calls
 * submit() and serializes the outcome. That keeps the pipeline testable without
 * WordPress and makes the security-relevant order explicit in one place —
 * site-wide rate limit, lookup, per-integration rate limit, quota guard,
 * schema, validation, duplicate check, spam check, mapping, upstream call. Each
 * step is cheaper than the one after it, so the requests worth refusing are
 * refused before the expensive work happens.
 *
 * Every one of those refusals that is not the visitor's own doing answers
 * identically, so the endpoint cannot be asked which slugs exist. Which of them
 * it was goes to the debug log for the site owner.
 *
 * The visitor never influences which Jotform form is used: the slug is a lookup
 * key and nothing more.
 */
final class SubmissionPipeline
{
    /**
     * How long an identical, already accepted submission is refused, in seconds.
     *
     * This is not rate limiting: it only catches the same visitor sending the
     * same values twice — a double click, a retried request, a bounced network —
     * which would otherwise become two Jotform submissions. A failed submission
     * is never fingerprinted, so retrying after an error works immediately.
     */
    public const DUPLICATE_WINDOW = 30;

    public const DUPLICATE_TRANSIENT_PREFIX = 'jotform_bridge_sent_';

    private IntegrationRepository $integrations;

    private SchemaRepository $schemas;

    private JotformClient $client;

    private SubmissionValidator $validator;

    private SubmissionMapper $mapper;

    private SpamGuard $spam;

    private ?Logger $logger;

    private RedirectTarget $redirects;

    private RateLimiter $limiter;

    private QuotaGuard $quota;

    /**
     * What has to be handed in, and nothing else.
     *
     * This used to take ten parameters, seven of them optional. Five of those
     * seven — the validator, the mapper, the spam guard, the redirect resolver
     * and the rate limiter — were never supplied by the composition root, by a
     * test, or by anything else: every caller took the default. They were not
     * seams, only the appearance of one, and they made the dependency graph
     * unreadable in exchange for nothing. All five are stateless services with
     * no constructor of their own, so substituting them buys nothing that the
     * filters they already expose do not.
     *
     * The two that remain optional are supplied, and for a reason each. The
     * quota guard has to be the same instance the admin notice reads, so the
     * composition root owns it. The logger is absent on purpose in the unit
     * tests, which is how they assert that nothing here requires one.
     */
    public function __construct(
        IntegrationRepository $integrations,
        SchemaRepository $schemas,
        JotformClient $client,
        ?QuotaGuard $quota = null,
        ?Logger $logger = null
    ) {
        $this->integrations = $integrations;
        $this->schemas      = $schemas;
        $this->client       = $client;
        $this->quota        = $quota ?? new QuotaGuard();
        $this->logger       = $logger;
        $this->validator    = new SubmissionValidator();
        $this->mapper       = new SubmissionMapper();
        $this->spam         = new SpamGuard();
        $this->redirects    = new RedirectTarget();
        $this->limiter      = new RateLimiter();
    }

    /**
     * @param mixed                $fields  Raw `fields` value from the request body.
     * @param array<string, mixed> $context Request metadata for the spam check.
     */
    public function submit(string $slug, $fields, array $context = []): SubmissionOutcome
    {
        $slug = sanitize_key($slug);
        $ip   = $this->ip($context);

        // Before anything is looked up. An unresolved slug is the cheapest
        // answer the endpoint has, so without this the whole rate limit could be
        // sidestepped by probing for slugs instead of naming a real one.
        $wait = $this->limiter->checkGlobal($ip);

        if ($wait > 0) {
            // No integration has been resolved yet, so the slug the request
            // named is not to be trusted as a bucket name.
            return $this->throttled($slug, $wait);
        }

        $integration = $slug === '' ? null : $this->integrations->get($slug);

        if ($integration === null) {
            $this->note('A submission named an integration that does not exist.', [
                'integration' => $slug,
            ]);

            return $this->unavailable();
        }

        // The tighter, per-integration budget. Like the site-wide one it needs
        // neither the schema nor the values, so a flood is turned away before
        // the request costs a schema read or a pass through the validator.
        $wait = $this->limiter->check($slug, $ip);

        if ($wait > 0) {
            return $this->throttled($slug, $wait);
        }

        // The account-wide circuit breaker. It answers before the schema is
        // read for the same reason the rate limit does, and it answers with the
        // ordinary upstream message: which internal ceiling was reached is not
        // something the endpoint should be willing to tell anyone.
        $blocked = $this->quota->check();

        if ($blocked !== '') {
            $this->log('Submission blocked by the quota guard.', [
                'integration' => $slug,
                'reason'      => $blocked,
            ]);

            return $this->unavailable();
        }

        if (!is_array($fields)) {
            return SubmissionOutcome::invalid(
                __('Validation failed.', 'jotform-bridge'),
                [ValidationResult::FORM_KEY => __('No form data was submitted.', 'jotform-bridge')]
            );
        }

        $response = $this->schemas->get($integration->formId());

        // Never a network call: the schema either was synced by an administrator
        // or it was not, and a visitor's submission is not the moment to find
        // out what Jotform currently thinks the form looks like.
        if (!$response->isSuccess()) {
            $this->log('Submission blocked: no synced schema for this form.', [
                'integration' => $slug,
                'error'       => $response->errorCode(),
            ]);

            return $this->unavailable();
        }

        $schema = $response->data()['schema'];

        if (!$schema->isUsable()) {
            $this->log('Submission blocked: the form schema has unresolved errors.', [
                'integration' => $slug,
            ]);

            return $this->unavailable();
        }

        if (ConditionalLogic::errors($integration->conditions(), $schema) !== []) {
            $this->log('Submission blocked: invalid conditional rules.', ['integration' => $slug]);
            return $this->unavailable();
        }

        $result = $this->validator->validate($schema, $fields, $integration->conditions());

        if (!$result->isValid()) {
            return SubmissionOutcome::invalid(__('Validation failed.', 'jotform-bridge'), $result->errors());
        }

        /**
         * Filters the sanitized values just before they are mapped.
         *
         * @param array<string, string|array<int, string>> $values Sanitized values.
         * @param string                                   $slug   Integration slug.
         */
        $values = apply_filters('jotform_bridge_submission_fields', $result->values(), $slug);

        if (!is_array($values)) {
            $values = $result->values();
        }

        $window      = $this->duplicateWindow();
        $fingerprint = $window > 0 ? $this->fingerprint($slug, $values, $ip) : '';

        if ($fingerprint !== '' && get_transient($fingerprint) !== false) {
            return SubmissionOutcome::error(
                429,
                __('This form has already been submitted. Please wait a moment before sending it again.', 'jotform-bridge')
            );
        }

        $rejection = $this->spam->check($slug, $values, $context);

        if ($rejection !== '') {
            return SubmissionOutcome::error(403, $rejection);
        }

        $params = $this->mapper->map($schema, $values);

        if ($params === []) {
            return SubmissionOutcome::invalid(
                __('Validation failed.', 'jotform-bridge'),
                [ValidationResult::FORM_KEY => __('No form data was submitted.', 'jotform-bridge')]
            );
        }

        /**
         * Fires before a validated submission is sent to Jotform.
         *
         * @param string                                   $slug   Integration slug.
         * @param array<string, string|array<int, string>> $values Sanitized values.
         */
        do_action('jotform_bridge_before_submit', $slug, $values);

        $sent = $this->client->createSubmission($integration->formId(), $params);

        $this->quota->noteLimitLeft($sent->limitLeft());

        if (!$sent->isSuccess()) {
            // The upstream message can contain internal detail, so it is logged
            // and a generic message is returned instead.
            $this->log('Jotform rejected a submission.', [
                'integration' => $slug,
                'error'       => $sent->errorCode(),
                'status'      => $sent->status(),
            ]);

            // Two of these refusals are about the account rather than about
            // this submission, and neither clears within a request's lifetime:
            // the daily call allowance lasts until midnight, the monthly one
            // until the billing cycle rolls over. Sending the next visitor at
            // the same wall only wastes their time, so the breaker trips on the
            // upstream's own word and the site owner gets told.
            $upstreamTrip = $this->upstreamTripReason($sent->errorCode());

            if ($upstreamTrip !== '') {
                $this->quota->tripFromUpstream($upstreamTrip);

                return $this->unavailable();
            }

            return SubmissionOutcome::error(502, $this->upstreamMessage());
        }

        // Only an accepted submission is remembered, so an upstream failure can
        // be retried straight away.
        if ($fingerprint !== '') {
            set_transient($fingerprint, 1, $window);
        }

        // Likewise for the allowance: only what Jotform accepted was spent.
        $this->quota->record();

        /**
         * Fires after a submission was accepted by Jotform.
         *
         * @param string                                   $slug          Integration slug.
         * @param array<string, string|array<int, string>> $values        Sanitized values.
         * @param string                                   $submissionId  Jotform submission ID.
         */
        do_action(
            'jotform_bridge_after_submit',
            $slug,
            $values,
            (string) ($sent->data()['submission_id'] ?? '')
        );

        return SubmissionOutcome::success(
            __('Form submitted successfully.', 'jotform-bridge'),
            $this->redirect($integration)
        );
    }

    /**
     * Maps an upstream error code onto a breaker reason, or '' to leave the
     * breaker alone.
     */
    private function upstreamTripReason(string $errorCode): string
    {
        if ($errorCode === JotformClient::ERROR_FORM_QUOTA) {
            return QuotaGuard::REASON_UPSTREAM_QUOTA;
        }

        if ($errorCode === JotformClient::ERROR_API_LIMIT) {
            return QuotaGuard::REASON_UPSTREAM_API_LIMIT;
        }

        return '';
    }

    /**
     * The answer to an address that is sending too much.
     */
    private function throttled(string $slug, int $wait): SubmissionOutcome
    {
        $this->note('Submission refused by the rate limit.', [
            'integration' => $slug,
            'retry_after' => $wait,
        ]);

        return SubmissionOutcome::error(
            429,
            __('Too many submissions from this device. Please wait a moment and try again.', 'jotform-bridge'),
            ['Retry-After' => (string) $wait]
        );
    }

    /**
     * The redirect for an accepted submission, resolved at answer time.
     *
     * A broken target is a configuration problem, not a submission problem: the
     * submission has already been accepted by Jotform, so the answer degrades to
     * the plain success message and the reason goes to the debug log.
     *
     * @return array{url:string, delay:int}|null
     */
    private function redirect(Integration $integration): ?array
    {
        $target = $this->redirects->check($integration);

        if (RedirectTarget::isBroken($target)) {
            $this->note('The configured redirect target could not be used.', [
                'integration' => $integration->slug(),
                'state'       => $target['state'],
                'page_id'     => $integration->redirectPageId(),
            ]);

            return null;
        }

        return $target['state'] === RedirectTarget::STATE_OK
            ? ['url' => $target['url'], 'delay' => $target['delay']]
            : null;
    }

    /**
     * @return int Seconds; 0 disables the duplicate guard entirely.
     */
    private function duplicateWindow(): int
    {
        /**
         * Filters how long an identical submission is refused after one was
         * accepted. Return 0 to turn the guard off.
         *
         * @param int $seconds Duplicate window.
         */
        $window = (int) apply_filters('jotform_bridge_duplicate_window', self::DUPLICATE_WINDOW);

        return max(0, $window);
    }

    /**
     * Identifies "the same submission again": same integration, same visitor,
     * same values. Only a hash is stored — never the values themselves.
     *
     * @param array<string, string|array<int, string>> $values
     */
    private function fingerprint(string $slug, array $values, string $ip): string
    {
        return self::DUPLICATE_TRANSIENT_PREFIX
            . md5($slug . '|' . $ip . '|' . (string) wp_json_encode($values));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function ip(array $context): string
    {
        return isset($context['ip']) && is_scalar($context['ip']) ? (string) $context['ip'] : '';
    }

    /**
     * The single answer to every reason a form cannot take a submission that is
     * not the visitor's doing.
     *
     * Deliberately one answer rather than several. An unknown slug used to be a
     * 404 and an unsynced one a 503, which meant the endpoint would confirm, to
     * anybody who asked, exactly which slugs are real and what state each is in
     * — a map of the site's forms, free, from the outside.
     *
     * What cannot be hidden is that a working form is a working form: a valid
     * submission has to be answered differently from an invalid one, so a
     * request with plausible values still tells a prober it found something.
     * Closing that would mean closing the form. What this does close is the much
     * cheaper question — "does this name exist at all" — which needs no valid
     * values and no knowledge of the schema.
     *
     * The real reason is not lost: it goes to the debug log, and an
     * administrator viewing the page gets it spelled out by the renderer.
     */
    private function unavailable(): SubmissionOutcome
    {
        return SubmissionOutcome::error(503, $this->upstreamMessage());
    }

    private function upstreamMessage(): string
    {
        return __('The form could not be submitted right now. Please try again later.', 'jotform-bridge');
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function log(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, $context);
        }
    }

    /**
     * Notes something the site owner should know about, but which did not stop
     * the submission.
     *
     * @param array<string, scalar|null> $context
     */
    private function note(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->debug($message, $context);
        }
    }
}
