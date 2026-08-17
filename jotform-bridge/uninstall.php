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

// One schema transient per form; the meta option is the only index of them.
$jfbSchemaMeta = get_option('jotform_bridge_schema_meta', []);

if (is_array($jfbSchemaMeta)) {
    foreach (array_keys($jfbSchemaMeta) as $jfbFormId) {
        delete_transient('jotform_bridge_schema_' . (string) $jfbFormId);
    }
}

delete_option('jotform_bridge_settings');
delete_option('jotform_bridge_connection');
delete_option('jotform_bridge_forms_meta');
delete_option('jotform_bridge_schema_meta');
delete_option('jotform_bridge_integrations');
delete_option('jotform_bridge_templates');
delete_transient('jotform_bridge_forms');
