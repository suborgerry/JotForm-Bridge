<?php

/**
 * Settings screen markup.
 *
 * @var \JotformBridge\Settings\Settings                  $settings
 * @var array{status:string, checked_at:int, account:string, message:string} $connection
 * @var array<int, array<string, string>>                 $forms
 * @var array{fetched_at:int, count:int, error:string}    $formsMeta
 * @var array{type:string, message:string}|null           $notice
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

$jfbKeyLocked = $settings->isApiKeyLocked();
$jfbHasKey    = $settings->hasApiKey();
$jfbRegion    = $settings->region();
$jfbAll       = $settings->all();

$jfbFormsState = 'unavailable';
if (!$jfbHasKey) {
    $jfbFormsState = 'not_configured';
} elseif ($formsMeta['error'] !== '') {
    $jfbFormsState = 'unavailable';
} elseif ($forms !== []) {
    $jfbFormsState = 'loaded';
} else {
    $jfbFormsState = 'needs_refresh';
}
?>
<div class="wrap jfb-settings">
    <h1><?php echo esc_html__('Jotform Bridge', 'jotform-bridge'); ?></h1>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
            <p><?php echo esc_html($notice['message']); ?></p>
        </div>
    <?php endif; ?>

    <h2><?php echo esc_html__('Connection', 'jotform-bridge'); ?></h2>
    <p>
        <?php
        if (!$jfbHasKey) {
            printf(
                '<strong>%s</strong> %s',
                esc_html__('Not configured.', 'jotform-bridge'),
                esc_html__('Add a Jotform API key below.', 'jotform-bridge')
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
                esc_html__('Not tested yet.', 'jotform-bridge'),
                esc_html__('Run Test Connection to check the API key.', 'jotform-bridge')
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

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_SAVE); ?>">
        <?php wp_nonce_field(SettingsPage::ACTION_SAVE); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="jfb-api-key"><?php echo esc_html__('API Key', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <?php if ($jfbKeyLocked) : ?>
                        <p>
                            <code><?php echo esc_html($settings->maskedApiKey()); ?></code>
                        </p>
                        <p class="description">
                            <?php
                            echo esc_html__(
                                'The API key is set externally through the JOTFORM_API_KEY constant and cannot be changed here.',
                                'jotform-bridge'
                            );
                            ?>
                        </p>
                    <?php else : ?>
                        <input
                            type="password"
                            class="regular-text"
                            id="jfb-api-key"
                            name="jotform_bridge[api_key]"
                            value=""
                            autocomplete="off"
                            placeholder="<?php echo esc_attr($jfbHasKey ? $settings->maskedApiKey() : __('Paste your Jotform API key', 'jotform-bridge')); ?>"
                        >
                        <?php if ($jfbHasKey) : ?>
                            <p class="description">
                                <?php echo esc_html__('A key is stored. Leave the field empty to keep it.', 'jotform-bridge'); ?>
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="jotform_bridge[remove_api_key]" value="1">
                                    <?php echo esc_html__('Remove the stored API key', 'jotform-bridge'); ?>
                                </label>
                            </p>
                        <?php else : ?>
                            <p class="description">
                                <?php
                                echo esc_html__(
                                    'The key is stored server-side only and is never sent to the frontend.',
                                    'jotform-bridge'
                                );
                                ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="jfb-region"><?php echo esc_html__('API Region', 'jotform-bridge'); ?></label>
                </th>
                <td>
                    <select id="jfb-region" name="jotform_bridge[region]">
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

            <tr>
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
                        placeholder="https://api.jotform.com"
                    >
                    <p class="description">
                        <?php echo esc_html__('Used only when the region is set to "Custom base URL".', 'jotform-bridge'); ?>
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
                    <p class="description">
                        <?php echo esc_html__('Technical metadata only. The API key is never logged.', 'jotform-bridge'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <?php submit_button(__('Save Settings', 'jotform-bridge')); ?>
    </form>

    <h2><?php echo esc_html__('Actions', 'jotform-bridge'); ?></h2>
    <div class="jfb-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
            <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_TEST); ?>">
            <?php wp_nonce_field(SettingsPage::ACTION_TEST); ?>
            <?php submit_button(__('Test Connection', 'jotform-bridge'), 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_REFRESH); ?>">
            <?php wp_nonce_field(SettingsPage::ACTION_REFRESH); ?>
            <?php submit_button(__('Refresh Forms', 'jotform-bridge'), 'secondary', 'submit', false); ?>
        </form>
    </div>

    <h2><?php echo esc_html__('Jotform Forms', 'jotform-bridge'); ?></h2>
    <?php if ($jfbFormsState === 'not_configured') : ?>
        <p><?php echo esc_html__('Forms unavailable: no API key is configured.', 'jotform-bridge'); ?></p>
    <?php elseif ($jfbFormsState === 'unavailable') : ?>
        <p>
            <strong><?php echo esc_html__('Forms unavailable.', 'jotform-bridge'); ?></strong>
            <?php echo esc_html($formsMeta['error']); ?>
        </p>
    <?php elseif ($jfbFormsState === 'needs_refresh') : ?>
        <p><?php echo esc_html__('Form list has not been loaded yet. Use Refresh Forms.', 'jotform-bridge'); ?></p>
    <?php else : ?>
        <p class="description">
            <?php
            printf(
                /* translators: 1: number of forms, 2: human readable time difference */
                esc_html__('%1$d forms cached, loaded %2$s ago.', 'jotform-bridge'),
                (int) $formsMeta['count'],
                esc_html(human_time_diff($formsMeta['fetched_at'] > 0 ? $formsMeta['fetched_at'] : time(), time()))
            );
            ?>
        </p>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php echo esc_html__('Title', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Form ID', 'jotform-bridge'); ?></th>
                    <th scope="col"><?php echo esc_html__('Status', 'jotform-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $jfbForm) : ?>
                    <tr>
                        <td><?php echo esc_html((string) ($jfbForm['title'] ?? '')); ?></td>
                        <td><code><?php echo esc_html((string) ($jfbForm['id'] ?? '')); ?></code></td>
                        <td><?php echo esc_html((string) ($jfbForm['status'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
