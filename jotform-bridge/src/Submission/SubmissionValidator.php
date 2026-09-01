<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Forms\FieldNormalizer;
use JotformBridge\Forms\FormSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates and sanitizes one incoming submission against the Normalized Schema.
 *
 * The schema is the only authority here: what a field is, whether it is
 * required and which values it accepts all come from the cached Jotform
 * definition, never from the request. A field the schema does not know is
 * rejected rather than dropped silently, so a template/schema mismatch surfaces
 * instead of quietly losing data.
 */
final class SubmissionValidator
{
    /**
     * Upper bound on how many semantic paths one request may carry.
     *
     * A hundred is several times the largest form anyone builds by hand and
     * still small enough that the loop below cannot be turned into work. It is
     * checked before the schema is consulted, so a request naming a thousand
     * fields costs one count() rather than a thousand lookups.
     */
    public const MAX_FIELDS = 100;

    /**
     * Upper bound on the total size of the submitted values, in bytes.
     *
     * Bytes, because this is a budget for what crossed the wire rather than a
     * limit quoted to anybody. Sixty-four kilobytes is six times the longest
     * single textarea this class will accept, which leaves room for a form of
     * several long answers and none for a payload built to be expensive to
     * walk. SubmissionController's MAX_BODY_BYTES is the coarse outer rail at
     * four times this; this is the one that bounds the values themselves.
     */
    public const MAX_PAYLOAD_BYTES = 65536;

    /**
     * Upper bound on how many values one multi-value field may carry.
     *
     * Matched to MAX_FIELDS rather than reasoned about separately: a checkbox
     * question with more than a hundred options does not exist, and a request
     * claiming one is doing something other than answering a form.
     */
    public const MAX_VALUES = 100;

    /**
     * Longest field identifier that may appear in an error response.
     *
     * The frontend addresses error slots by this identifier, so it is echoed
     * back — including for a field the schema does not know. Anything that is
     * not a plausible identifier is reported without being repeated.
     */
    public const MAX_PATH_LENGTH = 128;

    /**
     * Longest value each kind of field may carry, in characters.
     *
     * Characters, not bytes, and the distinction is not academic: these numbers
     * are quoted back to the visitor in a message that says "characters", and
     * every one of them was being measured with strlen(). On a site writing
     * anything but ASCII that made the real limit a fraction of the stated one
     * — half of it in Cyrillic or Greek, a third in most of CJK — and the
     * message went on naming the number the field would not accept. A form that
     * refuses a valid answer and misstates why is the failure this whole
     * validator exists to avoid, and it would have been invisible to anyone
     * testing in English.
     *
     * mb_strlen() is safe to call unconditionally: WordPress polyfills it in
     * wp-includes/compat.php on the installs that have no mbstring extension.
     *
     * The byte-denominated bounds elsewhere in this class are deliberately not
     * these. MAX_PAYLOAD_BYTES is a budget for what crosses the wire, and
     * MAX_PATH_LENGTH bounds a semantic key, which cannot contain a multibyte
     * character in the first place.
     */
    public const MAX_TEXT_LENGTH     = 1000;
    public const MAX_TEXTAREA_LENGTH = 10000;
    public const MAX_PHONE_LENGTH    = 64;

    /**
     * Longest email address, in octets rather than characters.
     *
     * The odd one out on purpose: 254 is the RFC 5321 bound on a forward path,
     * which is counted in octets, and is_email() refuses a non-ASCII address
     * before this is reached anyway. Measuring it in characters would be
     * inventing a limit rather than applying the standard's.
     */
    public const MAX_EMAIL_LENGTH = 254;

    /**
     * @param array<string, mixed> $input Raw `fields` map from the request.
     */
    public function validate(FormSchema $schema, array $input): ValidationResult
    {
        $errors = [];
        $values = [];

        $sizeError = $this->checkSize($input);

        if ($sizeError !== '') {
            return new ValidationResult([ValidationResult::FORM_KEY => $sizeError], []);
        }

        $allowed = $this->allowedPaths($schema);

        foreach ($input as $rawPath => $value) {
            $path = (string) $rawPath;

            if (!isset($allowed[$path])) {
                // An unknown identifier is a template/schema mismatch worth
                // reporting per field — but only when it looks like one. A key
                // that does not is reported once, at form level, so nothing
                // arbitrary is echoed back into the response.
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

        foreach ($schema->requiredPaths() as $path) {
            if (isset($values[$path]) || isset($errors[$path])) {
                continue;
            }

            $errors[$path] = __('This field is required.', 'jotform-bridge');
        }

        return new ValidationResult($errors, $values);
    }

    /**
     * The shape a semantic identifier can have: what SemanticKey produces, plus
     * the dot that joins a composite child to its parent.
     */
    private static function isPlausiblePath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_PATH_LENGTH
            && preg_match('/^[A-Za-z0-9_.-]+$/', $path) === 1;
    }

    /**
     * Request sanity, checked before anything is looked at field by field.
     *
     * Nested values are measured rather than skipped: a payload built out of
     * arrays of arrays must not be able to pass the size check just because no
     * single leaf is a string.
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
     * Size of one submitted value, counting every leaf of a nested structure.
     *
     * @param mixed $value
     */
    private static function measure($value, int $depth = 0): int
    {
        if (is_scalar($value)) {
            return strlen((string) $value);
        }

        if (!is_array($value) || $depth > 4) {
            // Anything deeper is rejected by value() anyway; charging it the
            // full budget stops a deep structure from being measured for free.
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
     * Every semantic path a submission may address, with the field it belongs
     * to. Composite fields are addressable only through their children, exactly
     * like in templates.
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

        // Only a multi-value field may carry a list. A single value for such a
        // field is accepted and normalized, because that is a harmless client
        // difference; the other direction would be a type confusion.
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

        // A composite child is always a plain text input; the parent type only
        // decides how it is mapped, not how it is validated.
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
     * Choice fields are checked against the options Jotform reports.
     *
     * When the field allows a free-text "other" answer, an unlisted value is
     * accepted as text — rejecting it would break a legitimate form.
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
