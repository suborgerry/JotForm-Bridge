<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FieldNormalizer;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sends one real submission from the admin screen. Not a dry run: it lands in
 * the account and spends allowance. Bypasses the anti-abuse layer and
 * exercises stored schema, validation, mapping, transport and credentials.
 */
final class TestSubmission
{
    /** Put into every text value, so the submission is recognisable in the inbox. */
    public const MARKER = 'Jotform Bridge test';

    /** RFC 2606 reserved domain. */
    public const EMAIL = 'jotform-bridge-test@example.com';

    private SchemaRepository $schemas;

    private JotformClient $client;

    private SubmissionValidator $validator;

    private SubmissionMapper $mapper;

    private ?QuotaGuard $quota;

    public function __construct(SchemaRepository $schemas, JotformClient $client, ?QuotaGuard $quota = null)
    {
        $this->schemas   = $schemas;
        $this->client    = $client;
        $this->validator = new SubmissionValidator();
        $this->mapper    = new SubmissionMapper();
        $this->quota     = $quota;
    }

    /**
     * @return ApiResponse Data is `['submission_id' => string, 'values' => array]`.
     */
    public function send(Integration $integration): ApiResponse
    {
        $schema = $this->schemas->stored($integration->formId());

        if ($schema === null) {
            return ApiResponse::failure(
                SchemaRepository::ERROR_NOT_SYNCED,
                __('This form has not been synced yet. Press Sync Schema first.', 'jotform-bridge')
            );
        }

        if (!$schema->isUsable()) {
            return ApiResponse::failure(
                SchemaRepository::ERROR_NOT_SYNCED,
                __('The stored schema has unresolved errors. Re-sync it and try again.', 'jotform-bridge')
            );
        }

        $values = $this->sampleValues($schema);

        if ($values === []) {
            return ApiResponse::failure(
                JotformClient::ERROR_UNEXPECTED,
                __('This form has no fields the plugin can fill in automatically.', 'jotform-bridge')
            );
        }

        $result = $this->validator->validate($schema, $values, $integration->conditions());

        if (!$result->isValid()) {
            return ApiResponse::failure(
                JotformClient::ERROR_UNEXPECTED,
                sprintf(
                    /* translators: %s: comma separated field identifiers */
                    __('The generated test values did not pass validation (%s). The stored schema may be out of date.', 'jotform-bridge'),
                    implode(', ', array_keys($result->errors()))
                )
            );
        }

        $params = $this->mapper->map($schema, $result->values());

        if ($params === []) {
            return ApiResponse::failure(
                JotformClient::ERROR_UNEXPECTED,
                __('Nothing could be mapped onto Jotform question IDs. Re-sync the schema.', 'jotform-bridge')
            );
        }

        $sent = $this->client->createSubmission($integration->formId(), $params);

        if ($this->quota !== null) {
            $this->quota->noteLimitLeft($sent->limitLeft());
        }

        if (!$sent->isSuccess()) {
            // The upstream detail is what the administrator pressed the button to read.
            return $sent;
        }

        if ($this->quota !== null) {
            $this->quota->record();
        }

        return ApiResponse::success(
            [
                'submission_id' => (string) ($sent->data()['submission_id'] ?? ''),
                'values'        => $result->values(),
            ],
            $sent->status(),
            $sent->meta()
        );
    }

    /**
     * Plausible values for every supported field: a reported option for choices,
     * a number inside the declared range.
     *
     * @return array<string, string|array<int, string>>
     */
    private function sampleValues(FormSchema $schema): array
    {
        $values = [];

        foreach ($schema->supportedFields() as $field) {
            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $values[(string) $child['key']] = self::MARKER;
                }

                continue;
            }

            $key = (string) $field['key'];

            if ((bool) $field['multiple']) {
                $option = $this->firstOption($field);

                if ($option !== null) {
                    $values[$key] = [$option];
                }

                continue;
            }

            $value = $this->scalarValue($field);

            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function scalarValue(array $field): ?string
    {
        switch ((string) $field['type']) {
            case FieldNormalizer::TYPE_EMAIL:
                return self::EMAIL;

            case FieldNormalizer::TYPE_PHONE:
                return '+1 555 0100';

            case FieldNormalizer::TYPE_NUMBER:
                return $this->numberInRange($field);

            case FieldNormalizer::TYPE_SELECT:
            case FieldNormalizer::TYPE_RADIO:
            case FieldNormalizer::TYPE_CHECKBOX:
                $option = $this->firstOption($field);

                return $option ?? ((bool) $field['allow_other'] ? self::MARKER : null);
        }

        return self::MARKER;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function numberInRange(array $field): string
    {
        $min = isset($field['meta']['min']) && is_numeric($field['meta']['min'])
            ? (float) $field['meta']['min']
            : null;
        $max = isset($field['meta']['max']) && is_numeric($field['meta']['max'])
            ? (float) $field['meta']['max']
            : null;

        $value = $min ?? 1.0;

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return (string) (float) $value === (string) (int) $value
            ? (string) (int) $value
            : (string) $value;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function firstOption(array $field): ?string
    {
        foreach ($field['options'] as $option) {
            $value = (string) $option['value'];

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
