<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Integrations\Integration;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the variables a custom template is given.
 *
 * Deliberately narrow: everything a template legitimately needs to render
 * markup, and nothing else. The Jotform form ID, the question IDs and the API
 * key are not part of it and cannot be reached from it — a template that wanted
 * to leak them would have nothing to leak.
 */
final class TemplateContext
{
    /**
     * @return array{
     *     integration: array<string, string>,
     *     schema: array<string, mixed>,
     *     endpoint: string
     * }
     */
    public static function build(Integration $integration, FormSchema $schema, string $endpoint): array
    {
        return [
            'integration' => [
                'slug'     => $integration->slug(),
                'name'     => $integration->name(),
                'template' => $integration->templateSlug(),
            ],
            'schema'      => [
                'fields'   => self::fields($schema),
                'required' => $schema->requiredPaths(),
            ],
            'endpoint'    => $endpoint,
        ];
    }

    /**
     * The schema flattened to the semantic paths a template addresses, keeping
     * only presentation-relevant properties.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fields(FormSchema $schema): array
    {
        $fields = [];

        foreach ($schema->supportedFields() as $field) {
            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $fields[(string) $child['key']] = [
                        'key'      => (string) $child['key'],
                        'label'    => (string) ($child['label'] !== '' ? $child['label'] : $field['label']),
                        'type'     => 'text',
                        'required' => (bool) $child['required'],
                        'multiple' => false,
                        'options'  => [],
                        'parent'   => (string) $field['key'],
                    ];
                }

                continue;
            }

            $fields[(string) $field['key']] = [
                'key'      => (string) $field['key'],
                'label'    => (string) $field['label'],
                'type'     => (string) $field['type'],
                'required' => (bool) $field['required'],
                'multiple' => (bool) $field['multiple'],
                'options'  => array_values(
                    array_map(
                        static fn(array $option): array => [
                            'value' => (string) $option['value'],
                            'label' => (string) $option['label'],
                        ],
                        $field['options']
                    )
                ),
                'parent'   => '',
            ];
        }

        return $fields;
    }
}
