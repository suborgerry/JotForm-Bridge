<?php
/**
 * Email validation settings.
 * @var \JotformBridge\Settings\Settings $settings
 * @package JotformBridge
 */
declare(strict_types=1);
use JotformBridge\Admin\SettingsPage;
if (!defined('ABSPATH')) {
    exit;
}
?>
<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="<?php echo esc_attr(SettingsPage::ACTION_SAVE); ?>">
    <input type="hidden" name="jotform_bridge[validation_tab]" value="1">
    <?php wp_nonce_field(SettingsPage::ACTION_SAVE); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php echo esc_html__('Email domains', 'jotform-bridge'); ?></th>
            <td>
                <label><input type="checkbox" name="jotform_bridge[popular_email_domains_only]" value="1" <?php checked($settings->popularEmailDomainsOnly()); ?>> <?php echo esc_html__('Allow only popular email domains', 'jotform-bridge'); ?></label>
                <p class="description"><?php echo esc_html__('When enabled, all email fields accept only the domains below. When disabled, any domain is accepted if the email format is valid.', 'jotform-bridge'); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><label for="jfb-email-domains"><?php echo esc_html__('Allowed domains', 'jotform-bridge'); ?></label></th>
            <td>
                <textarea id="jfb-email-domains" name="jotform_bridge[allowed_email_domains]" rows="14" class="large-text code" aria-describedby="jfb-email-domains-help"><?php echo esc_textarea(implode("\n", $settings->allowedEmailDomains())); ?></textarea>
                <p id="jfb-email-domains-help" class="description"><?php echo esc_html__('One domain per line, without @ or a URL. Matching ignores case and is exact: subdomains are not included. Invalid lines are removed on save. An empty list blocks all email addresses when enabled. Add company domains if needed. The initial list contains established providers; it does not verify mailbox ownership or guarantee safety.', 'jotform-bridge'); ?></p>
            </td>
        </tr>
    </table>
    <?php submit_button(__('Save Settings', 'jotform-bridge')); ?>
</form>
