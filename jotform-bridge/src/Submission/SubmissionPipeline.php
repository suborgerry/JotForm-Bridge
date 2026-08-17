<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\IntegrationRepository;
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
 * lookup, activity, schema, validation, spam check, mapping, upstream call.
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

    public function __construct(
        IntegrationRepository $integrations,
        SchemaRepository $schemas,
        JotformClient $client,
        ?SubmissionValidator $validator = null,
        ?SubmissionMapper $mapper = null,
        ?SpamGuard $spam = null,
        ?Logger $logger = null
    ) {
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
        $slug        = sanitize_key($slug);
        $integration = $slug === '' ? null : $this->integrations->get($slug);

        if ($integration === null) {
            return SubmissionOutcome::error(404, __('This form is not available.', 'jotform-bridge'));
        }

        if (!$integration->isActive()) {
            return SubmissionOutcome::error(403, __('This form is not accepting submissions.', 'jotform-bridge'));
        }

        if (!is_array($fields)) {
            return SubmissionOutcome::invalid(
                __('Validation failed.', 'jotform-bridge'),
                [ValidationResult::FORM_KEY => __('No form data was submitted.', 'jotform-bridge')]
            );
        }

        $response = $this->schemas->get($integration->formId());

        if (!$response->isSuccess()) {
            $this->log('Submission blocked: the form schema could not be loaded.', [
                'integration' => $slug,
                'error'       => $response->errorCode(),
            ]);

            return SubmissionOutcome::error(503, $this->upstreamMessage());
        }

        $schema = $response->data()['schema'];

        if (!$schema->isUsable()) {
            $this->log('Submission blocked: the form schema has unresolved errors.', [
                'integration' => $slug,
            ]);

            return SubmissionOutcome::error(503, $this->upstreamMessage());
        }

        $result = $this->validator->validate($schema, $fields);

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
        $fingerprint = $window > 0 ? $this->fingerprint($slug, $values, $context) : '';

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

        if (!$sent->isSuccess()) {
            // The upstream message can contain internal detail, so it is logged
            // and a generic message is returned instead.
            $this->log('Jotform rejected a submission.', [
                'integration' => $slug,
                'error'       => $sent->errorCode(),
                'status'      => $sent->status(),
            ]);

            return SubmissionOutcome::error(502, $this->upstreamMessage());
        }

        // Only an accepted submission is remembered, so an upstream failure can
        // be retried straight away.
        if ($fingerprint !== '') {
            set_transient($fingerprint, 1, $window);
        }

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

        return SubmissionOutcome::success(__('Form submitted successfully.', 'jotform-bridge'));
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
    private function fingerprint(string $slug, array $values, array $context): string
    {
        $ip = isset($context['ip']) && is_scalar($context['ip']) ? (string) $context['ip'] : '';

        return self::DUPLICATE_TRANSIENT_PREFIX . md5($slug . '|' . $ip . '|' . (string) wp_json_encode($values));
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
}
