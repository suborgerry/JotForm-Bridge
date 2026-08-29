<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the stylesheet and script the admin screens use.
 *
 * Both used to be printed inline into the views. That could not be cached, could
 * not be minified, made the markup harder to read than the behaviour it carried,
 * and — the reason it had to change — is refused outright by any site running a
 * content security policy, which would have left the admin screens silently
 * half-working.
 *
 * Enqueued only on this plugin's own screens: an admin loading two extra files
 * on every page of somebody else's plugin is exactly the behaviour that makes
 * WordPress admins slow.
 */
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
    }

    /**
     * Whether the screen being rendered belongs to this plugin.
     *
     * The hook suffix is the reliable signal — it is derived from the menu slug
     * — and get_current_screen() is only consulted when the action was called
     * without one.
     */
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
