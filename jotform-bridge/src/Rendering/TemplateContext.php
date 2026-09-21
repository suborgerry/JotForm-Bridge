<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Integrations\Integration;
use JotformBridge\Submission\Guards\Honeypot;
use JotformBridge\Submission\Guards\Turnstile;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the variables a custom template is given. No form ID, qid or API
 * key is part of it.
 */
final class TemplateContext
{
    /**
     * @return array{
     *     integration: array<string, string>,
     *     schema: array<string, mixed>,
     *     endpoint: string,
     *     honeypot: string,
     *     turnstile: string,
     *     noscript: string
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
            // Ready-to-print markup; `turnstile` is empty unless configured.
            'honeypot'    => Honeypot::markup('jfb-' . $integration->slug()),
            'turnstile'   => Turnstile::markup(),
            'noscript'    => AutoRenderer::noscript(),
        ];
    }

    /**
     * The schema flattened to semantic paths, presentation properties only.
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
