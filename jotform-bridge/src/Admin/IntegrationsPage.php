<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\Integration;
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
 * The screen itself only reads stored state. Jotform is contacted from the
 * explicit per-integration Sync Schema action and from nowhere else, and the
 * filesystem is read whenever the templates are listed. Saving an
 * integration, opening a screen or rendering a form never triggers a fetch.
 *
 * The five action handlers were considered for a class of their own, since this
 * is the longest file in the plugin. They stay: every one of them runs the same
 * four steps — guard(), do the work, flash(), redirect() — and moving them out
 * would produce two classes joined by those four private helpers, which is more
 * structure describing the same thing rather than less. Length here is five
 * short handlers, not one long anything.
 */
final class IntegrationsPage
{
    public const MENU_SLUG  = 'jotform-bridge';
    public const CAPABILITY = Plugin::CAPABILITY;

    public const ACTION_SAVE    = 'jotform_bridge_save_integration';
    public const ACTION_DELETE  = 'jotform_bridge_delete_integration';
    public const ACTION_SYNC    = 'jotform_bridge_sync_schema';
    public const ACTION_TEST    = 'jotform_bridge_test_submission';

    /**
     * "Connect form" — the only asynchronous action in the admin.
     *
     * It answers over admin-ajax rather than through admin-post because it
     * resolves one field of a form that is still being filled in: a redirect
     * would either lose everything typed so far or have to save it, and neither
     * is what pressing a button beside a text field should mean.
     */
    public const ACTION_CONNECT = 'jotform_bridge_connect_form';

    private const FLASH_PREFIX = 'jotform_bridge_notice_';

    /**
     * Where the top-level menu sits, below Settings and above Tools.
     *
     * Fractional, and deliberately. WordPress keys the menu array by this
     * value, so two plugins choosing the same whole number do not end up
     * adjacent — one silently replaces the other, and which one depends on
     * plugin load order. Whole numbers are exactly what everybody picks, which
     * is what makes them the collision.
     */
    private const MENU_POSITION = 58.7;

    private IntegrationRepository $integrations;

    private FormRepository $forms;

    private SchemaRepository $schemas;

    private TemplateRegistry $templates;

    private CompatibilityChecker $compatibility;

    private RedirectTarget $redirects;

    /**
     * Null when the page was built without a Jotform client to send with, in
     * which case the action is not registered and the button is not offered.
     */
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

    /**
     * Brand mark for the top-level menu, inlined as a data URI.
     *
     * Falls back to a Dashicon if the asset is missing.
     */
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

        // The templates table reads the reverse of the integration list: one
        // template can back several integrations, so each slug collects every
        // integration bound to it rather than a single one.
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

        // A failed save hands the submitted values back so nothing is retyped.
        if ($flash !== null && !empty($flash['input']) && is_array($flash['input'])) {
            $integration = Integration::fromInput($flash['input']);
        }

        $isNew         = $integration === null || $view === 'new';
        $originalSlug  = $view === 'edit' ? $slug : '';
        $integration   = $integration ?? new Integration('', '', '', Integration::MODE_CUSTOM, '');
        $compatibility = $this->compatibility->check($integration);
        $redirect      = $this->redirects->check($integration);
        $schema        = $integration->formId() !== '' ? $this->schemas->stored($integration->formId()) : null;
        $schemaMeta    = $integration->formId() !== '' ? $this->schemas->meta($integration->formId()) : null;
        $schemaStale   = $integration->formId() !== '' && $this->schemas->isStale($integration->formId());
        // One record, not a list: the editor names the form this integration
        // points at and knows nothing about any other form on the account.
        $connectedForm = $integration->formId() !== '' ? $this->forms->get($integration->formId()) : null;
        $templates     = $this->templates->choices();
        $canTest       = $this->tests !== null;

        // Offered whenever there is a schema to build it from, in either
        // rendering mode: an auto-rendered integration moving to a template is
        // exactly when this is most useful.
        $scaffold     = $schema !== null ? (new TemplateScaffold())->build($integration, $schema) : '';
        $scaffoldFile = $schema !== null ? (new TemplateScaffold())->fileName($integration) : '';
        $notice       = $flash;
        $page         = self::MENU_SLUG;

