<?php
/**
 * Plugin Name:       Jotform Bridge
 * Plugin URI:        https://github.com/suborgerry/JotForm-Bridge
 * Description:       Uses Jotform as a headless form backend for WordPress: custom markup on the site, submissions and data storage on Jotform.
 * Version:           2.0.15
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Jotform Bridge
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jotform-bridge
 * Domain Path:       /languages
 * Update URI:        https://github.com/suborgerry/JotForm-Bridge
 *
 * @package JotformBridge
 */

declare(strict_types=1);

namespace JotformBridge;

if (!defined('ABSPATH')) {
    exit;
}

if (defined('JOTFORM_BRIDGE_VERSION')) {
    return;
}

// The version lives in the header above only; everything else reads it from here.
define('JOTFORM_BRIDGE_VERSION', (string) get_file_data(__FILE__, ['Version' => 'Version'])['Version']);
define('JOTFORM_BRIDGE_FILE', __FILE__);
define('JOTFORM_BRIDGE_DIR', plugin_dir_path(__FILE__));
define('JOTFORM_BRIDGE_URL', plugin_dir_url(__FILE__));
define('JOTFORM_BRIDGE_MIN_PHP', '8.0');
define('JOTFORM_BRIDGE_MIN_WP', '6.4');

/** Admin notice shown when the environment is too old. */
function jotform_bridge_requirements_notice(string $message): void
{
    add_action(
        'admin_notices',
        static function () use ($message): void {
            if (!current_user_can('activate_plugins')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html($message)
            );
        }
    );
}

if (version_compare(PHP_VERSION, JOTFORM_BRIDGE_MIN_PHP, '<')) {
    jotform_bridge_requirements_notice(
        sprintf(
            /* translators: 1: required PHP version, 2: current PHP version */
            __('Jotform Bridge requires PHP %1$s or newer. This site runs PHP %2$s.', 'jotform-bridge'),
            JOTFORM_BRIDGE_MIN_PHP,
            PHP_VERSION
        )
    );

    return;
}

if (version_compare(get_bloginfo('version'), JOTFORM_BRIDGE_MIN_WP, '<')) {
    jotform_bridge_requirements_notice(
        sprintf(
            /* translators: 1: required WordPress version, 2: current WordPress version */
            __('Jotform Bridge requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'jotform-bridge'),
            JOTFORM_BRIDGE_MIN_WP,
            get_bloginfo('version')
        )
    );

    return;
}

require_once __DIR__ . '/src/Autoloader.php';

Autoloader::register(__NAMESPACE__ . '\\', __DIR__ . '/src');

require_once __DIR__ . '/src/api.php';

register_activation_hook(__FILE__, [Plugin::class, 'onActivate']);
register_deactivation_hook(__FILE__, [Plugin::class, 'onDeactivate']);

add_action(
    'plugins_loaded',
    static function (): void {
        Plugin::instance()->boot();
    }
);
