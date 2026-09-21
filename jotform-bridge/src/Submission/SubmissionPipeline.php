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
 * The server side of a submission, from integration slug to Jotform.
 *
 * Order: site-wide rate limit, lookup, per-integration rate limit, quota
 * guard, schema, validation, duplicate check, spam check, mapping, upstream
 * call. Every refusal that is not the visitor's doing answers with the same
 * 503, so the endpoint does not reveal which slugs exist; the reason goes to
 * the debug log.
 */
final class SubmissionPipeline
{
    /** Seconds an identical, already accepted submission is refused. */
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

        // Site-wide limit first, before any lookup.
        $wait = $this->limiter->checkGlobal($ip);

        if ($wait > 0) {
            return $this->throttled($slug, $wait);
        }

        $integration = $slug === '' ? null : $this->integrations->get($slug);

        if ($integration === null) {
            $this->note('A submission named an integration that does not exist.', [
                'integration' => $slug,
            ]);

            return $this->unavailable();
        }

        $wait = $this->limiter->check($slug, $ip);

        if ($wait > 0) {
            return $this->throttled($slug, $wait);
        }

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

        // Stored schema only; never a network call.
        $response = $this->schemas->get($integration->formId());

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
            // The upstream message is logged, never returned.
            $this->log('Jotform rejected a submission.', [
                'integration' => $slug,
                'error'       => $sent->errorCode(),
                'status'      => $sent->status(),
            ]);

            // An allowance refusal trips the breaker for everyone.
            $upstreamTrip = $this->upstreamTripReason($sent->errorCode());

            if ($upstreamTrip !== '') {
                $this->quota->tripFromUpstream($upstreamTrip);

                return $this->unavailable();
            }

            return SubmissionOutcome::error(502, $this->upstreamMessage());
        }

        // Only an accepted submission is fingerprinted and counted.
        if ($fingerprint !== '') {
            set_transient($fingerprint, 1, $window);
        }

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

    /** Breaker reason for an upstream error code, or '' for none. */
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
     * The redirect for an accepted submission, resolved at answer time; a
     * broken target degrades to the plain success message.
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
     * Hash of integration, visitor and values; the values are never stored.
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

    /** The single answer to every refusal that is not the visitor's doing. */
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
     * @param array<string, scalar|null> $context
     */
    private function note(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->debug($message, $context);
        }
    }
}
