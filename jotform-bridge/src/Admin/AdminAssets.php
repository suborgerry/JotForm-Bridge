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

        // Everything the script says out loud. It used to carry one English
        // sentence in its source, which no `.po` file could ever reach, and it
        // now needs three more: a copy that succeeded and a copy that failed
        // are announced rather than only shown.
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
