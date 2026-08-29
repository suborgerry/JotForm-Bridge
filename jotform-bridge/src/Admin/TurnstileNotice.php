<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Submission\Guards\Turnstile;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Says whether the optional challenge is on, and how to switch it on.
 *
 * Built along the same lines as ApiKeyNotice: the keys live in wp-config.php,
 * so the only useful thing the admin area can do is say what to write and
 * where. The tone is different though, and deliberately so. A missing API key
 * means the plugin does not work; a missing challenge means one optional layer
 * is off, which is a perfectly reasonable state to be in. So this is a plain
 * line of information, not a warning and not a campaign: no colour beyond the
 * default, no call to action, and the instructions folded away until somebody
 * asks for them. Nothing to dismiss either — there is nothing to escape from,
 * and a state worth reading stays readable.
 *
 * The one case that does get a warning is half a configuration: with only one
 * of the two constants defined the challenge silently does nothing, and
 * silently doing nothing is exactly what a security control must never do.
 */
final class TurnstileNotice
{
    public const CAPABILITY = 'manage_options';

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

        $this->renderStatus();
    }

    /**
     * One sentence of state, with the setup steps behind a disclosure.
     */
    private function renderStatus(): void
    {
        printf(
            '<div class="notice notice-info inline jfb-challenge-notice">'
            . '<p>%1$s</p>'
            . '<details><summary>%2$s</summary>'
            . '<p>%3$s</p><pre><code>%4$s</code></pre>'
            . '<p>%5$s</p></details></div>',
            esc_html__(
                'Challenge: off. Your forms still refuse bots that fill hidden fields, submit instantly or flood the endpoint; a challenge is the optional layer that also stops a bot driving a real browser.',
                'jotform-bridge'
            ),
            esc_html__('How to switch it on', 'jotform-bridge'),
            sprintf(
                /* translators: %s: link to the Cloudflare Turnstile dashboard */
                esc_html__('Add your site at %s, then put the two keys it gives you into wp-config.php, above the "That\'s all, stop editing!" comment:', 'jotform-bridge'),
                '<a href="' . esc_url(self::DASHBOARD_URL) . '" target="_blank" rel="noopener noreferrer">'
                    . esc_html__('Cloudflare Turnstile', 'jotform-bridge') . '</a>'
            ),
            esc_html(self::SNIPPET),
            esc_html__(
                'It is free, and you do not need to move your domain to Cloudflare. After adding the keys, clear your page cache: submissions without a challenge token are refused, and pages cached beforehand do not carry the widget.',
                'jotform-bridge'
            )
        );
    }

    private function renderHalfConfigured(): void
    {
        printf(
            '<div class="notice notice-warning jfb-challenge-notice"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
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
