<?php

declare(strict_types=1);

namespace JotformBridge\Templates;

use JotformBridge\Forms\FormSchema;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Compares the literal `data-jotform-field` identifiers of a template with the
 * Normalized Schema. Purely static; dynamic identifiers are reported as such.
 */
final class TemplateValidator
{
    /**
     * @param array<int, string> $templateFields Literal identifiers from the template.
     * @param int                $dynamicCount   Identifiers produced by PHP.
     * @param array<int, string> $conditionalRequired Fields that can become required.
     */
    public function validate(FormSchema $schema, array $templateFields, int $dynamicCount = 0, array $conditionalRequired = []): CompatibilityReport
    {
        $expected = $this->expectedPaths($schema);
        $declared = [];

        foreach ($templateFields as $field) {
            $field = trim((string) $field);

            if ($field !== '') {
                $declared[$field] = true;
            }
        }

        $rows = [];

        foreach ($expected as $path => $meta) {
            if (in_array($path, $conditionalRequired, true)) {
                $meta['required'] = true;
            }
            $rows[] = $this->schemaRow($path, $meta, isset($declared[$path]));

            unset($declared[$path]);
        }

        foreach (array_keys($declared) as $path) {
            $rows[] = $this->unknownRow($schema, (string) $path);
        }

        if ($dynamicCount > 0) {
            $rows[] = [
                'path'     => '',
                'label'    => '',
                'qid'      => '',
                'type'     => '',
                'required' => false,
                'status'   => CompatibilityReport::DYNAMIC,
                'message'  => sprintf(
                    /* translators: %d: number of dynamic identifiers */
                    _n(
                        '%d dynamic field identifier cannot be statically validated.',
                        '%d dynamic field identifiers cannot be statically validated.',
                        $dynamicCount,
                        'jotform-bridge'
                    ),
                    $dynamicCount
                ),
            ];
        }

        return new CompatibilityReport($rows, $dynamicCount);
    }

    /**
     * Every semantic path the schema exposes; composites contribute their
     * children, not themselves.
     *
     * @return array<string, array<string, mixed>> Path => row metadata.
     */
    private function expectedPaths(FormSchema $schema): array
    {
        $paths = [];

        foreach ($schema->fields() as $field) {
            $key   = (string) $field['key'];
            $label = (string) $field['label'];

            if ($field['children'] === []) {
                $paths[$key] = [
                    'label'     => $label,
                    'qid'       => (string) $field['qid'],
                    'type'      => (string) $field['type'],
                    'required'  => (bool) $field['required'],
                    'supported' => (bool) $field['supported'],
                ];

                continue;
            }

            foreach ($field['children'] as $child) {
                $childLabel = (string) $child['label'] !== ''
                    ? sprintf('%s — %s', $label, (string) $child['label'])
                    : sprintf('%s — %s', $label, (string) $child['child']);

                $paths[(string) $child['key']] = [
                    'label'     => $childLabel,
                    'qid'       => (string) $field['qid'],
                    'type'      => (string) $field['type'],
                    'required'  => (bool) $child['required'],
                    'supported' => (bool) $field['supported'],
                ];
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function schemaRow(string $path, array $meta, bool $present): array
    {
        $row = [
            'path'     => $path,
            'label'    => (string) $meta['label'],
            'qid'      => (string) $meta['qid'],
            'type'     => (string) $meta['type'],
            'required' => (bool) $meta['required'],
            'status'   => CompatibilityReport::MATCHED,
            'message'  => '',
        ];

        if (!$meta['supported']) {
            $row['status']  = $meta['required']
                ? CompatibilityReport::UNSUPPORTED_REQUIRED
                : CompatibilityReport::UNSUPPORTED_OPTIONAL;
            $row['message'] = sprintf(
                $meta['required']
                    /* translators: 1: field label, 2: semantic key */
                    ? __(
                        'The required field "%1$s" (%2$s) uses a Jotform type this plugin cannot map yet.',
                        'jotform-bridge'
                    )
                    /* translators: 1: field label, 2: semantic key */
                    : __(
                        'The optional field "%1$s" (%2$s) uses a Jotform type this plugin cannot map yet.',
                        'jotform-bridge'
                    ),
                (string) $meta['label'],
                $path
            );

            return $row;
        }

        if ($present) {
            return $row;
        }

        $row['status']  = $meta['required']
            ? CompatibilityReport::MISSING_REQUIRED
            : CompatibilityReport::MISSING_OPTIONAL;
        $row['message'] = sprintf(
            $meta['required']
                /* translators: 1: field label, 2: semantic key */
                ? __('The template is missing the required field "%1$s" (data-jotform-field="%2$s").', 'jotform-bridge')
                /* translators: 1: field label, 2: semantic key */
                : __('The template is missing the optional field "%1$s" (data-jotform-field="%2$s").', 'jotform-bridge'),
            (string) $meta['label'],
            $path
        );

        return $row;
    }

    /**
     * A template identifier the schema does not offer.
     *
     * @return array<string, mixed>
     */
    private function unknownRow(FormSchema $schema, string $path): array
    {
        $field = $schema->field($path);

        // The parent key of a composite field gets a message naming its children.
        if ($field !== null && $field['children'] !== []) {
            $children = array_map(
                static fn(array $child): string => (string) $child['key'],
                array_values($field['children'])
            );

            return [
                'path'     => $path,
                'label'    => (string) $field['label'],
                'qid'      => (string) $field['qid'],
                'type'     => (string) $field['type'],
                'required' => (bool) $field['required'],
                'status'   => CompatibilityReport::COMPOSITE_PARENT,
                'message'  => sprintf(
                    /* translators: 1: semantic key, 2: comma separated child keys */
                    __(
                        '"%1$s" is a composite Jotform field and cannot be a single input. Use its sub-fields instead: %2$s.',
                        'jotform-bridge'
                    ),
                    $path,
                    implode(', ', $children)
                ),
            ];
        }

        return [
            'path'     => $path,
            'label'    => '',
            'qid'      => '',
            'type'     => '',
            'required' => false,
            'status'   => CompatibilityReport::UNKNOWN,
            'message'  => sprintf(
                /* translators: %s: semantic key used in the template */
                __(
                    'The template declares data-jotform-field="%s", which does not exist in the Jotform form.',
                    'jotform-bridge'
                ),
                $path
            ),
        ];
    }
}
