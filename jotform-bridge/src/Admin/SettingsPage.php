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
 * The screen only reads cached state; Jotform is contacted from the explicit
 * Test Connection and Refresh Forms actions.
 */
final class SettingsPage
{
    public const MENU_SLUG  = 'jotform-bridge-settings';
    public const CAPABILITY = 'manage_options';

    public const ACTION_SAVE    = 'jotform_bridge_save_settings';
    public const ACTION_TEST    = 'jotform_bridge_test_connection';
    public const ACTION_REFRESH = 'jotform_bridge_refresh_forms';

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
        add_action('admin_post_' . self::ACTION_TEST, [$this, 'handleTestConnection']);
        add_action('admin_post_' . self::ACTION_REFRESH, [$this, 'handleRefreshForms']);
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
        $raw = isset($_POST['jotform_bridge']) && is_array($_POST['jotform_bridge'])
            ? wp_unslash($_POST['jotform_bridge']) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            : [];

        $this->settings->save($raw);

        // Region or key changes invalidate what we know about the account.
        $this->connection->reset();
        $this->forms->flush();

        $this->redirect('saved');
    }

    public function handleTestConnection(): void
    {
        $this->guard(self::ACTION_TEST);

        if (!$this->settings->hasApiKey()) {
            $this->connection->recordFailure(__('No API key configured.', 'jotform-bridge'));
            $this->redirect('no_key');
        }

        $response = $this->client->testConnection();

        if (!$response->isSuccess()) {
            $this->connection->recordFailure($response->errorMessage());
            $this->redirect('connection_failed');
        }

        $data = $response->data();
        $this->connection->recordSuccess(isset($data['username']) ? (string) $data['username'] : '');
        $this->redirect('connected');
    }

    public function handleRefreshForms(): void
    {
        $this->guard(self::ACTION_REFRESH);

        if (!$this->settings->hasApiKey()) {
            $this->redirect('no_key');
        }

        $response = $this->forms->refresh();

        if (!$response->isSuccess()) {
            $this->connection->recordFailure($response->errorMessage());
            $this->redirect('forms_failed');
        }

        $this->connection->recordSuccess($this->connection->get()['account']);
        $this->redirect('forms_refreshed');
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
            'connection_failed' => [
                'type'    => 'error',
                'message' => $connection['message'] !== ''
                    ? $connection['message']
                    : __('Connection to Jotform failed.', 'jotform-bridge'),
            ],
            'forms_refreshed' => [
                'type'    => 'success',
                'message' => sprintf(
                    /* translators: %d: number of forms */
                    _n('%d form loaded from Jotform.', '%d forms loaded from Jotform.', $formsMeta['count'], 'jotform-bridge'),
                    $formsMeta['count']
                ),
            ],
            'forms_failed' => [
                'type'    => 'error',
                'message' => $formsMeta['error'] !== ''
                    ? $formsMeta['error']
                    : __('Could not load the form list from Jotform.', 'jotform-bridge'),
            ],
            'no_key' => [
                'type'    => 'error',
                'message' => __('Add a Jotform API key before using this action.', 'jotform-bridge'),
            ],
        ];

        return $notices[$code] ?? null;
    }
}
