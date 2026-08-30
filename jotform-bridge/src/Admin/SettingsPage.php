<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Api\ConnectionState;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Jotform Bridge → Settings" admin screen and its actions.
 *
 * The screen only reads stored state; Jotform is contacted from the explicit
 * Sync with Jotform action and from nowhere else.
 */
final class SettingsPage
{
    public const MENU_SLUG  = 'jotform-bridge-settings';
    public const CAPABILITY = 'manage_options';

    public const ACTION_SAVE    = 'jotform_bridge_save_settings';
    public const ACTION_REFRESH = 'jotform_bridge_refresh_forms';
    public const ACTION_REMOVE  = 'jotform_bridge_remove_form';

    private const NOTICE_ARG = 'jfb_notice';

    private Settings $settings;

    private JotformClient $client;

    private FormRepository $forms;

    private ConnectionState $connection;

    public function __construct(
        Settings $settings,
        JotformClient $client,
        FormRepository $forms,
        ConnectionState $connection
    ) {
        $this->settings   = $settings;
        $this->client     = $client;
        $this->forms      = $forms;
        $this->connection = $connection;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_REFRESH, [$this, 'handleRefreshForms']);
        add_action('admin_post_' . self::ACTION_REMOVE, [$this, 'handleRemoveForm']);
    }

    /**
     * The top-level menu belongs to IntegrationsPage; Settings is its sibling.
     */
    public function registerMenu(): void
    {
        add_submenu_page(
            IntegrationsPage::MENU_SLUG,
            __('Settings', 'jotform-bridge'),
            __('Settings', 'jotform-bridge'),
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

        $settings   = $this->settings;
        $connection = $this->connection->get();
        $forms      = $this->forms->all();
        $formsMeta  = $this->forms->meta();
        $notice     = $this->currentNotice();

        require __DIR__ . '/views/settings-page.php';
    }

    public function handleSave(): void
    {
        $this->guard(self::ACTION_SAVE);

        // The nonce and the capability are checked by guard() above, and every
        // value is sanitized by Settings::save().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $raw = isset($_POST['jotform_bridge']) && is_array($_POST['jotform_bridge'])
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitized in save().
            ? wp_unslash($_POST['jotform_bridge'])
            : [];

        $this->settings->save($raw);

        // Region or key changes invalidate what we know about the account.
        $this->connection->reset();
        $this->forms->flush();

        $this->redirect('saved');
    }

    /**
     * The one action on this screen that contacts Jotform.
     *
     * It answers both questions a site owner has here — "is the key working?"
     * and "what forms are on the account?" — because they were never separate
     * in practice: a connection test that is not followed by a sync tells you
     * nothing you can act on, and a sync that fails is a failed connection test
     * with extra steps. It used to be two buttons, and the second one silently
     * depended on the first having been pressed at some point: the account name
     * shown on this screen came only from the connection test.
     *
     * `GET /user` first, because it is the cheaper of the two calls and the one
     * whose failure explains the other.
     */
    public function handleRefreshForms(): void
    {
        $this->guard(self::ACTION_REFRESH);

        if (!$this->settings->hasApiKey()) {
            $this->connection->recordFailure(__('No API key configured.', 'jotform-bridge'));
            $this->redirect('no_key');
        }

        $account = $this->client->testConnection();

        if (!$account->isSuccess()) {
            $this->connection->recordFailure($account->errorMessage());
            $this->redirect('connection_failed');
        }

        $response = $this->forms->refresh();

        if (!$response->isSuccess()) {
            $this->connection->recordFailure($response->errorMessage());
            $this->redirect('forms_failed');
        }

        $data = $account->data();

        $this->connection->recordSuccess(isset($data['username']) ? (string) $data['username'] : '');
        $this->redirect('forms_refreshed');
    }

    /**
     * Drops one form Jotform reports as deleted from the stored list.
     *
     * Nothing is sent to Jotform: the form is already in the account trash, and
     * this only stops the row from following the site around forever.
     */
    public function handleRemoveForm(): void
    {
        $this->guard(self::ACTION_REMOVE);

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $formId = isset($_POST['form_id']) ? sanitize_text_field(wp_unslash((string) $_POST['form_id'])) : '';

        if (!$this->forms->hide($formId)) {
            $this->redirect('form_not_removed');
        }

        $this->redirect('form_removed');
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

    private function redirect(string $notice): void
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page'           => self::MENU_SLUG,
                    self::NOTICE_ARG => $notice,
                ],
                admin_url('admin.php')
            )
        );

        exit;
    }

    /**
     * @return array{type:string, message:string}|null
     */
    private function currentNotice(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag.
        $code = isset($_GET[self::NOTICE_ARG]) ? sanitize_key((string) wp_unslash($_GET[self::NOTICE_ARG])) : '';

        if ($code === '') {
            return null;
        }

        $connection = $this->connection->get();
        $formsMeta  = $this->forms->meta();

        $notices = [
            'saved' => [
                'type'    => 'success',
                'message' => __('Settings saved.', 'jotform-bridge'),
            ],
            'connection_failed' => [
                'type'    => 'error',
                'message' => $connection['message'] !== ''
                    ? $connection['message']
                    : __('Connection to Jotform failed.', 'jotform-bridge'),
            ],
            'forms_refreshed' => [
                'type'    => 'success',
                'message' => $connection['account'] !== ''
                    ? sprintf(
                        /* translators: 1: number of forms, 2: Jotform account username */
                        _n(
                            '%1$d form loaded from Jotform, connected as %2$s.',
                            '%1$d forms loaded from Jotform, connected as %2$s.',
                            $formsMeta['count'],
                            'jotform-bridge'
                        ),
                        $formsMeta['count'],
                        $connection['account']
                    )
                    : sprintf(
                        /* translators: %d: number of forms */
                        _n(
                            '%d form loaded from Jotform.',
                            '%d forms loaded from Jotform.',
                            $formsMeta['count'],
                            'jotform-bridge'
                        ),
                        $formsMeta['count']
                    ),
            ],
            'forms_failed' => [
                'type'    => 'error',
                'message' => $formsMeta['error'] !== ''
                    ? $formsMeta['error']
                    : __('Could not load the form list from Jotform.', 'jotform-bridge'),
            ],
            'form_removed' => [
                'type'    => 'success',
                'message' => __(
                    'Form removed from the list. It stays in the Jotform trash, and comes back here only if it is restored there.',
                    'jotform-bridge'
                ),
            ],
            'form_not_removed' => [
                'type'    => 'error',
                'message' => __(
                    'Only a form Jotform reports as deleted can be removed from this list.',
                    'jotform-bridge'
                ),
            ],
            'no_key' => [
                'type'    => 'error',
                'message' => __(
                    'Set the JOTFORM_API_KEY constant in wp-config.php before using this action.',
                    'jotform-bridge'
                ),
            ],
        ];

        return $notices[$code] ?? null;
    }
}
