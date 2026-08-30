<?php

declare(strict_types=1);

namespace JotformBridge\Admin;

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
 * Nothing here contacts Jotform: the notice describes state the guard already
 * holds.
 */
final class QuotaNotice
{
    public const CAPABILITY   = 'manage_options';
    public const ACTION_RESET = 'jotform_bridge_reset_quota';

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

        // Clearing a ceiling the site set for itself is a judgement call about
        // traffic. Clearing one Jotform imposed only makes sense once the
        // account side has actually changed, so the advice differs.
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

    /**
     * The one warning left: Jotform's own count of API calls left for today,
     * which it reports on every answer and costs nothing to carry.
     */
    private function renderWarning(): void
    {
        $status = $this->quota->status();

        if ($status['limit_left'] >= 0 && $status['limit_left'] < 100) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s</p></div>',
                esc_html__('Jotform Bridge:', 'jotform-bridge'),
                esc_html(
                    sprintf(
                        /* translators: %d: remaining API calls */
                        __(
                            'the Jotform account has %d API calls left for today. Syncing a schema, refreshing the form list and every submission each spend one. The allowance resets at midnight Eastern time.',
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
