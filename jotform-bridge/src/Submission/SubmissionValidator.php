<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Forms\FieldNormalizer;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Integrations\ConditionalLogic;
use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates and sanitizes one submission against the Normalized Schema, which
 * is the only authority on types, requirements and options. Unknown fields
 * are rejected, not dropped.
 */
final class SubmissionValidator
{
    /** Most semantic paths one request may carry. */
    public const MAX_FIELDS = 100;

    /** Total size of the submitted values, in bytes. */
    public const MAX_PAYLOAD_BYTES = 65536;

    /** Most values one multi-value field may carry. */
    public const MAX_VALUES = 100;

    /** Longest field identifier echoed back in an error response. */
    public const MAX_PATH_LENGTH = 128;

    /** Value limits in characters (mb_strlen), as quoted to the visitor. */
    public const MAX_TEXT_LENGTH     = 1000;
    public const MAX_TEXTAREA_LENGTH = 10000;
    public const MAX_PHONE_LENGTH    = 64;

    /** RFC 5321 bound, in octets. */
    public const MAX_EMAIL_LENGTH = 254;

    /**
     * @param array<string, mixed> $input Raw `fields` map from the request.
     * @param array<int, array<string, string>> $conditions Local integration rules.
     */
    public function validate(FormSchema $schema, array $input, array $conditions = []): ValidationResult
    {
        $errors = [];
        $values = [];

        $sizeError = $this->checkSize($input);

        if ($sizeError !== '') {
            return new ValidationResult([ValidationResult::FORM_KEY => $sizeError], []);
        }

        if (ConditionalLogic::errors($conditions, $schema) !== []) {
            return new ValidationResult([ValidationResult::FORM_KEY => __(
                'This form is temporarily unavailable.',
                'jotform-bridge'
            )], []);
        }

        $allowed = $this->allowedPaths($schema);

        foreach ($input as $rawPath => $value) {
            $path = (string) $rawPath;

            if (!isset($allowed[$path])) {
                // Only a plausible identifier is echoed back per field.
                if (self::isPlausiblePath($path)) {
                    $errors[$path] = __('This field does not exist on the form.', 'jotform-bridge');
                } else {
                    $errors[ValidationResult::FORM_KEY] = __(
                        'The submission contains a field this form does not have.',
                        'jotform-bridge'
                    );
                }

                continue;
            }

            $clean = $this->value($allowed[$path], $value, $errors, $path);

            if ($clean !== null) {
                $values[$path] = $clean;
            }
        }

        $state = ConditionalLogic::state($conditions, $values);
        foreach ($state as $path => $actions) {
            if (isset($actions['show']) && !$actions['show']) {
                unset($values[$path], $errors[$path]);
            }
        }
        $required = array_fill_keys($schema->requiredPaths(), true);
        foreach ($state as $path => $actions) {
            if (isset($actions['require'])) {
                $required[$path] = $actions['require'];
            }
            if (isset($actions['show']) && !$actions['show']) {
                $required[$path] = false;
            }
        }
        foreach ($required as $path => $isRequired) {
            if (!$isRequired) {
                continue;
            }
            if (isset($values[$path]) || isset($errors[$path])) {
                continue;
            }

            $errors[$path] = __('This field is required.', 'jotform-bridge');
        }

        return new ValidationResult($errors, $values);
    }

