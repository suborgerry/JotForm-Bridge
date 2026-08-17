<?php

/**
 * Removes every option and transient the plugin created.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('jotform_bridge_settings');
delete_option('jotform_bridge_connection');
delete_option('jotform_bridge_forms_meta');
delete_transient('jotform_bridge_forms');
