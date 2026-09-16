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
 * Sends one real submission from the admin screen, on purpose.
 *
 * Everything else in this plugin is verified without touching the account: the
 * schema is a stored fixture, the transport is mocked, the mapping is checked
 * against the documented parameter shape. All of that can be right while the
 * form still does not work — a form ID that points somewhere else, a key
 * without write access, a field Jotform has since made required. The only way
 * to know is to send something and read the answer.
 *
 * So this is deliberately not a dry run. The submission lands in the account,
 * triggers whatever notifications the form has configured, and spends one of
 * the month's allowance. A simulation would prove only what the unit tests
 * already prove.
 *
 * It goes around the anti-abuse layer rather than through the pipeline, because
 * the pipeline would refuse it and be right to: there is no browser here to
 * compute a proof of work, and an administrator pressing a button is not a
 * visitor to be rate limited. What is exercised is the part that can actually
 * be wrong end to end — stored schema, validation, mapping, wire format,
 * credentials, and Jotform's own verdict.
 */
final class TestSubmission
{
    /**
     * Put into every text value, so the submission is recognisable in the
     * Jotform inbox without having to remember when the button was pressed.
     */
    public const MARKER = 'Jotform Bridge test';

    /**
     * example.com is reserved by RFC 2606 and cannot reach anybody.
     */
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

        // Run the real validator over the generated values. If it refuses them,
        // the fault is here or in the stored schema, and saying so is far more
        // useful than sending something and blaming Jotform for the answer.
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
            // Handed back untouched. A visitor gets a generic message because
            // upstream detail is not theirs to see; an administrator pressed
            // this button precisely to read that detail.
            return $sent;
        }

        // It really did spend one of the month's allowance, so the guard is
        // told — pretending otherwise would make its arithmetic quietly wrong.
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
     * Plausible values for every field the plugin can fill in.
     *
     * Choice fields take an option Jotform itself reported, and numbers respect
     * the range it declared, so the values are the ones the form asks for
     * rather than the ones that happen to pass our own validator.
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
                // Reserved for fiction, and long enough for the validator.
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
