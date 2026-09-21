<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\ConditionalLogic;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Plugin;
use JotformBridge\Submission\TestSubmission;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Templates\TemplateScaffold;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Jotform Bridge → Integrations": the list, the editor and their actions.
 *
 * Screens read stored state only. Jotform is contacted solely by the explicit
 * actions: Connect form, Sync Schema and Send Test Submission.
 */
final class IntegrationsPage
{
    public const MENU_SLUG  = 'jotform-bridge';
    public const CAPABILITY = Plugin::CAPABILITY;

    public const ACTION_SAVE    = 'jotform_bridge_save_integration';
    public const ACTION_DELETE  = 'jotform_bridge_delete_integration';
    public const ACTION_SYNC    = 'jotform_bridge_sync_schema';
    public const ACTION_TEST    = 'jotform_bridge_test_submission';

    /** "Connect form", answered over admin-ajax. */
    public const ACTION_CONNECT = 'jotform_bridge_connect_form';

    private const FLASH_PREFIX = 'jotform_bridge_notice_';

    /** Below Settings, above Tools; fractional to avoid menu-key collisions. */
    private const MENU_POSITION = 58.7;

    private IntegrationRepository $integrations;

    private FormRepository $forms;

    private SchemaRepository $schemas;

    private TemplateRegistry $templates;

    private CompatibilityChecker $compatibility;

    private RedirectTarget $redirects;

    /** Null without a Jotform client; the test action is then not offered. */
    private ?TestSubmission $tests;

    public function __construct(
        IntegrationRepository $integrations,
        FormRepository $forms,
        SchemaRepository $schemas,
        TemplateRegistry $templates,
        CompatibilityChecker $compatibility,
        ?TestSubmission $tests = null
    ) {
        $this->integrations  = $integrations;
        $this->forms         = $forms;
        $this->schemas       = $schemas;
        $this->templates     = $templates;
        $this->compatibility = $compatibility;
        $this->redirects     = new RedirectTarget();
        $this->tests         = $tests;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ACTION_SYNC, [$this, 'handleSyncSchema']);
        add_action('wp_ajax_' . self::ACTION_CONNECT, [$this, 'handleConnectForm']);

