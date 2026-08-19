<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Rest\SubmissionController;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The single entry point for rendering a form.
 *
 * Both the `jotform_form()` helper and the `[jotform_form]` shortcode go
 * through here, so there is exactly one implementation of "what does this slug
 * render to". Nothing in this class can fail loudly: a missing, disabled or
 * broken integration produces empty output on a live site and a diagnostic only
 * where a developer or administrator would want one.
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
        ?Logger $logger = null,
        ?AutoRenderer $auto = null
    ) {
        $this->integrations = $integrations;
        $this->schemas      = $schemas;
        $this->custom       = $custom;
        $this->assets       = $assets;
        $this->logger       = $logger;
        $this->auto         = $auto ?? new AutoRenderer();
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

        if (!$integration->isActive()) {
            return $this->diagnostic(
                $slug,
                __('This integration is disabled.', 'jotform-bridge')
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

        $endpoint = SubmissionController::endpoint($integration->slug());

        if (!$integration->usesCustomTemplate()) {
            return $this->renderAutomatically($integration, $schema, $endpoint);
        }

        $context = TemplateContext::build($integration, $schema, $endpoint);

        try {
            $html = $this->custom->render($integration->templateSlug(), $context);
        } catch (\Throwable $error) {
            // A broken theme template must not take the page down with it.
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
                    /* translators: %s: template slug */
                    __('The template "%s" is not registered. Rescan the templates.', 'jotform-bridge'),
                    $integration->templateSlug()
                )
            );
        }

        $this->assets->enqueue();

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

    /**
     * The fallback path: markup built from the schema, with no theme file involved.
     */
    private function renderAutomatically(Integration $integration, FormSchema $schema, string $endpoint): string
    {
        $html = $this->auto->render($integration, $schema, $endpoint);

        if ($html === '') {
            return $this->diagnostic(
                $integration->slug(),
                __('This Jotform form has no fields this plugin can render automatically.', 'jotform-bridge')
            );
        }

        $this->assets->enqueue();

        return $html;
    }

    /**
     * Storage-only schema access.
     *
     * Rendering a page never contacts Jotform — not to refresh a schema, and
     * not to fetch a missing one. A form whose schema was never synced does not
     * render at all, which is a visible, one-click-fixable state; a page that
     * hangs on an unreachable API while a visitor waits is not.
     */
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

    /**
     * What is shown when a form cannot be rendered.
     *
     * Visitors get nothing at all. Administrators get the reason, because a
     * silently missing form is the hardest kind of problem to notice; with
     * debug logging on, the reason also reaches the log.
     */
    private function diagnostic(string $slug, string $reason): string
    {
        $this->log('A form could not be rendered.', [
            'integration' => $slug,
            'reason'      => $reason,
        ]);

        if (!current_user_can('manage_options')) {
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
