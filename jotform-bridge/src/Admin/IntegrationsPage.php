<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Submission\TestSubmission;
use JotformBridge\Support\Features;
use JotformBridge\Support\Stats;
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
 */
final class IntegrationsPage
{
    public const MENU_SLUG  = 'jotform-bridge';
    public const CAPABILITY = 'manage_options';

    public const ACTION_SAVE    = 'jotform_bridge_save_integration';
    public const ACTION_DELETE  = 'jotform_bridge_delete_integration';
    public const ACTION_TOGGLE  = 'jotform_bridge_toggle_integration';
    public const ACTION_SYNC    = 'jotform_bridge_sync_schema';
    public const ACTION_TEST    = 'jotform_bridge_test_submission';

    private const FLASH_PREFIX = 'jotform_bridge_notice_';

    private IntegrationRepository $integrations;

    private FormRepository $forms;

    private SchemaRepository $schemas;

    private TemplateRegistry $templates;

    private CompatibilityChecker $compatibility;

    private RedirectTarget $redirects;

    private Stats $stats;

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
        ?RedirectTarget $redirects = null,
        ?Stats $stats = null,
        ?TestSubmission $tests = null
    ) {
        $this->integrations  = $integrations;
        $this->forms         = $forms;
        $this->schemas       = $schemas;
        $this->templates     = $templates;
        $this->compatibility = $compatibility;
        $this->redirects     = $redirects ?? new RedirectTarget();
        $this->stats         = $stats ?? new Stats();
        $this->tests         = $tests;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_DELETE, [$this, 'handleDelete']);
        add_action('admin_post_' . self::ACTION_TOGGLE, [$this, 'handleToggle']);
        add_action('admin_post_' . self::ACTION_SYNC, [$this, 'handleSyncSchema']);

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

    /**
     * Brand mark for the top-level menu, inlined as a data URI.
     *
     * Falls back to a Dashicon if the asset is missing.
     */
    private function menuIcon(): string
    {
        $path = JOTFORM_BRIDGE_DIR . 'assets/menu-icon.svg';
        $svg  = is_readable($path) ? file_get_contents($path) : false;

        if ($svg === false || $svg === '') {
            return 'dashicons-feedback';
        }

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
        $showStats    = Features::enabled(Features::STATS_UI);
        $rows         = [];

        foreach ($integrations as $integration) {
            $rows[$integration->slug()] = [
                'integration'   => $integration,
                'compatibility' => $this->compatibility->check($integration),
                'redirect'      => $this->redirects->check($integration),
                'form_title'    => $this->formTitle($integration->formId()),
                'schema_meta'   => $integration->formId() !== ''
                    ? $this->schemas->meta($integration->formId())
                    : null,
                'schema_synced' => $integration->formId() !== ''
                    && $this->schemas->isSynced($integration->formId()),
                'schema_stale'  => $integration->formId() !== ''
                    && $this->schemas->isStale($integration->formId()),
                // Counting never stops; only the reading of it is optional.
                'stats'         => $showStats ? $this->stats->summary($integration->slug(), 7) : null,
                'health'        => $showStats ? $this->stats->health($integration->slug()) : '',
                'last_ok'       => $showStats ? $this->stats->lastSuccess($integration->slug()) : 0,
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
        $redirect      = $this->redirects->check($integration);
        $schema        = $integration->formId() !== '' ? $this->schemas->stored($integration->formId()) : null;
        $schemaMeta    = $integration->formId() !== '' ? $this->schemas->meta($integration->formId()) : null;
        $schemaStale   = $integration->formId() !== '' && $this->schemas->isStale($integration->formId());
        $forms         = $this->forms->all();
        $templates     = $this->templates->choices();
        $canTest       = $this->tests !== null;

        // Offered whenever there is a schema to build it from, in either
        // rendering mode: an auto-rendered integration moving to a template is
        // exactly when this is most useful.
        $scaffold     = $schema !== null ? (new TemplateScaffold())->build($integration, $schema) : '';
        $scaffoldFile = $schema !== null ? (new TemplateScaffold())->fileName($integration) : '';
        $showStats     = Features::enabled(Features::STATS_UI) && $integration->slug() !== '';
        $stats         = $showStats ? $this->stats->summary($integration->slug(), 7) : null;
        $statsToday    = $showStats ? $this->stats->summary($integration->slug(), 1) : null;
        $statsFields   = $showStats ? $this->stats->fieldErrors($integration->slug(), 7) : [];
        $statsHealth   = $showStats ? $this->stats->health($integration->slug()) : 'idle';
        $statsLastOk   = $showStats ? $this->stats->lastSuccess($integration->slug()) : 0;
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
            $errors[] = __('The selected Jotform form is not in the stored account form list. Refresh the form list on the settings screen.', 'jotform-bridge');
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

        if ($this->integrations->delete($slug)) {
            // The tally describes an integration that no longer exists.
            $this->stats->forget($slug);

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