        if ($this->tests !== null) {
            add_action('admin_post_' . self::ACTION_TEST, [$this, 'handleTestSubmission']);
        }
    }

    public function registerMenu(): void
    {
        add_menu_page(
            __('Jotform Bridge', 'jotform-bridge'),
            __('Jotform Bridge', 'jotform-bridge'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
            $this->menuIcon(),
            self::MENU_POSITION
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

    /** Menu icon as a data URI, or a Dashicon when the asset is missing. */
    private function menuIcon(): string
    {
        $path = JOTFORM_BRIDGE_DIR . 'assets/menu-icon.svg';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file shipped inside this plugin, not a URL.
        $svg  = is_readable($path) ? file_get_contents($path) : false;

        if ($svg === false || $svg === '') {
            return 'dashicons-feedback';
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- the encoding a data: URI requires, not obfuscation.
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
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
                'integration' => $integration,
                'form_title'  => $this->formTitle($integration->formId()),
            ];
        }

        $notice = $flash;
        $page   = self::MENU_SLUG;

        // Template slug → the integrations bound to it.
        $templateUsage = [];

        foreach ($integrations as $integration) {
            if (!$integration->usesCustomTemplate() || $integration->templateSlug() === '') {
                continue;
            }

            $templateUsage[$integration->templateSlug()][] = $integration;
        }

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

        // A failed save hands the submitted values back.
        if ($flash !== null && !empty($flash['input']) && is_array($flash['input'])) {
            $integration = Integration::fromInput($flash['input']);
        }

        $isNew         = $integration === null || $view === 'new';
        $originalSlug  = $view === 'edit' ? $slug : '';
        $integration   = $integration ?? new Integration('', '', '', Integration::MODE_DEFAULT, '');
        $compatibility = $this->compatibility->check($integration);
        $redirect      = $this->redirects->check($integration);
        $schema        = $integration->formId() !== '' ? $this->schemas->stored($integration->formId()) : null;
        $conditionErrors = ConditionalLogic::errors($integration->conditions(), $schema);
        $conditionRows = $integration->conditions();

        $schemaMeta    = $integration->formId() !== '' ? $this->schemas->meta($integration->formId()) : null;
        $schemaStale   = $integration->formId() !== '' && $this->schemas->isStale($integration->formId());
        $connectedForm = $integration->formId() !== '' ? $this->forms->get($integration->formId()) : null;
        $templates     = $this->templates->choices();
        $canTest       = $this->tests !== null;

        $scaffold     = $schema !== null ? (new TemplateScaffold())->build($integration, $schema) : '';
        $scaffoldFile = $schema !== null ? (new TemplateScaffold())->fileName($integration) : '';
        $notice       = $flash;
        $page         = self::MENU_SLUG;

        require __DIR__ . '/views/integration-edit.php';
    }

    public function handleSave(): void
    {
        $this->guard(self::ACTION_SAVE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $raw = isset($_POST['jotform_integration']) && is_array($_POST['jotform_integration'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitized in fromInput().
            ? wp_unslash($_POST['jotform_integration'])
            : [];

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $originalSlug = isset($_POST['original_slug'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
            ? sanitize_key((string) wp_unslash($_POST['original_slug']))
            : '';

        // Conditions are never accepted from the request; preserved on rename.
        $previous = $originalSlug !== '' ? $this->integrations->get($originalSlug) : null;
        $raw['conditions'] = $previous !== null ? $previous->conditions() : [];

        $integration = Integration::fromInput($raw);
        $errors      = ConditionalLogic::errors(
            $integration->conditions(),
            $this->schemas->stored($integration->formId())
        );
        if ($integration->conditions() !== [] && !$this->schemas->isSynced($integration->formId())) {
            $errors[] = __('Sync the schema before configuring conditional logic.', 'jotform-bridge');
        }

        if (
            $integration->usesCustomTemplate()
            && $integration->templateSlug() !== ''
            && !$this->templates->has($integration->templateSlug())
        ) {
            $errors[] = sprintf(
                /* translators: %s: template slug */
                __('The template "%s" is not in the registry.', 'jotform-bridge'),
                $integration->templateSlug()
            );
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

        // Saving never fetches; unconnected and unsynced forms are warnings.
        $warnings = [];

        if ($integration->formId() !== '' && !$this->forms->has($integration->formId())) {
            $warnings[] = __(
                'This Jotform form has not been connected yet. Press Connect form to check that it exists and to load its definition.',
                'jotform-bridge'
            );
        }

        if (!$this->schemas->isSynced($integration->formId())) {
            $warnings[] = __(
                'This form has not been synced yet. Press Sync Schema to load its definition from Jotform — the form cannot render until you do.',
                'jotform-bridge'
            );
        }

        // A broken redirect target is a warning, not a refusal.
        $target = $this->redirects->check($integration);

        if (RedirectTarget::isBroken($target)) {
            $warnings[] = (string) $target['message'];
        }

        $this->flash(
            $warnings === [] ? 'success' : 'warning',
            __('Integration saved.', 'jotform-bridge'),
            $warnings
        );

        $this->redirect(['view' => 'edit', 'integration' => $integration->slug()]);
    }

    public function handleDelete(): void
    {
        $this->guard(self::ACTION_DELETE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';

        $integration = $this->integrations->get($slug);
        $formId      = $integration !== null ? $integration->formId() : '';

        if ($this->integrations->delete($slug)) {
            // Drop the form record when nothing names it any more; the schema stays.
            if ($formId !== '' && !$this->isFormInUse($formId)) {
                $this->forms->forget($formId);
            }

            $this->flash('success', __('Integration deleted.', 'jotform-bridge'));
        } else {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
        }

        $this->redirect([]);
    }

    /** Sync Schema: fetches and stores the definition of one integration's form. */
    public function handleSyncSchema(): void
    {
        $this->guard(self::ACTION_SYNC);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug        = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
        $integration = $this->integrations->get($slug);
        $return      = $this->returnArgs();

        if ($integration === null) {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
            $this->redirect([]);
        }

        if ($integration->formId() === '') {
            $this->flash('error', __('This integration has no Jotform form selected.', 'jotform-bridge'));
            $this->redirect($return);
        }

        $before   = $this->schemas->meta($integration->formId())['fingerprint'];
        $response = $this->schemas->sync($integration->formId());

        if (!$response->isSuccess()) {
            $this->flash('error', $response->errorMessage());
            $this->redirect($return);
        }

        $after   = $this->schemas->meta($integration->formId())['fingerprint'];
        $changed = $before !== '' && $before !== $after;

        $report = $this->compatibility->check($integration);

        $this->flash(
            $report['report'] !== null && !$report['report']->isValid() ? 'warning' : 'success',
            $changed
                ? __('The Jotform form has changed since the last sync. Compatibility was re-checked.', 'jotform-bridge')
                : __('Schema synced. The Jotform form has not changed.', 'jotform-bridge'),
            $report['report'] !== null ? $report['report']->messages() : []
        );

        $this->redirect($return);
    }

    /** Sends one real submission to Jotform and reports what came back. */
    public function handleTestSubmission(): void
    {
        $this->guard(self::ACTION_TEST);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $slug        = isset($_POST['integration']) ? sanitize_key((string) wp_unslash($_POST['integration'])) : '';
        $integration = $this->integrations->get($slug);
        $return      = $this->returnArgs();

        if ($integration === null || $this->tests === null) {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
            $this->redirect([]);
        }

        if ($integration->formId() === '') {
            $this->flash('error', __('This integration has no Jotform form selected.', 'jotform-bridge'));
            $this->redirect($return);
        }

        $response = $this->tests->send($integration);

        if (!$response->isSuccess()) {
            $this->flash(
                'error',
                __('The test submission was not accepted.', 'jotform-bridge'),
                [$response->errorMessage()]
            );

            $this->redirect($return);
        }

        $submissionId = (string) ($response->data()['submission_id'] ?? '');

        $this->flash(
            'success',
            __('The test submission reached Jotform.', 'jotform-bridge'),
            [
                $submissionId !== ''
                    ? sprintf(
                        /* translators: %s: Jotform submission ID */
                        __('Jotform submission ID: %s', 'jotform-bridge'),
                        $submissionId
                    )
                    : __('Jotform accepted it but returned no submission ID.', 'jotform-bridge'),
                __('It is a real submission: it is in your Jotform inbox, it triggered whatever notifications the form has, and it counts towards this month\'s allowance. Delete it in Jotform if you do not want it there.', 'jotform-bridge'),
            ]
        );

        $this->redirect($return);
    }

    /**
     * Connect form: resolves a form ID into a title and, when the form has no
     * stored schema yet, syncs it. A 401 is ambiguous (missing form, foreign
     * form or bad key) and is reported as such.
     */
    public function handleConnectForm(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(
                ['message' => __('You are not allowed to perform this action.', 'jotform-bridge')],
                403
            );
        }

        check_ajax_referer(self::ACTION_CONNECT);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_ajax_referer() above.
        $formId = isset($_POST['form_id']) ? sanitize_text_field(wp_unslash((string) $_POST['form_id'])) : '';

        if ($formId === '' || !ctype_digit($formId)) {
            wp_send_json_error(
                [
                    'message' => __(
                        'A Jotform form ID is digits only. It is the last part of the form URL, for example the 240000000000001 in form.jotform.com/240000000000001.',
                        'jotform-bridge'
                    ),
                ],
                400
            );
        }

        $response = $this->forms->connect($formId);

        if (!$response->isSuccess()) {
            wp_send_json_error(
                [
                    'message' => $response->errorMessage(),
                    'hint'    => $response->status() === 401
                        ? __(
                            'Jotform refuses this the same way whether the form does not exist, belongs to another account, or the API key is wrong. Check the ID against the form URL, then use Check Connection on the Settings screen to rule out the key.',
                            'jotform-bridge'
                        )
                        : '',
                ],
                200
            );
        }

        $form   = $response->data();
        $schema = $this->connectSchema($formId);
        $hints  = [];
        $state  = $schema['state'];

        if (strtoupper((string) $form['status']) === FormRepository::STATUS_DELETED) {
            $hints[] = __('This form is in the Jotform trash. It will not accept submissions until it is restored.', 'jotform-bridge');
            $state   = 'warning';
        }

        $hints[] = $schema['hint'];

        wp_send_json_success(
            [
                'id'      => (string) $form['id'],
                'title'   => (string) $form['title'],
                'status'  => (string) $form['status'],
                'state'   => $state,
                'message' => sprintf(
                    /* translators: 1: Jotform form title, 2: Jotform form status, e.g. ENABLED */
                    __('Connected: %1$s (%2$s)', 'jotform-bridge'),
                    (string) $form['title'] !== ''
                        ? (string) $form['title']
                        : __('untitled form', 'jotform-bridge'),
                    (string) $form['status']
                ),
                'hint'    => implode(' ', $hints),
            ]
        );
    }

    /**
     * Syncs the schema of a form that has none; a stored schema is never
     * replaced here. A failed sync is a warning, not a refusal.
     *
     * @return array{state:string, hint:string}
     */
    private function connectSchema(string $formId): array
    {
        if ($this->schemas->isSynced($formId)) {
            return [
                'state' => 'ok',
                'hint'  => __(
                    'Its definition is already stored and was left as it is. Sync Schema, on a saved integration, is what refreshes it.',
                    'jotform-bridge'
                ),
            ];
        }

        $response = $this->schemas->sync($formId);

        if (!$response->isSuccess()) {
            return [
                'state' => 'warning',
                'hint'  => sprintf(
                    /* translators: %s: the error Jotform or WordPress reported */
                    __('The form exists, but its definition could not be loaded: %s', 'jotform-bridge'),
                    $response->errorMessage()
                ),
            ];
        }

        $schema = $response->data()['schema'];

        if ($schema instanceof FormSchema && !$schema->isUsable()) {
            return [
                'state' => 'warning',
                'hint'  => __(
                    'Its definition was loaded, but the schema has errors that have to be fixed in Jotform. Save the integration to see them listed.',
                    'jotform-bridge'
                ),
            ];
        }

        $fields = $schema instanceof FormSchema ? count($schema->fields()) : 0;

        return [
            'state' => 'ok',
            'hint'  => sprintf(
                /* translators: %d: number of fields in the loaded schema */
                _n(
                    'Its definition was loaded: %d field. Save the integration to finish.',
                    'Its definition was loaded: %d fields. Save the integration to finish.',
                    $fields,
                    'jotform-bridge'
                ),
                $fields
            ),
        ];
    }

    /** Capability and nonce check shared by every mutating action. */
    private function guard(string $action): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'jotform-bridge'), '', ['response' => 403]);
        }

        check_admin_referer($action);
    }

    /**
     * Where an action returns to: the editor when `return_view` says so, else the list.
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
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
            ? sanitize_key((string) wp_unslash($_POST['return_integration']))
            : '';

        if ($view === 'edit' && $slug === '') {
            return [];
        }

        return $view === 'edit'
            ? ['view' => 'edit', 'integration' => $slug]
            : ['view' => 'new'];
    }

    /** A timestamp in the site's date format and timezone; used by the views. */
    private function formatDateTime(int $timestamp): string
    {
        $date = (string) get_option('date_format', 'Y-m-d');
        $time = (string) get_option('time_format', 'H:i');

        return (string) wp_date(trim($date . ' ' . $time), $timestamp);
    }

    /** Whether any stored integration still points at this form. */
    private function isFormInUse(string $formId): bool
    {
        foreach ($this->integrations->all() as $integration) {
            if ($integration->formId() === $formId) {
                return true;
            }
        }

        return false;
    }

    private function formTitle(string $formId): string
    {
        return $this->forms->title($formId);
    }

    /**
     * Stores a one-shot notice for the redirect target.
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
     * Redirects to the plugin screen and ends the request.
     *
     * @param array<string, string> $args
     *
     * @return never
     */
    private function redirect(array $args)
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
