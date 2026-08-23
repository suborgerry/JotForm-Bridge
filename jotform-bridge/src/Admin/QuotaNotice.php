<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Api\JotformClient;
use JotformBridge\Settings\Settings;
use JotformBridge\Submission\QuotaGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin surface of the quota guard: the warning, the trip, and the way out.
 *
 * A circuit breaker nobody is told about is worse than none — the forms would
 * simply stop working for reasons that only appear in a log the site does not
 * write by default. So the trip is a persistent notice, not a log line, and it
 * carries the button that clears it: a legitimate spike is a thing that happens,
 * and the site owner has to be able to say so.
 *
 * This is also where the account-wide spend is refreshed. Once an hour, on the
 * plugin's own screens only, so the request happens because somebody opened
 * Jotform Bridge — never while a visitor waits for a submission to go through.
 */
final class QuotaNotice
{
    public const CAPABILITY   = 'manage_options';
    public const ACTION_RESET = 'jotform_bridge_reset_quota';

    private QuotaGuard $quota;

    private Settings $settings;

    private JotformClient $client;

    public function __construct(QuotaGuard $quota, Settings $settings, JotformClient $client)
    {
        $this->quota    = $quota;
        $this->settings = $settings;
        $this->client   = $client;
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
        add_action('admin_post_' . self::ACTION_RESET, [$this, 'handleReset']);
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY) || !$this->onPluginScreen()) {
            return;
        }

        $this->refreshUsage();

        if ($this->quota->isTripped()) {
            $this->renderTrip();

            return;
        }

        if ($this->quota->isNearQuota()) {
            $this->renderWarning();
        }
    }

    /**
     * Clears the trip and lets the forms accept submissions again.
     */
    public function handleReset(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to perform this action.', 'jotform-bridge'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION_RESET);

        $this->quota->reset();

        wp_safe_redirect(
            add_query_arg(
                ['page' => IntegrationsPage::MENU_SLUG],
                admin_url('admin.php')
            )
        );

        exit;
    }

    private function renderTrip(): void
    {
        $status = $this->quota->status();

        $reason = $status['reason'] === QuotaGuard::REASON_QUOTA
            ? __(
                'The Jotform account is at or near its monthly submission allowance. Sending more would risk switching every form on the account off until the allowance resets.',
                'jotform-bridge'
            )
            : sprintf(
                /* translators: 1: submissions sent today, 2: the ceiling that was reached */
                __(
                    'This site has sent %1$d submissions today, which reached the safety ceiling of %2$d. That is far above its recent normal, so sending was stopped.',
                    'jotform-bridge'
                ),
                (int) $status['today'],
                (int) $status['ceiling']
            );

        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p>%4$s</p></div>',
            esc_html__('Jotform Bridge has stopped sending submissions.', 'jotform-bridge'),
            esc_html($reason),
            esc_html__(
                'Visitors are seeing the generic "please try again later" message. Check what caused the spike before clearing this — if it was real traffic, clearing it is the right answer.',
                'jotform-bridge'
            ),
            $this->resetButton()
        );
    }

    private function renderWarning(): void
    {
        $status = $this->quota->status();

        printf(
            '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
            esc_html__('Jotform Bridge:', 'jotform-bridge'),
            esc_html(
                sprintf(
                    /* translators: 1: submissions used, 2: monthly allowance */
                    __(
                        'the Jotform account has used %1$d of its %2$d monthly submissions. When the allowance runs out, every form on the account stops accepting submissions until it resets.',
                        'jotform-bridge'
                    ),
                    (int) $status['used'],
                    (int) $status['monthly_quota']
                )
            )
        );
    }

    /**
     * @return string Escaped markup for the reset form.
     */
    private function resetButton(): string
    {
        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::ACTION_RESET); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_RESET); ?>">
            <button type="submit" class="button button-secondary">
                <?php esc_html_e('Resume sending submissions', 'jotform-bridge'); ?>
            </button>
        </form>
        <?php

        return (string) ob_get_clean();
    }

    /**
     * Refreshes the account-wide spend, at most once an hour.
     *
     * A failure is deliberately silent: the guard falls back to the previous
     * snapshot, and an unreachable API already has its own notice elsewhere.
     */
    private function refreshUsage(): void
    {
        if (!$this->settings->hasApiKey()) {
            return;
        }

        $this->quota->refreshUsage($this->client);
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
