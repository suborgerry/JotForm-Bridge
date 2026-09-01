<?php

/**
 * Settings screen markup.
 *
 * @var \JotformBridge\Settings\Settings                  $settings
 * @var array{status:string, checked_at:int, account:string, message:string} $connection
 * @var array{type:string, message:string}|null           $notice
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Admin\ApiKeyNotice;
use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$jfbHasKey = $settings->hasApiKey();
$jfbRegion = $settings->region();
$jfbAll    = $settings->all();
?>
<div class="wrap jfb-settings">
    <h1><?php echo esc_html__('Jotform Bridge', 'jotform-bridge'); ?></h1>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_SAVE); ?>">
        <?php wp_nonce_field(SettingsPage::ACTION_SAVE); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php echo esc_html__('API Key', 'jotform-bridge'); ?></th>
                <td>
                    <?php if ($jfbHasKey) : ?>
                        <p><code><?php echo esc_html($settings->maskedApiKey()); ?></code></p>
                    <?php else : ?>
                        <p>
                            <strong><?php echo esc_html__('No API key configured.', 'jotform-bridge'); ?></strong>
                        </p>
                        <p class="description">
                            <?php
                            echo esc_html__(
                                'Add this line to wp-config.php, above the "That\'s all, stop editing!" comment, then reload this page:',
                                'jotform-bridge'
                            );
                            ?>
                        </p>
                        <p><code><?php echo esc_html(ApiKeyNotice::SNIPPET); ?></code></p>
                        <p class="description">
                            <?php
                            echo esc_html__(
                                'Create the key in your Jotform account under Settings → API. The plugin reads it from the constant only, so it is never stored in the database.',
                                'jotform-bridge'
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-region"><?php echo esc_html__('API Region', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <select
                        id="jfb-region"
                        name="jotform_bridge[region]"
                        data-jfb-toggle=".jfb-custom-base-url-field"
                        data-jfb-toggle-value="<?php echo esc_attr(Settings::REGION_CUSTOM); ?>"
                    >
                        <?php foreach (Settings::regions() as $jfbSlug => $jfbLabel) : ?>
                            <option
                                value="<?php echo esc_attr($jfbSlug); ?>"
                                <?php selected($jfbRegion, $jfbSlug); ?>
                            >
                                <?php echo esc_html($jfbLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description">
                        <?php
                        printf(
                            /* translators: %s: currently effective API base URL */
                            esc_html__('Current base URL: %s', 'jotform-bridge'),
                            '<code>' . esc_html($settings->baseUrl()) . '</code>'
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr class="jfb-custom-base-url-field">
                <th scope="row">
                    <label for="jfb-base-url"><?php echo esc_html__('Custom Base URL', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <input
                        type="url"
                        class="regular-text code"
                        id="jfb-base-url"
                        name="jotform_bridge[base_url]"
                        value="<?php echo esc_attr((string) $jfbAll['base_url']); ?>"
                        placeholder="<?php echo esc_attr(Settings::regionUrl(Settings::REGION_STANDARD)); ?>"
                    >
                    <p class="description">
                        <?php
                        echo esc_html__(
                            'Used only when the region is set to "Custom base URL". Must be a jotform.com address: the API key travels to whatever is entered here, so other hosts are refused. If you genuinely need one, add it with the jotform_bridge_allowed_api_hosts filter.',
                            'jotform-bridge'
                        );
                        ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Debug Logging', 'jotform-bridge'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="jotform_bridge[debug_logging]"
                            value="1"
                            <?php checked($settings->debugEnabled()); ?>
                        >
                        <?php echo esc_html__('Log Jotform API failures to the PHP error log', 'jotform-bridge'); ?>
                    </label>
                </td>
            </tr>

            <tr>
                <th scope="row"><?php echo esc_html__('Uninstall', 'jotform-bridge'); ?></th>
                <td>
                    <label>
                        <input
                            type="checkbox"
                            name="jotform_bridge[delete_data_on_uninstall]"
                            value="1"
                            <?php checked($settings->deletesDataOnUninstall()); ?>
                        >
                        <?php
                        echo esc_html__(
                            'Delete the integrations and the settings when the plugin is deleted',
                            'jotform-bridge'
                        );
                        ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'jotform-bridge')); ?>
    </form>

    <h2><?php echo esc_html__('Connection', 'jotform-bridge'); ?></h2>
    <p>
        <?php
        if (!$jfbHasKey) {
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Not configured.', 'jotform-bridge'),
                esc_html__('Set the JOTFORM_API_KEY constant in wp-config.php; see above.', 'jotform-bridge')
            );
        } elseif ($connection['status'] === ConnectionState::STATUS_CONNECTED) {
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Connected.', 'jotform-bridge'),
                esc_html(
                    $connection['account'] !== ''
                        ? sprintf(
                            /* translators: %s: Jotform account username */
                            __('Jotform account: %s', 'jotform-bridge'),
                            $connection['account']
                        )
                        : ''
                )
            );
        } elseif ($connection['status'] === ConnectionState::STATUS_FAILED) {
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Connection failed.', 'jotform-bridge'),
                esc_html($connection['message'])
            );
        } else {
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Not checked yet.', 'jotform-bridge'),
                esc_html__('Press Check Connection to see whether the key works.', 'jotform-bridge')
            );
        }
        ?>
    </p>
    <?php if ($connection['checked_at'] > 0) : ?>
        <p class="description">
            <?php
            printf(
                /* translators: %s: human readable time difference, e.g. "5 mins" */
                esc_html__('Last checked %s ago.', 'jotform-bridge'),
                esc_html(human_time_diff($connection['checked_at'], time()))
            );
            ?>
        </p>
    <?php endif; ?>
    <div class="jfb-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="jfb-action-form">
            <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_CHECK); ?>">
            <?php wp_nonce_field(SettingsPage::ACTION_CHECK); ?>
            <?php submit_button(__('Check Connection', 'jotform-bridge'), 'primary', 'submit', false); ?>
        </form>
        <p class="description">
            <?php
            echo esc_html__(
                'Asks Jotform who the API key belongs to, and nothing else. Nothing on this screen contacts Jotform on its own.',
                'jotform-bridge'
            );
            ?>
        </p>
        <p class="description">
            <?php
            echo esc_html__(
                'Worth pressing when Connect form refuses a form ID: Jotform answers a wrong ID, another account\'s form and a bad API key identically, and this is what tells the key apart from the other two.',
                'jotform-bridge'
            );
            ?>
        </p>
    </div>

    <h2><?php echo esc_html__('Jotform Forms', 'jotform-bridge'); ?></h2>
    <p class="description">
        <?php
        echo esc_html__(
            'The plugin does not keep a list of the forms on your account, and never asks Jotform for one. A form is connected by its ID on the integration that uses it, under Jotform Bridge → Integrations.',
            'jotform-bridge'
        );
        ?>
    </p>
</div>
