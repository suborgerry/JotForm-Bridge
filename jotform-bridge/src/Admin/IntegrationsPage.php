<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Templates\TemplateRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Jotform Bridge → Integrations": the list, the editor and their actions.
 *
 * The screen itself only reads cached state. Jotform is contacted from the
 * explicit Refresh Schema action, and the filesystem is scanned from the
 * explicit Rescan Templates action.
 */
final class IntegrationsPage
{
    public const MENU_SLUG  = 'jotform-bridge';
    public const CAPABILITY = 'manage_options';

    public const ACTION_SAVE    = 'jotform_bridge_save_integration';
    public const ACTION_DELETE  = 'jotform_bridge_delete_integration';
    public const ACTION_TOGGLE  = 'jotform_bridge_toggle_integration';
    public const ACTION_RESCAN  = 'jotform_bridge_rescan_templates';
    public const ACTION_REFRESH = 'jotform_bridge_refresh_schema';

    private const FLASH_PREFIX = 'jotform_bridge_notice_';

    private IntegrationRepository $integrations;

    private FormRepository $forms;

    private SchemaRepository $schemas;

    private TemplateRegistry $templates;

    private CompatibilityChecker $compatibility;

    public function __construct(
        IntegrationRepository $integrations,
        FormRepository $forms,
        SchemaRepository $schemas,
        TemplateRegistry $templates,
        CompatibilityChecker $compatibility
    ) {
        $this->integrations  = $integrations;
        $this->forms         = $forms;
        $this->schemas       = $schemas;
        $this->templates     = $templates;
        $this->compatibility = $compatibility;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ACTION_TOGGLE, [$this, 'handleToggle']);
        add_action('admin_post_' . self::ACTION_RESCAN, [$this, 'handleRescan']);
        add_action('admin_post_' . self::ACTION_REFRESH, [$this, 'handleRefreshSchema']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Jotform Bridge', 'jotform-bridge'),
            __('Jotform Bridge', 'jotform-bridge'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-feedback',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Integrations', 'jotform-bridge'),
            __('Integrations', 'jotform-bridge'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to access this page.', 'jotform-bridge'));
        }

        $flash = $this->takeFlash();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
        $view = isset($_GET['view']) ? sanitize_key((string) wp_unslash($_GET['view'])) : '';

        if ($view === 'new' || $view === 'edit') {
            $this->renderEditor($view, $flash);

            return;
        }

        $this->renderList($flash);
    }

    /**
     * @param array<string, mixed>|null $flash
     */
    private function renderList(?array $flash): void
    {
        $integrations = $this->integrations->all();
        $templates    = $this->templates;
        $rows         = [];

        foreach ($integrations as $integration) {
            $rows[$integration->slug()] = [
                'integration'   => $integration,
                'compatibility' => $this->compatibility->check($integration),
                'form_title'    => $this->formTitle($integration->formId()),
            ];
        }

        $notice      = $flash;
        $diagnostics = $this->templates->diagnostics();
        $page        = self::MENU_SLUG;

        require __DIR__ . '/views/integrations-list.php';
    }

    /**
     * @param array<string, mixed>|null $flash
     */
    private function renderEditor(string $view, ?array $flash): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup key.
        $slug        = isset($_GET['integration']) ? sanitize_key((string) wp_unslash($_GET['integration'])) : '';
        $integration = $view === 'edit' ? $this->integrations->get($slug) : null;

        if ($view === 'edit' && $integration === null) {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
            $this->redirect([]);
        }

        // A failed save hands the submitted values back so nothing is retyped.
        if ($flash !== null && !empty($flash['input']) && is_array($flash['input'])) {
            $integration = Integration::fromInput($flash['input']);
        }

