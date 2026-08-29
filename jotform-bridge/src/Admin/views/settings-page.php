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

use JotformBridge\Admin\ApiKeyNotice;
use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Settings\Settings;
use JotformBridge\Support\Features;

if (!defined('ABSPATH')) {
    exit;
}

$jfbHasKey = $settings->hasApiKey();
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
                        placeholder="https://api.jotform.com"
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

            <?php if (Features::enabled(Features::ACCOUNT_QUOTA)) : ?>
            <tr>
                <th scope="row">
                    <label for="jotform-bridge-monthly-quota">
                        <?php echo esc_html__('Monthly Submission Allowance', 'jotform-bridge'); ?>
                    </label>
                </th>
                <td>
                    <input
                        type="number"
                        min="0"
                        step="1"
                        class="small-text"
                        id="jotform-bridge-monthly-quota"
                        name="jotform_bridge[monthly_quota]"
                        value="<?php echo esc_attr((string) $settings->monthlyQuota()); ?>"
                    >
                    <p class="description">
                        <?php
                        echo esc_html__(
                            'How many submissions your Jotform plan allows per month. Jotform reports how much of the allowance has been spent, but not what it is, so it has to be entered here once. Leave it at 0 if you would rather not: the safety ceiling still limits how much can be sent in a day, it just cannot tell how close the account is to having its forms switched off.',
                            'jotform-bridge'
                        );
                        ?>
                    </p>
                </td>
            </tr>
            <?php endif; ?>

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

    <script>
        /* The custom base URL only matters for the custom region. With JavaScript
           off the field stays visible, which is a usable form, not a broken one:
           the server ignores the value unless the region asks for it. */
        (function () {
            var region = document.getElementById('jfb-region');
            var rows = document.querySelectorAll('.jfb-custom-base-url-field');

            if (!region) {
                return;
            }

            function sync() {
                var show = region.value === '<?php echo esc_js(Settings::REGION_CUSTOM); ?>';

                for (var i = 0; i < rows.length; i++) {
                    rows[i].style.display = show ? '' : 'none';
                }
            }

            region.addEventListener('change', sync);
            sync();
        })();
    </script>

    <h2><?php echo esc_html__('Sync & Connection', 'jotform-bridge'); ?></h2>
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
    <div class="jfb-actions">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
            <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_TEST); ?>">
            <?php wp_nonce_field(SettingsPage::ACTION_TEST); ?>
            <?php submit_button(__('Test Connection', 'jotform-bridge'), 'secondary', 'submit', false); ?>
        </form>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_REFRESH); ?>">
            <?php wp_nonce_field(SettingsPage::ACTION_REFRESH); ?>
            <?php submit_button(__('Sync with Jotform', 'jotform-bridge'), 'secondary', 'submit', false); ?>
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
        <p><?php echo esc_html__('Form list has not been loaded yet. Use Sync with Jotform.', 'jotform-bridge'); ?></p>
    <?php else : ?>
        <p class="description">
            <?php
            printf(
                /* translators: 1: number of forms, 2: human readable time difference */
                esc_html__('%1$d forms stored, loaded %2$s ago.', 'jotform-bridge'),
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
                    <th scope="col"><?php echo esc_html__('Actions', 'jotform-bridge'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($forms as $jfbForm) : ?>
                    <?php $jfbFormId = (string) ($jfbForm['id'] ?? ''); ?>
                    <tr>
                        <td><?php echo esc_html((string) ($jfbForm['title'] ?? '')); ?></td>
                        <td><code><?php echo esc_html($jfbFormId); ?></code></td>
                        <td><?php echo esc_html((string) ($jfbForm['status'] ?? '')); ?></td>
                        <td>
                            <?php if (strtoupper((string) ($jfbForm['status'] ?? '')) === FormRepository::STATUS_DELETED) : ?>
                                <?php /* Deleted in Jotform: the row is only noise here, so it can be dropped for good. */ ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_REMOVE); ?>">
                                    <input type="hidden" name="form_id" value="<?php echo esc_attr($jfbFormId); ?>">
                                    <?php wp_nonce_field(SettingsPage::ACTION_REMOVE); ?>
                                    <button type="submit" class="button-link delete">
                                        <?php echo esc_html__('Remove from list', 'jotform-bridge'); ?>
                                    </button>
                                </form>
                            <?php else : ?>
                                <span aria-hidden="true">&mdash;</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
