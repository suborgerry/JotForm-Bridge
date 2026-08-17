<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Converts one raw Jotform question into one normalized field.
 *
 * This is the only place in the plugin that knows about `control_*` types,
 * pipe-delimited options and Jotform sub-field vocabulary. Everything above it
 * works against the Normalized Schema.
 *
 * Sources for the Jotform specifics used here:
 *  - GET /form/{formID}/questions  — https://www.jotform.com/apidocs-v1/
 *  - per-type property reference   — https://api.jotform.com/docs/properties/
 */
final class FieldNormalizer
{
    // Normalized types. Deliberately frontend-agnostic.
    public const TYPE_TEXT        = 'text';
    public const TYPE_TEXTAREA    = 'textarea';
    public const TYPE_EMAIL       = 'email';
    public const TYPE_PHONE       = 'phone';
    public const TYPE_NUMBER      = 'number';
    public const TYPE_SELECT      = 'select';
    public const TYPE_RADIO       = 'radio';
    public const TYPE_CHECKBOX    = 'checkbox';
    public const TYPE_NAME        = 'name';
    public const TYPE_ADDRESS     = 'address';
    public const TYPE_UNSUPPORTED = 'unsupported';

    /**
     * Jotform control types we can normalize, mapped to our own type.
     *
     * @var array<string, string>
     */
    private const TYPE_MAP = [
        'control_textbox'  => self::TYPE_TEXT,
        'control_textarea' => self::TYPE_TEXTAREA,
        'control_email'    => self::TYPE_EMAIL,
        'control_phone'    => self::TYPE_PHONE,
        'control_number'   => self::TYPE_NUMBER,
        'control_dropdown' => self::TYPE_SELECT,
        'control_radio'    => self::TYPE_RADIO,
        'control_checkbox' => self::TYPE_CHECKBOX,
        'control_fullname' => self::TYPE_NAME,
        'control_address'  => self::TYPE_ADDRESS,
    ];

    /**
     * Layout and system elements: they carry no answer, so they are not fields
     * at all. They are skipped rather than reported as unsupported.
     *
     * @var array<int, string>
     */
    private const NON_INPUT_TYPES = [
        'control_head',
        'control_text',
        'control_button',
        'control_pagebreak',
        'control_divider',
        'control_collapse',
        'control_image',
        'control_captcha',
        'control_clear',
    ];

    /**
     * Full Name children. `first` and `last` always exist; the rest are opt-in
     * per the `prefix` / `middle` / `suffix` properties.
     *
     * Child key => Jotform property that enables it (null = always present).
     *
     * @var array<string, string|null>
     */
    private const NAME_CHILDREN = [
        'prefix' => 'prefix',
        'first'  => null,
        'middle' => 'middle',
        'last'   => null,
        'suffix' => 'suffix',
    ];

    /**
     * Address children. The `subfields` property lists the enabled sub-inputs
     * with its own short tokens; the answer/prefill vocabulary uses the longer
     * names. Both come from Jotform — the answer names are the ones that end up
     * in submission payloads, so they are the semantic child keys.
     *
     * subfields token => semantic child key.
     *
     * @var array<string, string>
     */
    private const ADDRESS_SUBFIELDS = [
        'st1'     => 'addr_line1',
        'st2'     => 'addr_line2',
        'city'    => 'city',
        'state'   => 'state',
        'zip'     => 'postal',
        'country' => 'country',
    ];

    /**
     * Fallback when `subfields` is absent: Jotform's default address layout.
     *
     * @var array<int, string>
     */
    private const ADDRESS_DEFAULT = ['addr_line1', 'addr_line2', 'city', 'state', 'postal'];

    /**
     * Address sub-inputs that stay optional even when the field is required.
     * Jotform does not enforce the second street line.
     *
     * @var array<int, string>
     */
    private const ADDRESS_OPTIONAL = ['addr_line2'];

