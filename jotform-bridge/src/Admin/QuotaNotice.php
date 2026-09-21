<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

use JotformBridge\Plugin;
use JotformBridge\Submission\QuotaGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The admin surface of the quota guard: the low-allowance warning, the trip
 * notice and the reset button. Never contacts Jotform.
 */
final class QuotaNotice
{
    public const CAPABILITY   = Plugin::CAPABILITY;
    public const ACTION_RESET = 'jotform_bridge_reset_quota';

    /** Remaining API calls below which the site owner is warned. */
    private const WARN_BELOW = 100;

    private QuotaGuard $quota;

    public function __construct(QuotaGuard $quota)
    {
        $this->quota = $quota;
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

        if ($this->quota->isTripped()) {
            $this->renderTrip();

            return;
        }

        $this->renderWarning();
    }

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

        if ($status['reason'] === QuotaGuard::REASON_UPSTREAM_QUOTA) {
            $reason = __(
                'Jotform has refused a submission because the account is over its monthly allowance. Every form on the account — including any embedded elsewhere — stays switched off until the allowance resets. Upgrading the plan or waiting for the new cycle are the only two ways out.',
                'jotform-bridge'
            );
        } elseif ($status['reason'] === QuotaGuard::REASON_UPSTREAM_API_LIMIT) {
            $reason = __(
                'Jotform has refused a request because the account is out of API calls for today. The allowance resets at midnight Eastern time; until then no submission can be forwarded.',
                'jotform-bridge'
            );
        } else {
            $reason = sprintf(
                /* translators: 1: submissions sent today, 2: the ceiling that was reached */
                __(
                    'This site has sent %1$d submissions today, which reached the safety ceiling of %2$d. That is far above its recent normal, so sending was stopped.',
                    'jotform-bridge'
                ),
                (int) $status['today'],
                (int) $status['ceiling']
            );
        }

        $advice = $this->fromUpstream($status['reason'])
            ? __(
                'Visitors are seeing the generic "please try again later" message. Clearing this only helps once the account itself has room again — otherwise Jotform will simply refuse the next one too.',
                'jotform-bridge'
            )
            : __(
                'Visitors are seeing the generic "please try again later" message. Check what caused the spike before clearing this — if it was real traffic, clearing it is the right answer.',
                'jotform-bridge'
            );

        printf(
            '<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p><p>%4$s</p></div>',
            esc_html__('Jotform Bridge has stopped sending submissions.', 'jotform-bridge'),
            esc_html($reason),
            esc_html($advice),
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built by this class, escaped as it is written.
            $this->resetButton()
        );
    }

    private function fromUpstream(string $reason): bool
    {
        return $reason === QuotaGuard::REASON_UPSTREAM_QUOTA
            || $reason === QuotaGuard::REASON_UPSTREAM_API_LIMIT;
    }

    /** Warns when Jotform's reported daily API allowance runs low. */
    private function renderWarning(): void
    {
        $status = $this->quota->status();

        if ($status['limit_left'] >= 0 && $status['limit_left'] < self::WARN_BELOW) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__('Jotform Bridge:', 'jotform-bridge'),
                esc_html(
                    sprintf(
                        /* translators: %d: remaining API calls */
                        __(
                            'the Jotform account has %d API calls left for today. Connecting a form, syncing a schema, checking the connection and every submission each spend one. The allowance resets at midnight Eastern time.',
                            'jotform-bridge'
                        ),
                        (int) $status['limit_left']
                    )
                )
            );
        }
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

    private function onPluginScreen(): bool
    {
        if (!function_exists('get_current_screen')) {
            return false;
        }

        $screen = get_current_screen();

        return $screen !== null && strpos((string) $screen->id, IntegrationsPage::MENU_SLUG) !== false;
    }
}
