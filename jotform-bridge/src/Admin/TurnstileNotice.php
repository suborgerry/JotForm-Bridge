<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Submission\Guards\Turnstile;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Tells an administrator how to switch the challenge on — once, and politely.
 *
 * Built along the same lines as ApiKeyNotice: the keys live in wp-config.php,
 * so the only useful thing the admin area can do is say what to write and
 * where. The tone is different though, and deliberately so. A missing API key
 * means the plugin does not work; a missing challenge means one optional layer
 * is off, which is a perfectly reasonable state to be in. So this is an
 * informational notice that can be dismissed for good, not a warning that
 * follows somebody around forever.
 *
 * The one case that does get a warning is half a configuration: with only one
 * of the two constants defined the challenge silently does nothing, and
 * silently doing nothing is exactly what a security control must never do.
 */
final class TurnstileNotice
{
    public const CAPABILITY = 'manage_options';

    public const ACTION_DISMISS = 'jotform_bridge_dismiss_turnstile_notice';

    /**
     * Per user, not per site: one administrator deciding they are not
     * interested should not make the suggestion vanish for their colleagues.
     */
    public const USER_META = 'jotform_bridge_turnstile_notice_dismissed';

    /**
     * The lines an administrator has to paste into wp-config.php.
     */
    public const SNIPPET = "define( 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY', 'your-site-key' );\n"
        . "define( 'JOTFORM_BRIDGE_TURNSTILE_SECRET', 'your-secret-key' );";

    /**
     * Where the keys come from. Free, and does not require moving the domain
     * to Cloudflare — a point worth making, because most people assume it does.
     */
    public const DASHBOARD_URL = 'https://dash.cloudflare.com/?to=/:account/turnstile';

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_post_' . self::ACTION_DISMISS, [$this, 'handleDismiss']);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY) || !$this->onPluginScreen()) {
            return;
        }

        if (Turnstile::isConfigured()) {
            return;
        }

        if ($this->isHalfConfigured()) {
            $this->renderHalfConfigured();

            return;
        }

        if ((bool) get_user_meta(get_current_user_id(), self::USER_META, true)) {
            return;
        }

        $this->renderInvitation();
    }

    public function handleDismiss(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'jotform-bridge'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION_DISMISS);

        update_user_meta(get_current_user_id(), self::USER_META, 1);

        wp_safe_redirect(
            add_query_arg(['page' => IntegrationsPage::MENU_SLUG], admin_url('admin.php'))
        );

        exit;
    }

    private function renderInvitation(): void
    {
        printf(
            '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s</p>'
            . '<p>%3$s</p><pre style="margin:0 0 1em;white-space:pre-wrap;"><code>%4$s</code></pre>'
            . '<p>%5$s</p><p>%6$s</p></div>',
            esc_html__('Add a challenge to your forms?', 'jotform-bridge'),
            esc_html__(
                'Your forms already refuse bots that fill hidden fields, submit instantly or flood the endpoint. A challenge is the layer that also stops a bot driving a real browser. It is optional and free.',
                'jotform-bridge'
            ),
            sprintf(
                /* translators: %s: link to the Cloudflare Turnstile dashboard */
                esc_html__('Add your site at %s, then put the two keys it gives you into wp-config.php, above the "That\'s all, stop editing!" comment:', 'jotform-bridge'),
                '<a href="' . esc_url(self::DASHBOARD_URL) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Cloudflare Turnstile', 'jotform-bridge') . '</a>'
            ),
            esc_html(self::SNIPPET),
            esc_html__(
                'You do not need to move your domain to Cloudflare. After adding the keys, clear your page cache: submissions without a challenge token are refused, and pages cached beforehand do not carry the widget.',
                'jotform-bridge'
            ),
            $this->dismissButton()
        );
    }

    private function renderHalfConfigured(): void
    {
        printf(
            '<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
            esc_html__('The Jotform Bridge challenge is only half configured.', 'jotform-bridge'),
            esc_html(
                sprintf(
                    /* translators: %s: name of the missing constant */
                    __('%s is missing, so the challenge is switched off and your forms are running without it.', 'jotform-bridge'),
                    $this->missingConstant()
                )
            ),
            esc_html__('Add the missing constant to wp-config.php, or remove the other one to make it clear the challenge is off on purpose.', 'jotform-bridge')
        );
    }

    private function dismissButton(): string
    {
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
            <?php wp_nonce_field(self::ACTION_DISMISS); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_DISMISS); ?>">
            <button type="submit" class="button-link">
                <?php esc_html_e('No thanks, do not show this again', 'jotform-bridge'); ?>
            </button>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    private function isHalfConfigured(): bool
    {
        return $this->defined(Turnstile::SITE_KEY_CONSTANT) !== $this->defined(Turnstile::SECRET_CONSTANT);
    }

    private function missingConstant(): string
    {
        return $this->defined(Turnstile::SITE_KEY_CONSTANT)
            ? Turnstile::SECRET_CONSTANT
            : Turnstile::SITE_KEY_CONSTANT;
    }

    private function defined(string $constant): bool
    {
        return defined($constant) && trim((string) constant($constant)) !== '';
    }

    private function onPluginScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen !== null && strpos((string) $screen->id, IntegrationsPage::MENU_SLUG) !== false;
    }
}