    private static function isPlausiblePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_PATH_LENGTH
            && preg_match('/^[A-Za-z0-9_.-]+$/', $path) === 1;
    }

    /**
     * Size limits, checked before any field is looked at.
     *
     * @param array<string, mixed> $input
     */
    private function checkSize(array $input): string
    {
        if (count($input) > self::MAX_FIELDS) {
            return __('The submission contains too many fields.', 'jotform-bridge');
        }

        $bytes = 0;

        foreach ($input as $path => $value) {
            $bytes += strlen((string) $path);
            $bytes += self::measure($value);

            if ($bytes > self::MAX_PAYLOAD_BYTES) {
                return __('The submission is too large.', 'jotform-bridge');
            }
        }

        return '';
    }

    /**
     * Size of one value, counting every leaf of a nested structure.
     *
     * @param mixed $value
     */
    private static function measure($value, int $depth = 0): int
    {
        if (is_scalar($value)) {
            return strlen((string) $value);
        }

        if (!is_array($value) || $depth > 4) {
            return self::MAX_PAYLOAD_BYTES + 1;
        }

        $bytes = 0;

        foreach ($value as $item) {
            $bytes += self::measure($item, $depth + 1);

            if ($bytes > self::MAX_PAYLOAD_BYTES) {
                return $bytes;
            }
        }

        return $bytes;
    }

    /**
     * Every semantic path a submission may address; composites only through
     * their children.
     *
     * @return array<string, array{field: array<string, mixed>, child: array<string, mixed>|null}>
     */
    private function allowedPaths(FormSchema $schema): array
    {
        $allowed = [];

        foreach ($schema->supportedFields() as $field) {
            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $allowed[(string) $child['key']] = [
                        'field' => $field,
                        'child' => $child,
                    ];
                }

                continue;
            }

            $allowed[(string) $field['key']] = [
                'field' => $field,
                'child' => null,
            ];
        }

        return $allowed;
    }

    /**
     * @param array{field: array<string, mixed>, child: array<string, mixed>|null} $target
     * @param mixed                                                                $value
     * @param array<string, string>                                                $errors
     *
     * @return string|array<int, string>|null Null when nothing usable was sent.
     */
    private function value(array $target, $value, array &$errors, string $path)
    {
        $field = $target['field'];
        $type  = (string) $field['type'];

        // Only a multi-value field may carry a list; a single value for one is normalized.
        $multiple = $target['child'] === null && (bool) $field['multiple'];

        if (is_array($value) && !$multiple) {
            $errors[$path] = __('This field accepts a single value.', 'jotform-bridge');

            return null;
        }

        if ($multiple) {
            return $this->multiValue($field, is_array($value) ? $value : [$value], $errors, $path);
        }

        if (!is_scalar($value)) {
            $errors[$path] = __('This value is not valid.', 'jotform-bridge');

            return null;
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return null;
        }

        // A composite child is always plain text.
        if ($target['child'] !== null) {
            return $this->text($raw, self::MAX_TEXT_LENGTH, $errors, $path);
        }

        switch ($type) {
            case FieldNormalizer::TYPE_EMAIL:
                return $this->email($raw, $errors, $path);

            case FieldNormalizer::TYPE_NUMBER:
                return $this->number($raw, $field, $errors, $path);

            case FieldNormalizer::TYPE_TEXTAREA:
                return $this->textarea($raw, $errors, $path);

            case FieldNormalizer::TYPE_PHONE:
                return $this->phone($raw, $errors, $path);

            case FieldNormalizer::TYPE_SELECT:
            case FieldNormalizer::TYPE_RADIO:
                return $this->option($raw, $field, $errors, $path);
        }

        return $this->text($raw, self::MAX_TEXT_LENGTH, $errors, $path);
    }

    /**
     * @param array<string, mixed>  $field
     * @param array<mixed>          $values
     * @param array<string, string> $errors
     *
     * @return array<int, string>|null
     */
    private function multiValue(array $field, array $values, array &$errors, string $path): ?array
    {
        if (count($values) > self::MAX_VALUES) {
            $errors[$path] = __('Too many values were selected.', 'jotform-bridge');

            return null;
        }

        $clean = [];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                $errors[$path] = __('This value is not valid.', 'jotform-bridge');

                return null;
            }

            $raw = trim((string) $value);

            if ($raw === '') {
                continue;
            }

            $option = $this->option($raw, $field, $errors, $path);

            if ($option === null) {
                return null;
            }

            if (!in_array($option, $clean, true)) {
                $clean[] = $option;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param array<string, string> $errors
     */
    private function text(string $raw, int $limit, array &$errors, string $path): ?string
    {
        $clean = sanitize_text_field($raw);

        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) > $limit) {
            $errors[$path] = sprintf(
                /* translators: %d: maximum number of characters */
                __('This value is longer than %d characters.', 'jotform-bridge'),
                $limit
            );

            return null;
        }

        return $clean;
    }

    /**
     * @param array<string, string> $errors
     */
    private function textarea(string $raw, array &$errors, string $path): ?string
    {
        $clean = sanitize_textarea_field($raw);

        if ($clean === '') {
            return null;
        }

        if (mb_strlen($clean) > self::MAX_TEXTAREA_LENGTH) {
            $errors[$path] = sprintf(
                /* translators: %d: maximum number of characters */
                __('This value is longer than %d characters.', 'jotform-bridge'),
                self::MAX_TEXTAREA_LENGTH
            );

            return null;
        }

        return $clean;
    }

    /**
     * @param array<string, string> $errors
     */
    private function email(string $raw, array &$errors, string $path): ?string
    {
        $clean = sanitize_email($raw);

        if ($clean === '' || !is_email($clean) || strlen($clean) > self::MAX_EMAIL_LENGTH) {
            $errors[$path] = __('Enter a valid email address.', 'jotform-bridge');

            return null;
        }

        $settings = new Settings();
        $domain = strtolower(substr($clean, (int) strrpos($clean, '@') + 1));
        if ($settings->popularEmailDomainsOnly() && !in_array($domain, $settings->allowedEmailDomains(), true)) {
            $errors[$path] = __('Use an email address from an allowed email domain.', 'jotform-bridge');

            return null;
        }

        return $clean;
    }

    /**
     * @param array<string, mixed>  $field
     * @param array<string, string> $errors
     */
    private function number(string $raw, array $field, array &$errors, string $path): ?string
    {
        if (!is_numeric($raw)) {
            $errors[$path] = __('Enter a number.', 'jotform-bridge');

            return null;
        }

        $number = (float) $raw;
        $min    = isset($field['meta']['min']) ? (string) $field['meta']['min'] : '';
        $max    = isset($field['meta']['max']) ? (string) $field['meta']['max'] : '';

        if ($min !== '' && is_numeric($min) && $number < (float) $min) {
            $errors[$path] = sprintf(
                /* translators: %s: smallest accepted number */
                __('Enter a number of %s or more.', 'jotform-bridge'),
                $min
            );

            return null;
        }

        if ($max !== '' && is_numeric($max) && $number > (float) $max) {
            $errors[$path] = sprintf(
                /* translators: %s: largest accepted number */
                __('Enter a number of %s or less.', 'jotform-bridge'),
                $max
            );

            return null;
        }

        return $raw;
    }

    /**
     * @param array<string, string> $errors
     */
    private function phone(string $raw, array &$errors, string $path): ?string
    {
        $clean = sanitize_text_field($raw);
        $digits = preg_replace('/\D+/', '', $clean) ?? '';

        if (mb_strlen($clean) > self::MAX_PHONE_LENGTH || strlen($digits) < 5) {
            $errors[$path] = __('Enter a valid phone number.', 'jotform-bridge');

            return null;
        }

        return $clean;
    }

    /**
     * Checks a choice against the schema options; an "other" field accepts
     * unlisted text.
     *
     * @param array<string, mixed>  $field
     * @param array<string, string> $errors
     */
    private function option(string $raw, array $field, array &$errors, string $path): ?string
    {
        $options = [];

        foreach ($field['options'] as $option) {
            $options[] = (string) $option['value'];
        }

        if (in_array($raw, $options, true)) {
            return $raw;
        }

        if ((bool) $field['allow_other'] || $options === []) {
            return $this->text($raw, self::MAX_TEXT_LENGTH, $errors, $path);
        }

        $errors[$path] = __('Select one of the available options.', 'jotform-bridge');

        return null;
    }
}