        $isNew         = $integration === null || $view === 'new';
        $originalSlug  = $view === 'edit' ? $slug : '';
        $integration   = $integration ?? new Integration('', '', '', Integration::MODE_CUSTOM, '', true);
        $compatibility = $this->compatibility->check($integration);
        $schema        = $integration->formId() !== '' ? $this->schemas->cached($integration->formId()) : null;
        $schemaMeta    = $integration->formId() !== '' ? $this->schemas->meta($integration->formId()) : null;
        $forms         = $this->forms->all();
        $templates     = $this->templates->choices();
        $notice        = $flash;
        $page          = self::MENU_SLUG;

        require __DIR__ . '/views/integration-edit.php';
    }

    public function handleSave(): void
    {
        $this->guard(self::ACTION_SAVE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $raw = isset($_POST['jotform_integration']) && is_array($_POST['jotform_integration'])
            ? wp_unslash($_POST['jotform_integration']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $originalSlug = isset($_POST['original_slug'])
            ? sanitize_key((string) wp_unslash($_POST['original_slug']))
            : '';

        $integration = Integration::fromInput($raw);
        $errors      = [];

        // The template select is populated from the registry, so anything else
        // is either a stale form or a forged request.
        if ($integration->usesCustomTemplate()
            && $integration->templateSlug() !== ''
            && !$this->templates->has($integration->templateSlug())
        ) {
            $errors[] = sprintf(
                /* translators: %s: template slug */
                __('The template "%s" is not in the registry.', 'jotform-bridge'),
                $integration->templateSlug()
            );
        }

        if ($integration->formId() !== '' && !$this->isKnownForm($integration->formId())) {
            $errors[] = __('The selected Jotform form is not in the cached account form list.', 'jotform-bridge');
        }

        if ($errors === []) {
            $errors = $this->integrations->save($integration, $originalSlug !== '' ? $originalSlug : null);
        }

        if ($errors !== []) {
            $this->flash('error', __('The integration could not be saved.', 'jotform-bridge'), $errors, $raw);

            $this->redirect(
                $originalSlug !== ''
                    ? ['view' => 'edit', 'integration' => $originalSlug]
                    : ['view' => 'new']
            );
        }

        // A newly bound form has no cached schema yet; fetching it here is what
        // makes the compatibility report meaningful straight away.
        if (!$this->schemas->isCached($integration->formId())) {
            $this->schemas->refresh($integration->formId());
        }

        $this->flash('success', __('Integration saved.', 'jotform-bridge'));
        $this->redirect(['view' => 'edit', 'integration' => $integration->slug()]);
    }

    public function handleDelete(): void
    {
        $this->guard(self::ACTION_DELETE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';

        if ($this->integrations->delete($slug)) {
            $this->flash('success', __('Integration deleted.', 'jotform-bridge'));
        } else {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
        }

        $this->redirect([]);
    }

    public function handleToggle(): void
    {
        $this->guard(self::ACTION_TOGGLE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $active = !empty($_POST['active']);

        if ($this->integrations->setActive($slug, $active)) {
            $this->flash(
                'success',
                $active
                    ? __('Integration activated.', 'jotform-bridge')
                    : __('Integration deactivated.', 'jotform-bridge')
            );
        } else {
            $this->flash('error', __('That integration could not be updated.', 'jotform-bridge'));
        }

        $this->redirect([]);
    }

    public function handleRescan(): void
    {
        $this->guard(self::ACTION_RESCAN);

        $registry = $this->templates->rescan();
        $count    = count($registry['templates']);

        $messages = [];

        foreach ($registry['diagnostics'] as $diagnostic) {
            $messages[] = (string) $diagnostic['message'];
        }

        $hasError = false;

        foreach ($registry['diagnostics'] as $diagnostic) {
            if ((string) $diagnostic['level'] === 'error') {
                $hasError = true;

                break;
            }
        }

        $this->flash(
            $hasError ? 'warning' : 'success',
            sprintf(
                /* translators: %d: number of templates */
                _n('%d template found.', '%d templates found.', $count, 'jotform-bridge'),
                $count
            ),
            $messages
        );

        $this->redirect($this->returnArgs());
    }

    public function handleRefreshSchema(): void
    {
        $this->guard(self::ACTION_REFRESH);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug        = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
        $integration = $this->integrations->get($slug);

        if ($integration === null) {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
            $this->redirect([]);
        }

        $before   = $this->schemas->meta($integration->formId())['fingerprint'];
        $response = $this->schemas->refresh($integration->formId());

        if (!$response->isSuccess()) {
            $this->flash('error', $response->errorMessage());
            $this->redirect(['view' => 'edit', 'integration' => $integration->slug()]);
        }

        $after   = $this->schemas->meta($integration->formId())['fingerprint'];
        $changed = $before !== '' && $before !== $after;

        $report = $this->compatibility->check($integration);

        $this->flash(
            $report['report'] !== null && !$report['report']->isValid() ? 'warning' : 'success',
            $changed
                ? __('The Jotform form has changed since the last refresh. Compatibility was re-checked.', 'jotform-bridge')
                : __('Schema refreshed. The Jotform form has not changed.', 'jotform-bridge'),
            $report['report'] !== null ? $report['report']->messages() : []
        );

        $this->redirect(['view' => 'edit', 'integration' => $integration->slug()]);
    }

    /**
     * Capability + nonce check shared by every mutating action.
     */
    private function guard(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'jotform-bridge'), '', ['response' => 403]);
        }

        check_admin_referer($action);
    }

    /**
     * Where a Rescan issued from the editor should return to.
     *
     * @return array<string, string>
     */
    private function returnArgs(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard(); used only as a view switch.
        $view = isset($_POST['return_view']) ? sanitize_key((string) wp_unslash($_POST['return_view'])) : '';

        if ($view !== 'edit' && $view !== 'new') {
            return [];
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug = isset($_POST['return_integration'])
            ? sanitize_key((string) wp_unslash($_POST['return_integration']))
            : '';

        if ($view === 'edit' && $slug === '') {
            return [];
        }

        return $view === 'edit'
            ? ['view' => 'edit', 'integration' => $slug]
            : ['view' => 'new'];
    }

    private function isKnownForm(string $formId): bool
    {
        foreach ($this->forms->all() as $form) {
            if (isset($form['id']) && (string) $form['id'] === $formId) {
                return true;
            }
        }

        return false;
    }

    private function formTitle(string $formId): string
    {
        foreach ($this->forms->all() as $form) {
            if (isset($form['id']) && (string) $form['id'] === $formId) {
                return (string) ($form['title'] ?? '');
            }
        }

        return '';
    }

    /**
     * Stores a one-shot notice for the redirect target.
     *
     * Kept out of the URL so that validation detail and the submitted values
     * survive the redirect without ending up in the browser history.
     *
     * @param array<int, string>   $messages
     * @param array<string, mixed> $input
     */
    private function flash(string $type, string $message, array $messages = [], array $input = []): void
    {
        set_transient(
            self::FLASH_PREFIX . get_current_user_id(),
            [
                'type'     => $type,
                'message'  => $message,
                'messages' => array_values(array_map('strval', $messages)),
                'input'    => $input,
            ],
            5 * MINUTE_IN_SECONDS
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function takeFlash(): ?array
    {
        $key    = self::FLASH_PREFIX . get_current_user_id();
        $stored = get_transient($key);

        if (!is_array($stored) || !isset($stored['message'])) {
            return null;
        }

        delete_transient($key);

        return [
            'type'     => isset($stored['type']) ? (string) $stored['type'] : 'info',
            'message'  => (string) $stored['message'],
            'messages' => isset($stored['messages']) && is_array($stored['messages']) ? $stored['messages'] : [],
            'input'    => isset($stored['input']) && is_array($stored['input']) ? $stored['input'] : [],
        ];
    }

    /**
     * @param array<string, string> $args
     */
    private function redirect(array $args): void
    {
        wp_safe_redirect(
            add_query_arg(
                array_merge(['page' => self::MENU_SLUG], $args),
                admin_url('admin.php')
            )
        );

        exit;
    }
}
