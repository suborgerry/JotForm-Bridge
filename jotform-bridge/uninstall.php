<?php

/**
 * Uninstall cleanup. Derived state is always removed; integrations and
 * settings only when "delete data on uninstall" was enabled.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/** Removes everything this plugin stored on one site; run per blog on a network. */
$jfbCleanUpSite = static function (): void {
    // The meta option is the index of the per-form schema options.
    $jfbSchemaMeta = get_option('jotform_bridge_schema_meta', []);

    if (is_array($jfbSchemaMeta)) {
        foreach (array_keys($jfbSchemaMeta) as $jfbFormId) {
            delete_option('jotform_bridge_schema_' . (string) $jfbFormId);
            delete_transient('jotform_bridge_schema_' . (string) $jfbFormId);
        }
    }

    delete_option('jotform_bridge_connected_forms');

    // Legacy account form list.
    delete_option('jotform_bridge_forms');
    delete_transient('jotform_bridge_forms');
    delete_option('jotform_bridge_forms_meta');
    delete_option('jotform_bridge_forms_hidden');

    delete_option('jotform_bridge_schema_meta');
    delete_option('jotform_bridge_templates');
    delete_option('jotform_bridge_connection');
    delete_option('jotform_bridge_quota');

    // Legacy submission tally.
    delete_option('jotform_bridge_stats');
    delete_option('jotform_bridge_version');

    delete_site_transient('jotform_bridge_update_check');

    $jfbSettings = get_option('jotform_bridge_settings', []);

    if (is_array($jfbSettings) && !empty($jfbSettings['delete_data_on_uninstall'])) {
        delete_option('jotform_bridge_integrations');
        delete_option('jotform_bridge_settings');
    }
};

if (is_multisite()) {
    $jfbSites = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach (is_array($jfbSites) ? $jfbSites : [] as $jfbSiteId) {
        switch_to_blog((int) $jfbSiteId);

        $jfbCleanUpSite();

        restore_current_blog();
    }
} else {
    $jfbCleanUpSite();
}
