<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\ConditionalLogic;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Plugin;
use JotformBridge\Rest\SubmissionController;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The single entry point for rendering a form, behind both
 * `jotform_bridge_render()` and the `[jotform_form]` shortcode. A broken
 * integration renders nothing for visitors and a diagnostic for administrators.
 */
final class FormRenderer
{
    private IntegrationRepository $integrations;

    private SchemaRepository $schemas;

    private CustomTemplateRenderer $custom;

    private AutoRenderer $auto;

    private Assets $assets;

    private ?Logger $logger;

    public function __construct(
        IntegrationRepository $integrations,
        SchemaRepository $schemas,
        CustomTemplateRenderer $custom,
        Assets $assets,
        ?Logger $logger = null
    ) {
        $this->integrations = $integrations;
        $this->schemas      = $schemas;
        $this->custom       = $custom;
        $this->assets       = $assets;
        $this->logger       = $logger;
        $this->auto         = new AutoRenderer();
    }

    public function render(string $slug): string
    {
        $slug        = sanitize_key($slug);
        $integration = $slug === '' ? null : $this->integrations->get($slug);

        if ($integration === null) {
            return $this->diagnostic(
                $slug,
                sprintf(
                    /* translators: %s: integration slug */
                    __('There is no integration with the slug "%s".', 'jotform-bridge'),
                    $slug
                )
            );
        }

        $schema = $this->schema($integration);

        if ($schema === null) {
            return $this->diagnostic(
                $slug,
                __(
                    'The Jotform schema for this integration is not available. Open the integration and press Sync Schema.',
                    'jotform-bridge'
                )
            );
        }

        if (ConditionalLogic::errors($integration->conditions(), $schema) !== []) {
            return $this->diagnostic($slug, __('The saved conditional rules no longer match the synced schema and require maintenance. Rules are read-only in the integration editor.', 'jotform-bridge'));
        }

        $endpoint = SubmissionController::endpoint($integration->slug());

        if (!$integration->usesCustomTemplate()) {
            return $this->renderAutomatically($integration, $schema, $endpoint);
        }

        $context = TemplateContext::build($integration, $schema, $endpoint);

        try {
            $html = $this->custom->render($integration->templateSlug(), $context);
        } catch (\Throwable $error) {
            $this->log('A custom form template raised an error.', [
                'integration' => $slug,
                'template'    => $integration->templateSlug(),
                'error'       => $error->getMessage(),
            ]);

            return $this->diagnostic($slug, __('The form template raised an error.', 'jotform-bridge'));
        }

        if ($html === null) {
            return $this->diagnostic(
                $slug,
                sprintf(
                    /* translators: %s: expected template file name */
                    __('No template file named "%s" in the theme.', 'jotform-bridge'),
                    $integration->templateSlug() . '.php'
                )
            );
        }

        $this->assets->enqueue($slug, $integration->conditions(), $schema->requiredPaths());

        return $html;
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function shortcode($atts): string
    {
        $atts = shortcode_atts(['id' => ''], is_array($atts) ? $atts : [], 'jotform_form');

        return $this->render((string) $atts['id']);
    }

    private function renderAutomatically(Integration $integration, FormSchema $schema, string $endpoint): string
    {
        $html = $this->auto->render($integration, $schema, $endpoint);

        if ($html === '') {
            return $this->diagnostic(
                $integration->slug(),
                __('This Jotform form has no fields this plugin can render automatically.', 'jotform-bridge')
            );
        }

        $this->assets->enqueue($integration->slug(), $integration->conditions(), $schema->requiredPaths());

        return $html;
    }

    /** The stored, usable schema, or null; never contacts Jotform. */
    private function schema(Integration $integration): ?FormSchema
    {
        $stored = $this->schemas->stored($integration->formId());

        if ($stored === null) {
            $this->log('The schema needed for rendering has not been synced.', [
                'integration' => $integration->slug(),
                'form_id'     => $integration->formId(),
            ]);

            return null;
        }

        return $stored->isUsable() ? $stored : null;
    }

    /** Nothing for visitors, the reason for administrators, and a log line. */
    private function diagnostic(string $slug, string $reason): string
    {
        $this->log('A form could not be rendered.', [
            'integration' => $slug,
            'reason'      => $reason,
        ]);

        if (!current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        return sprintf(
            '<div class="jfb-notice jfb-notice--error"><strong>%s</strong> %s</div>',
            esc_html__('Jotform Bridge:', 'jotform-bridge'),
            esc_html($reason)
        );
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function log(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, $context);
        }
    }
}
