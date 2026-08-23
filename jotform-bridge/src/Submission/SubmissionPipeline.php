<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Support\Logger;
use JotformBridge\Support\Stats;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The whole server side of a submission, from integration slug to Jotform.
 *
 * The REST controller owns none of this: it unwraps the HTTP request, calls
 * submit() and serializes the outcome. That keeps the pipeline testable without
 * WordPress and makes the security-relevant order explicit in one place —
 * site-wide rate limit, lookup, activity, per-integration rate limit, quota
 * guard, schema, validation, duplicate check, spam check, mapping, upstream
 * call. Each step is cheaper than the one after it, so the requests worth
 * refusing are refused before the expensive work happens.
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

    private Stats $stats;

    public function __construct(
        IntegrationRepository $integrations,
        SchemaRepository $schemas,
        JotformClient $client,
        ?SubmissionValidator $validator = null,
        ?SubmissionMapper $mapper = null,
        ?SpamGuard $spam = null,
        ?Logger $logger = null,
        ?RedirectTarget $redirects = null,
        ?RateLimiter $limiter = null,
        ?QuotaGuard $quota = null,
        ?Stats $stats = null
    ) {
        $this->redirects = $redirects ?? new RedirectTarget();
        $this->limiter   = $limiter ?? new RateLimiter();
        $this->quota     = $quota ?? new QuotaGuard();
        $this->stats     = $stats ?? new Stats();
        $this->integrations = $integrations;
        $this->schemas      = $schemas;
        $this->client       = $client;
        $this->validator    = $validator ?? new SubmissionValidator();
        $this->mapper       = $mapper ?? new SubmissionMapper();
        $this->spam         = $spam ?? new SpamGuard();
        $this->logger       = $logger;
    }

    /**
     * @param mixed                $fields  Raw `fields` value from the request body.
     * @param array<string, mixed> $context Request metadata for the spam check.
     */
    public function submit(string $slug, $fields, array $context = []): SubmissionOutcome
    {
        $slug = sanitize_key($slug);
        $ip   = $this->ip($context);

        // Before anything is looked up. An unknown slug answers 404 cheaply, so
        // without this the whole rate limit could be sidestepped by probing for
        // slugs instead of naming a real one.
        $wait = $this->limiter->checkGlobal($ip);

        if ($wait > 0) {
            // No integration has been resolved yet, so the slug the request
            // named is not to be trusted as a bucket name.
            return $this->throttled(Stats::GLOBAL_SCOPE, $slug, $wait);
        }

        $integration = $slug === '' ? null : $this->integrations->get($slug);

        if ($integration === null) {
            return $this->count(
                Stats::GLOBAL_SCOPE,
                Stats::UNKNOWN,
                SubmissionOutcome::error(404, __('This form is not available.', 'jotform-bridge'))
            );
        }

        if (!$integration->isActive()) {
            return $this->count(
                $slug,
                Stats::INACTIVE,
                SubmissionOutcome::error(403, __('This form is not accepting submissions.', 'jotform-bridge'))
            );
        }

        // The tighter, per-integration budget. Like the site-wide one it needs
        // neither the schema nor the values, so a flood is turned away before
        // the request costs a schema read or a pass through the validator.
        $wait = $this->limiter->check($slug, $ip);

        if ($wait > 0) {
            return $this->throttled($slug, $slug, $wait);
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

            return $this->count($slug, Stats::QUOTA, SubmissionOutcome::error(503, $this->upstreamMessage()));
        }

        if (!is_array($fields)) {
            return $this->count(
                $slug,
                Stats::EMPTY_BODY,
                SubmissionOutcome::invalid(
                    __('Validation failed.', 'jotform-bridge'),
                    [ValidationResult::FORM_KEY => __('No form data was submitted.', 'jotform-bridge')]
                )
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

            return $this->count($slug, Stats::NO_SCHEMA, SubmissionOutcome::error(503, $this->upstreamMessage()));
        }

        $schema = $response->data()['schema'];

        if (!$schema->isUsable()) {
            $this->log('Submission blocked: the form schema has unresolved errors.', [
                'integration' => $slug,
            ]);

            return $this->count($slug, Stats::NO_SCHEMA, SubmissionOutcome::error(503, $this->upstreamMessage()));
        }

        $result = $this->validator->validate($schema, $fields);

        if (!$result->isValid()) {
            // The field names go into the tally, so a validator that is
            // stricter than the form suggests becomes visible.
            return $this->count(
                $slug,
                Stats::INVALID,
                SubmissionOutcome::invalid(__('Validation failed.', 'jotform-bridge'), $result->errors()),
                array_keys($result->errors())
            );
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
            return $this->count(
                $slug,
                Stats::DUPLICATE,
                SubmissionOutcome::error(
                    429,
                    __('This form has already been submitted. Please wait a moment before sending it again.', 'jotform-bridge')
                )
            );
        }

        $rejection = $this->spam->check($slug, $values, $context);

        if ($rejection !== '') {
            return $this->count($slug, Stats::SPAM, SubmissionOutcome::error(403, $rejection));
        }

        $params = $this->mapper->map($schema, $values);

        if ($params === []) {
            return $this->count(
                $slug,
                Stats::EMPTY_BODY,
                SubmissionOutcome::invalid(
                    __('Validation failed.', 'jotform-bridge'),
                    [ValidationResult::FORM_KEY => __('No form data was submitted.', 'jotform-bridge')]
                )
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

                return $this->count($slug, Stats::QUOTA, SubmissionOutcome::error(503, $this->upstreamMessage()));
            }

            return $this->count($slug, Stats::UPSTREAM, SubmissionOutcome::error(502, $this->upstreamMessage()));
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

        return $this->count(
            $slug,
            Stats::OK,
            SubmissionOutcome::success(
                __('Form submitted successfully.', 'jotform-bridge'),
                $this->redirect($integration)
            )
        );
    }

    /**
     * Records how a submission ended and hands the answer back unchanged.
     *
     * Every exit from submit() goes through here, so the tally cannot drift
     * away from what the pipeline actually does: a new outcome that forgets to
     * be counted is a new `return` that does not compile into this shape.
     *
     * @param array<int, string> $fieldErrors
     */
    private function count(
        string $bucket,
        string $outcome,
        SubmissionOutcome $answer,
        array $fieldErrors = []
    ): SubmissionOutcome {
        $this->stats->record($bucket, $outcome, $fieldErrors);

        return $answer;
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
    private function throttled(string $bucket, string $slug, int $wait): SubmissionOutcome
    {
        $this->note('Submission refused by the rate limit.', [
            'integration' => $slug,
            'retry_after' => $wait,
        ]);

        return $this->count(
            $bucket,
            Stats::THROTTLED,
            SubmissionOutcome::error(
                429,
                __('Too many submissions from this device. Please wait a moment and try again.', 'jotform-bridge'),
                ['Retry-After' => (string) $wait]
            )
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
     * @param array<string, mixed>                     $context
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
