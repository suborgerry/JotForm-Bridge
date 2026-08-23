<?php

/**
 * Uninstall cleanup.
 *
 * Everything derived from Jotform — the synced schemas, the account form list,
 * the template registry — is always removed: it is worthless once the plugin is
 * gone, and re-syncing it by hand is exactly one click per integration.
 *
 * Configuration is a different matter: integrations and settings
 * are work somebody did by hand, and deleting them on uninstall would destroy it
 * silently, including on the "deactivate, delete, reinstall" round trip people
 * use to fix a broken update. It is therefore kept unless the administrator
 * explicitly asked for a full removal on the settings screen.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// One schema option per form; the meta option is the only index of them. The
// transient of the same name is what versions up to 0.1.0 used.
$jfbSchemaMeta = get_option('jotform_bridge_schema_meta', []);

if (is_array($jfbSchemaMeta)) {
    foreach (array_keys($jfbSchemaMeta) as $jfbFormId) {
        delete_option('jotform_bridge_schema_' . (string) $jfbFormId);
        delete_transient('jotform_bridge_schema_' . (string) $jfbFormId);
    }
}

delete_option('jotform_bridge_forms');
delete_transient('jotform_bridge_forms');

delete_option('jotform_bridge_forms_meta');
delete_option('jotform_bridge_schema_meta');
delete_option('jotform_bridge_templates');
delete_option('jotform_bridge_connection');
delete_option('jotform_bridge_quota');
delete_option('jotform_bridge_stats');
delete_option('jotform_bridge_version');

$jfbSettings = get_option('jotform_bridge_settings', []);

if (is_array($jfbSettings) && !empty($jfbSettings['delete_data_on_uninstall'])) {
    delete_option('jotform_bridge_integrations');
    delete_option('jotform_bridge_settings');
}