    /**
     * Normalizes a single question.
     *
     * @param array<string, mixed> $question Raw question as returned by the API.
     *
     * @return array<string, mixed>|null Null for layout/system elements.
     */
    public function normalize(array $question): ?array
    {
        $jotformType = isset($question['type']) ? (string) $question['type'] : '';

        if ($jotformType === '' || in_array($jotformType, self::NON_INPUT_TYPES, true)) {
            return null;
        }

        $qid   = isset($question['qid']) ? (string) $question['qid'] : '';
        $name  = isset($question['name']) ? (string) $question['name'] : '';
        $label = isset($question['text']) ? (string) $question['text'] : '';
        $key   = SemanticKey::fromName($name, $qid);

        $field = [
            'key'          => $key,
            'qid'          => $qid,
            'name'         => $name,
            'label'        => $label,
            'type'         => self::TYPE_MAP[$jotformType] ?? self::TYPE_UNSUPPORTED,
            'jotform_type' => $jotformType,
            'required'     => $this->isRequired($question),
            'order'        => isset($question['order']) ? (int) $question['order'] : 0,
            'supported'    => isset(self::TYPE_MAP[$jotformType]),
            'multiple'     => false,
            'allow_other'  => false,
            'options'      => [],
            'children'     => [],
            'meta'         => [],
            'reason'       => '',
        ];

        if (!$field['supported']) {
            $field['reason'] = sprintf(
                /* translators: %s: raw Jotform field type, e.g. control_datetime */
                __('The Jotform field type "%s" is not supported yet.', 'jotform-bridge'),
                $jotformType
            );

            return $field;
        }

        switch ($field['type']) {
            case self::TYPE_SELECT:
            case self::TYPE_RADIO:
            case self::TYPE_CHECKBOX:
                $field['options']     = $this->options($question);
                $field['multiple']    = $field['type'] === self::TYPE_CHECKBOX;
                $field['allow_other'] = $this->isYes($question['allowOther'] ?? null);
                break;

            case self::TYPE_NAME:
                $field['children'] = $this->nameChildren($question, $key, (bool) $field['required']);
                break;

            case self::TYPE_ADDRESS:
                $field['children'] = $this->addressChildren($question, $key, (bool) $field['required']);
                break;

            case self::TYPE_PHONE:
                // Documented as a single input; country code and input mask are
                // presentation details kept for later validation/mapping.
                $field['meta']['country_code'] = $this->isYes($question['countryCode'] ?? null);
                $field['meta']['input_mask']   = isset($question['inputMaskValue'])
                    && $this->isEnabled($question['inputMask'] ?? null)
                        ? (string) $question['inputMaskValue']
                        : '';
                break;

            case self::TYPE_NUMBER:
                $field['meta']['min'] = isset($question['minValue']) && $question['minValue'] !== ''
                    ? (string) $question['minValue']
                    : '';
                $field['meta']['max'] = isset($question['maxValue']) && $question['maxValue'] !== ''
                    ? (string) $question['maxValue']
                    : '';
                break;
        }

        return $field;
    }

    /**
     * Jotform reports `required` as the string "Yes"/"No".
     *
     * @param array<string, mixed> $question
     */
    private function isRequired(array $question): bool
    {
        return $this->isYes($question['required'] ?? null);
    }

    /**
     * @param mixed $value
     */
    private function isYes($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return is_string($value) && strtolower(trim($value)) === 'yes';
    }

    /**
     * @param mixed $value
     */
    private function isEnabled($value): bool
    {
        return is_string($value) && strtolower(trim($value)) === 'enable';
    }

    /**
     * Options arrive as a single pipe-delimited string.
     *
     * The raw value is what a submission must send back, so it is preserved
     * verbatim; the label is only for rendering.
     *
     * @param array<string, mixed> $question
     *
     * @return array<int, array{value:string, label:string}>
     */
    private function options(array $question): array
    {
        $raw = $question['options'] ?? '';

        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $options = [];
        $seen    = [];

        foreach (explode('|', $raw) as $option) {
            $value = trim($option);

            if ($value === '' || isset($seen[$value])) {
                continue;
            }

            $seen[$value] = true;
            $options[]    = [
                'value' => $value,
                'label' => $value,
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $question
     *
     * @return array<string, array<string, mixed>>
     */
    private function nameChildren(array $question, string $parentKey, bool $required): array
    {
        $sublabels = $this->sublabels($question);
        $children  = [];

        foreach (self::NAME_CHILDREN as $child => $toggle) {
            if ($toggle !== null && !$this->isYes($question[$toggle] ?? null)) {
                continue;
            }

            // Jotform only enforces first and last name on a required field.
            $childRequired = $required && $toggle === null;

            $children[$child] = $this->child($parentKey, $child, $sublabels, $childRequired);
        }

        return $children;
    }

    /**
     * @param array<string, mixed> $question
     *
     * @return array<string, array<string, mixed>>
     */
    private function addressChildren(array $question, string $parentKey, bool $required): array
    {
        $enabled = self::ADDRESS_DEFAULT;
        $raw     = $question['subfields'] ?? '';

        if (is_string($raw) && trim($raw) !== '') {
            $enabled = [];

            foreach (explode('|', $raw) as $token) {
                $token = strtolower(trim($token));

                if (isset(self::ADDRESS_SUBFIELDS[$token])) {
                    $enabled[] = self::ADDRESS_SUBFIELDS[$token];
                }
            }

            if ($enabled === []) {
                $enabled = self::ADDRESS_DEFAULT;
            }
        }

        $sublabels = $this->sublabels($question);
        $children  = [];

        foreach ($enabled as $child) {
            $childRequired = $required && !in_array($child, self::ADDRESS_OPTIONAL, true);

            $children[$child] = $this->child($parentKey, $child, $sublabels, $childRequired);
        }

        return $children;
    }

    /**
     * @param array<string, string> $sublabels
     *
     * @return array<string, mixed>
     */
    private function child(string $parentKey, string $child, array $sublabels, bool $required): array
    {
        return [
            'key'      => SemanticKey::child($parentKey, $child),
            'child'    => $child,
            // The Jotform answer key, kept for submission mapping in stage 4.
            'jotform'  => $child,
            'label'    => $sublabels[$child] ?? '',
            'required' => $required,
        ];
    }

    /**
     * `sublabels` is a JSON object serialized into a string property.
     *
     * @param array<string, mixed> $question
     *
     * @return array<string, string>
     */
    private function sublabels(array $question): array
    {
        $raw = $question['sublabels'] ?? '';

        if (is_array($raw)) {
            $decoded = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
        } else {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $labels = [];

        foreach ($decoded as $child => $label) {
            if (is_string($label)) {
                $labels[(string) $child] = $label;
            }
        }

        return $labels;
    }
}
