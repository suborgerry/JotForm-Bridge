<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Plugin;
use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/** Tells an administrator how to supply the API key when the constant is missing. */
final class ApiKeyNotice
{
    public const CAPABILITY = Plugin::CAPABILITY;

    /** The line to paste into wp-config.php. */
    public const SNIPPET = "define( 'JOTFORM_API_KEY', 'your-api-key' );";

    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!$this->shouldRender()) {
            return;
        }

        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> %s</p><p><code>%s</code></p><p><a href="%s">%s</a></p></div>',
            esc_html__('Jotform Bridge is not connected.', 'jotform-bridge'),
            esc_html__(
                'Add this line to wp-config.php, above the "That\'s all, stop editing!" comment:',
                'jotform-bridge'
            ),
            esc_html(self::SNIPPET),
            esc_url(admin_url('admin.php?page=' . SettingsPage::MENU_SLUG)),
            esc_html__('Jotform Bridge settings', 'jotform-bridge')
        );
    }

    /** On the plugin's screens and the plugin list; not on Settings, which has its own row. */
    private function shouldRender(): bool
    {
        if ($this->settings->hasApiKey() || !current_user_can(self::CAPABILITY)) {
            return false;
        }

        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        if ($screen === null) {
            return false;
        }

        $id = (string) $screen->id;

        if (str_ends_with($id, SettingsPage::MENU_SLUG)) {
            return false;
        }

        return $id === 'plugins' || strpos($id, IntegrationsPage::MENU_SLUG) !== false;
    }
}
