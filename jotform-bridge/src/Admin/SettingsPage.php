<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Api\ConnectionState;
use JotformBridge\Api\JotformClient;
use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "Jotform Bridge → Settings" admin screen and its actions.
 *
 * The screen only reads stored state; Jotform is contacted from the explicit
 * Check Connection action and from nowhere else. It shows no list of forms:
 * the plugin never asks what forms an account has, and a form is connected by
 * its ID on the integration that uses it.
 */
final class SettingsPage
{
    public const MENU_SLUG  = 'jotform-bridge-settings';
    public const CAPABILITY = 'manage_options';

    public const ACTION_SAVE  = 'jotform_bridge_save_settings';
    public const ACTION_CHECK = 'jotform_bridge_check_connection';

    private const NOTICE_ARG = 'jfb_notice';

    private Settings $settings;

    private JotformClient $client;

    private ConnectionState $connection;

    public function __construct(
        Settings $settings,
        JotformClient $client,
        ConnectionState $connection
    ) {
        $this->settings   = $settings;
        $this->client     = $client;
        $this->connection = $connection;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_post_' . self::ACTION_SAVE, [$this, 'handleSave']);
        add_action('admin_post_' . self::ACTION_CHECK, [$this, 'handleCheckConnection']);
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

        // A region change can point the plugin at a different account, so what
        // was learned from GET /user no longer stands. The connected-form
        // records are left alone on purpose: each one is a title for an ID an
        // integration already holds, re-read by one press of Connect form, and
        // wiping them would blank every integration's label because somebody
        // toggled debug logging.
        $this->connection->reset();

        $this->redirect('saved');
    }

    /**
     * The one action on this screen that contacts Jotform: GET /user.
     *
     * It used to reload the account form list as well, under the name "Sync
     * with Jotform". The list is gone — forms are connected one at a time in
     * the integration editor — but the key check emphatically is not, and this
     * is the reason it kept a button of its own rather than being folded into
     * Connect form.
     *
     * Jotform answers a bad form ID, somebody else's form and a wrong API key
     * with the identical 401 "You're not authorized to use (/form-id)". So a
     * failing Connect form cannot tell a site owner which of the three they are
     * looking at. GET /user can: it does not mention a form, so if it succeeds
     * the key is good and the ID is the problem. Without this button that
     * distinction is unavailable anywhere in the plugin.
     */
    public function handleCheckConnection(): void
    {
        $this->guard(self::ACTION_CHECK);

        if (!$this->settings->hasApiKey()) {
            $this->connection->recordFailure(__('No API key configured.', 'jotform-bridge'));
            $this->redirect('no_key');
        }

        $account = $this->client->testConnection();

        if (!$account->isSuccess()) {
            $this->connection->recordFailure($account->errorMessage());
            $this->redirect('connection_failed');
        }

        $data = $account->data();

        $this->connection->recordSuccess(isset($data['username']) ? (string) $data['username'] : '');
        $this->redirect('connected');
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
            'connected' => [
                'type'    => 'success',
                'message' => $connection['account'] !== ''
                    ? sprintf(
                        /* translators: %s: Jotform account username */
                        __('Connected to Jotform as %s.', 'jotform-bridge'),
                        $connection['account']
                    )
                    : __('Connected to Jotform.', 'jotform-bridge'),
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
