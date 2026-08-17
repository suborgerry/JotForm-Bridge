<?php

declare(strict_types=1);

namespace JotformBridge\Integrations;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Templates\CompatibilityReport;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Templates\TemplateValidator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Answers "can this integration render?" from cached state only.
 *
 * Compatibility is derived, never stored: it is a function of the cached schema
 * and the cached registry, and deriving it on demand is cheaper than keeping a
 * third copy of the truth in sync. Jotform is never contacted from here.
 */
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

    public function __construct(
        SchemaRepository $schemas,
        TemplateRegistry $templates,
        ?TemplateValidator $validator = null
    ) {
        $this->schemas   = $schemas;
        $this->templates = $templates;
        $this->validator = $validator ?? new TemplateValidator();
    }

    /**
     * @return array{
     *     state: string,
     *     report: CompatibilityReport|null,
     *     label: string,
     *     message: string,
     *     fingerprint: string
     * }
     */
    public function check(Integration $integration): array
    {
        if ($integration->formId() === '') {
            return $this->state(
                self::STATE_NO_FORM,
                __('No Jotform form selected', 'jotform-bridge'),
                __('Select a Jotform form for this integration.', 'jotform-bridge')
            );
        }

        $schema = $this->schemas->cached($integration->formId());

        if ($schema === null) {
            return $this->state(
                self::STATE_NO_SCHEMA,
                __('Schema not loaded', 'jotform-bridge'),
                __('Use Refresh Schema to load the form definition from Jotform.', 'jotform-bridge')
            );
        }

        if (!$integration->usesCustomTemplate()) {
            // The automatic renderer outputs exactly the semantic paths the
            // schema supports, so validating the schema against that list is
            // not an approximation — it is what the visitor will get.
            $report = $this->validator->validate($schema, $schema->semanticPaths());

            return [
                'state'       => self::STATE_AUTO,
                'report'      => $report,
                'label'       => $report->status() === CompatibilityReport::STATUS_COMPATIBLE
                    ? __('Auto rendering', 'jotform-bridge')
                    : sprintf(
                        /* translators: %s: compatibility status, e.g. "Compatible with warnings" */
                        __('Auto rendering — %s', 'jotform-bridge'),
                        $report->statusLabel()
                    ),
                'message'     => $this->autoMessage($schema),
                'fingerprint' => $schema->fingerprint(),
            ];
        }

        if (!$this->templates->has($integration->templateSlug())) {
            return $this->state(
                self::STATE_NO_TEMPLATE,
                __('Template not found', 'jotform-bridge'),
                sprintf(
                    /* translators: %s: template slug */
                    __('The template "%s" is not in the registry. Rescan templates or pick another one.', 'jotform-bridge'),
                    $integration->templateSlug()
                ),
                $schema->fingerprint()
            );
        }

        $report = $this->validator->validate(
            $schema,
            $this->templates->fields($integration->templateSlug()),
            $this->templates->dynamicCount($integration->templateSlug())
        );

        return [
            'state'       => self::STATE_OK,
            'report'      => $report,
            'label'       => $report->statusLabel(),
            'message'     => '',
            'fingerprint' => $schema->fingerprint(),
        ];
    }

    /**
     * What auto rendering will actually produce, in one sentence.
     */
    private function autoMessage(FormSchema $schema): string
    {
        $supported   = count($schema->semanticPaths());
        $unsupported = count($schema->unsupportedFields());

        if ($supported === 0) {
            return __('This form has no fields that can be rendered automatically.', 'jotform-bridge');
        }

        $message = sprintf(
            /* translators: %d: number of inputs */
            _n(
                'The form is built from the schema: %d input.',
                'The form is built from the schema: %d inputs.',
                $supported,
                'jotform-bridge'
            ),
            $supported
        );

        if ($unsupported > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d: number of skipped fields */
                _n(
                    '%d Jotform field is not supported and is left out.',
                    '%d Jotform fields are not supported and are left out.',
                    $unsupported,
                    'jotform-bridge'
                ),
                $unsupported
            );
        }

        return $message;
    }

    /**
     * @return array{state:string, report:null, label:string, message:string, fingerprint:string}
     */
    private function state(string $state, string $label, string $message, string $fingerprint = ''): array
    {
        return [
            'state'       => $state,
            'report'      => null,
            'label'       => $label,
            'message'     => $message,
            'fingerprint' => $fingerprint,
        ];
    }
}
