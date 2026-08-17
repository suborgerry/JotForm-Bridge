<?php

declare(strict_types=1);

namespace JotformBridge\Forms;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assembles a FormSchema from the raw question list.
 *
 * FieldNormalizer handles one question at a time; everything that needs to see
 * the whole form — semantic key collisions, diagnostics, the fingerprint —
 * lives here.
 */
final class SchemaBuilder
{
    private FieldNormalizer $normalizer;

    public function __construct(?FieldNormalizer $normalizer = null)
    {
        $this->normalizer = $normalizer ?? new FieldNormalizer();
    }

    /**
     * @param array<int, array<string, mixed>> $questions Raw Jotform questions.
     */
    public function build(string $formId, array $questions): FormSchema
    {
        $fields      = [];
        $diagnostics = [];

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $field = $this->normalizer->normalize($question);

            if ($field === null) {
                continue;
            }

            $field['collision'] = false;

            $this->collectFieldDiagnostics($field, $diagnostics);

            $key = (string) $field['key'];

            if (isset($fields[$key])) {
                // Two fields want the same public identifier. Guessing which one
                // a template means would silently misroute data, so both are
                // flagged and the newcomer falls back to its qid-based key.
                $fields[$key]['collision'] = true;
                $field['collision']        = true;
                $field['key']              = SemanticKey::FALLBACK_PREFIX . $field['qid'];
                $field                     = $this->rekeyChildren($field);

                $diagnostics[] = [
                    'level'   => FormSchema::DIAGNOSTIC_ERROR,
                    'code'    => FormSchema::CODE_COLLISION,
                    'qid'     => (string) $field['qid'],
                    'key'     => $key,
                    'message' => sprintf(
                        /* translators: 1: semantic key, 2: first qid, 3: second qid */
                        __(
                            'The semantic key "%1$s" is produced by more than one Jotform field (qid %2$s and qid %3$s). Rename one of them in Jotform.',
                            'jotform-bridge'
                        ),
                        $key,
                        (string) $fields[$key]['qid'],
                        (string) $field['qid']
                    ),
                ];
            }

            $fields[(string) $field['key']] = $field;
        }

        return new FormSchema($formId, $fields, $diagnostics, self::fingerprint($fields));
    }

    /**
     * Deterministic hash over the structurally significant parts of a schema.
     *
     * Labels, order and hint text are excluded on purpose: they change without
     * breaking a template. Anything that would change how data is collected or
     * mapped is included.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    public static function fingerprint(array $fields): string
    {
        $significant = [];

        foreach ($fields as $key => $field) {
            $children = [];

            foreach ($field['children'] as $childKey => $child) {
                $children[(string) $childKey] = [
                    'jotform'  => (string) $child['jotform'],
                    'required' => (bool) $child['required'],
                ];
            }

            $significant[(string) $key] = [
                'qid'          => (string) $field['qid'],
                'name'         => (string) $field['name'],
                'type'         => (string) $field['type'],
                'jotform_type' => (string) $field['jotform_type'],
                'required'     => (bool) $field['required'],
                'supported'    => (bool) $field['supported'],
                'multiple'     => (bool) $field['multiple'],
                'allow_other'  => (bool) $field['allow_other'],
                'options'      => array_map(
                    static fn(array $option): string => (string) $option['value'],
                    $field['options']
                ),
                'children'     => $children,
            ];
        }

        // Sort by key so a reordered form yields the same fingerprint.
        ksort($significant);

        return hash('sha256', (string) json_encode($significant));
    }

    /**
     * Keeps composite child paths in sync after a collision rename.
     *
     * @param array<string, mixed> $field
     *
     * @return array<string, mixed>
     */
    private function rekeyChildren(array $field): array
    {
        foreach ($field['children'] as $childKey => $child) {
            $field['children'][$childKey]['key'] = SemanticKey::child(
                (string) $field['key'],
                (string) $child['child']
            );
        }

        return $field;
    }

    /**
     * @param array<string, mixed>             $field
     * @param array<int, array<string, mixed>> $diagnostics
     */
    private function collectFieldDiagnostics(array $field, array &$diagnostics): void
    {
        if (!$field['supported']) {
            $diagnostics[] = [
                // A required field we cannot map means the form cannot be
                // submitted correctly at all.
                'level'   => $field['required']
                    ? FormSchema::DIAGNOSTIC_ERROR
                    : FormSchema::DIAGNOSTIC_WARNING,
                'code'    => FormSchema::CODE_UNSUPPORTED,
                'qid'     => (string) $field['qid'],
                'key'     => (string) $field['key'],
                'message' => (string) $field['reason'],
            ];

            return;
        }

        if (SemanticKey::isFallback((string) $field['key'])) {
            $diagnostics[] = [
                'level'   => FormSchema::DIAGNOSTIC_WARNING,
                'code'    => FormSchema::CODE_NO_NAME,
                'qid'     => (string) $field['qid'],
                'key'     => (string) $field['key'],
                'message' => sprintf(
                    /* translators: 1: field label, 2: generated semantic key */
                    __(
                        'The Jotform field "%1$s" has no machine-readable name, so the key "%2$s" was derived from its ID. Give the field a name in Jotform to get a stable key.',
                        'jotform-bridge'
                    ),
                    (string) $field['label'],
                    (string) $field['key']
                ),
            ];
        }

        $needsOptions = in_array(
            $field['type'],
            [FieldNormalizer::TYPE_SELECT, FieldNormalizer::TYPE_RADIO, FieldNormalizer::TYPE_CHECKBOX],
            true
        );

        if ($needsOptions && $field['options'] === []) {
            $diagnostics[] = [
                'level'   => FormSchema::DIAGNOSTIC_WARNING,
                'code'    => FormSchema::CODE_NO_OPTIONS,
                'qid'     => (string) $field['qid'],
                'key'     => (string) $field['key'],
                'message' => sprintf(
                    /* translators: %s: field label */
                    __(
                        'The choice field "%s" reports no options, so its values cannot be validated against a list.',
                        'jotform-bridge'
                    ),
                    (string) $field['label']
                ),
            ];
        }
    }
}
