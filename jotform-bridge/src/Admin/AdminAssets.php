<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/** Enqueues the admin stylesheet and script, on this plugin's screens only. */
final class AdminAssets
{
    public const STYLE_HANDLE  = 'jotform-bridge-admin';
    public const SCRIPT_HANDLE = 'jotform-bridge-admin';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * @param string $hook Screen hook suffix WordPress passes to the action.
     */
    public function enqueue(string $hook = ''): void
    {
        if (!self::isPluginScreen($hook)) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            JOTFORM_BRIDGE_URL . 'assets/admin.css',
            [],
            JOTFORM_BRIDGE_VERSION
        );

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            JOTFORM_BRIDGE_URL . 'assets/admin.js',
            [],
            JOTFORM_BRIDGE_VERSION,
            true
        );

        wp_localize_script(
            self::SCRIPT_HANDLE,
            'jotformBridgeAdmin',
            [
                'messages' => [
                    'copied'      => __('Copied to clipboard.', 'jotform-bridge'),
                    'copyFailed'  => __('Could not copy. Select the text and copy it by hand.', 'jotform-bridge'),
                    'unreachable' => __('Could not reach WordPress to check the form ID.', 'jotform-bridge'),
                ],
            ]
        );
    }

    /** Whether the screen belongs to this plugin; the hook suffix first, get_current_screen() without one. */
    private static function isPluginScreen(string $hook): bool
    {
        if ($hook !== '' && strpos($hook, IntegrationsPage::MENU_SLUG) !== false) {
            return true;
        }

        if ($hook !== '') {
            return false;
        }

        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen !== null && strpos((string) $screen->id, IntegrationsPage::MENU_SLUG) !== false;
    }
}
