<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Templates\CompatibilityReport;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Templates\TemplateValidator;

if (!defined('ABSPATH')) {
    exit;
}

/** Derives "can this integration render?" from the stored schema and the registry. */
final class CompatibilityChecker
{
    public const STATE_OK          = 'ok';
    public const STATE_NO_FORM     = 'no_form';
    public const STATE_NO_SCHEMA   = 'no_schema';
    public const STATE_NO_TEMPLATE = 'no_template';
    public const STATE_AUTO        = 'auto';

    private SchemaRepository $schemas;

    private TemplateRegistry $templates;

    private TemplateValidator $validator;

    public function __construct(SchemaRepository $schemas, TemplateRegistry $templates)
    {
        $this->schemas   = $schemas;
        $this->templates = $templates;
        $this->validator = new TemplateValidator();
    }

    /**
     * @return array{
     *     state: string,
     *     report: CompatibilityReport|null,
     *     fingerprint: string
     * }
     */
    public function check(Integration $integration): array
    {
        if ($integration->formId() === '') {
            return $this->state(self::STATE_NO_FORM);
        }

        $schema = $this->schemas->stored($integration->formId());

        if ($schema === null) {
            return $this->state(self::STATE_NO_SCHEMA);
        }

        if (!$integration->usesCustomTemplate()) {
            // The auto renderer outputs exactly the supported semantic paths.
            $report = $this->validator->validate($schema, $schema->semanticPaths());

            return [
                'state'       => self::STATE_AUTO,
                'report'      => $report,
                'fingerprint' => $schema->fingerprint(),
            ];
        }

        if (!$this->templates->has($integration->templateSlug())) {
            return $this->state(self::STATE_NO_TEMPLATE, $schema->fingerprint());
        }

        $report = $this->validator->validate(
            $schema,
            $this->templates->fields($integration->templateSlug()),
            $this->templates->dynamicCount($integration->templateSlug()),
            array_values(array_map(
                static fn(array $rule): string => $rule['target'],
                array_filter($integration->conditions(), static fn(array $rule): bool => $rule['action'] === 'require')
            ))
        );

        return [
            'state'       => self::STATE_OK,
            'report'      => $report,
            'fingerprint' => $schema->fingerprint(),
        ];
    }

    /**
     * @return array{state:string, report:null, fingerprint:string}
     */
    private function state(string $state, string $fingerprint = ''): array
    {
        return [
            'state'       => $state,
            'report'      => null,
            'fingerprint' => $fingerprint,
        ];
    }
}