        require __DIR__ . '/views/integration-edit.php';
    }

    public function handleSave(): void
    {
        $this->guard(self::ACTION_SAVE);

        // The nonce and the capability are checked by guard() above, and every
        // value is sanitized field by field in Integration::fromInput().
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

        $integration = Integration::fromInput($raw);
        $errors      = [];

        // The template select is populated from the registry, so anything else
        // is either a stale form or a forged request.
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

        // Saving never fetches. A form nobody has synced yet is reported as
        // exactly that, so the one action that talks to Jotform stays the one
        // the administrator pressed on purpose.
        $warnings = [];

        // An unconnected form ID is a warning rather than a refusal. The field
        // is free text now, and refusing to store digits the administrator
        // typed — because a button beside them was not pressed — would make the
        // form feel broken. Nothing can go quietly wrong either way: an
        // integration whose schema was never synced does not render at all, and
        // the schema is the next warning down.
        if ($integration->formId() !== '' && !$this->forms->has($integration->formId())) {
            $warnings[] = __(
                'This Jotform form has not been connected yet. Press Connect form to check that it exists and to read its title.',
                'jotform-bridge'
            );
        }

        if (!$this->schemas->isSynced($integration->formId())) {
            $warnings[] = __(
                'This form has not been synced yet. Press Sync Schema to load its definition from Jotform — the form cannot render until you do.',
                'jotform-bridge'
            );
        }

        // A broken redirect target does not stop the save — the page may be
        // published later — but the admin is told before a visitor finds out.
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
            // The store holds the forms integrations use, so a form the last
            // integration referencing it has just taken with it has nothing
            // left to name. The schema is left alone: it is a manual sync the
            // administrator paid for, and a new integration on the same form
            // should not have to pay for it again.
            if ($formId !== '' && !$this->isFormInUse($formId)) {
                $this->forms->forget($formId);
            }

            $this->flash('success', __('Integration deleted.', 'jotform-bridge'));
        } else {
            $this->flash('error', __('That integration does not exist.', 'jotform-bridge'));
        }

        $this->redirect([]);
    }

    /**
     * The only action in the plugin that fetches a form definition.
     *
     * It is per integration on purpose: syncing one form is a decision about
     * one contract between a template and a Jotform form, and a site with ten
     * integrations should never have nine of them move because somebody wanted
     * the tenth updated.
     */
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

    /**
     * Sends one real submission to Jotform and reports exactly what came back.
     *
     * The only action in the plugin that writes to the account. It is not a
     * simulation on purpose: the failures worth catching — a form ID pointing
     * elsewhere, a key without write access, a field Jotform has since made
     * required — are invisible until something is actually sent.
     */
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
            // The upstream message goes straight through: reading it is the
            // entire reason for pressing the button.
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
     * Resolves one Jotform form ID into a title, and stores the result.
     *
     * The one place the plugin asks Jotform about a form's existence. It
     * deliberately cannot say *why* a lookup failed: Jotform answers 401 with
     * the same message for a form that does not exist, a form owned by another
     * account and an API key that is wrong, so the reply names all three and
     * points at Check Connection, which is the only thing that separates the
     * last of them from the first two.
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

        $form = $response->data();

        wp_send_json_success(
            [
                'id'      => (string) $form['id'],
                'title'   => (string) $form['title'],
                'status'  => (string) $form['status'],
                'message' => sprintf(
                    /* translators: 1: Jotform form title, 2: Jotform form status, e.g. ENABLED */
                    __('Connected: %1$s (%2$s)', 'jotform-bridge'),
                    (string) $form['title'] !== ''
                        ? (string) $form['title']
                        : __('untitled form', 'jotform-bridge'),
                    (string) $form['status']
                ),
                'hint'    => strtoupper((string) $form['status']) === FormRepository::STATUS_DELETED
                    ? __('This form is in the Jotform trash. It will not accept submissions until it is restored.', 'jotform-bridge')
                    : __('Press Sync Schema below to load its fields, then save the integration.', 'jotform-bridge'),
            ]
        );
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
     * Where an action issued from the editor should return to.
     *
     * A form that posts `return_view` decides; anything else lands on the list,
     * which is where the per-row buttons live.
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

    /**
     * A timestamp in the site's own date format and timezone.
     *
     * Both tables on the list screen print one, and they have to agree with the
     * rest of the admin rather than invent a format of their own. Called from
     * the view, which is included inside a method of this class and therefore
     * shares its scope.
     */
    private function formatDateTime(int $timestamp): string
    {
        $date = (string) get_option('date_format', 'Y-m-d');
        $time = (string) get_option('time_format', 'H:i');

        return (string) wp_date(trim($date . ' ' . $time), $timestamp);
    }

    /**
     * Whether any stored integration still points at this Jotform form.
     *
     * Several integrations sharing one form is a requirement, not an accident,
     * so deleting one of them must not take the shared record with it.
     */
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
     * Answers the request and ends it. Every guard clause on this screen leans
     * on that: the code after one reads its value as still set, because a
     * missing one has already left the process.
     *
     * `never` is a docblock rather than a native return type because the plugin
     * supports PHP 8.0, where the type does not exist.
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
