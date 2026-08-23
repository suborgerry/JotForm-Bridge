<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

use JotformBridge\Api\ApiResponse;
use JotformBridge\Api\JotformClient;
use JotformBridge\Settings\Settings;
use JotformBridge\Support\Features;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The circuit breaker that stands between a flood and the Jotform account.
 *
 * The monthly submission allowance belongs to the account, not to a form: spend
 * it and every form on the account — including the ones embedded elsewhere —
 * starts answering "Form Over Quota" until the billing cycle rolls over. That is
 * an outage nobody can shorten, which is why this guard exists at all and why it
 * counts globally rather than per integration.
 *
 * It answers a different question from RateLimiter. That one asks "is this
 * visitor abusing the form?" and is defeated by a flood spread across a
 * thousand addresses. This one asks "has this site sent an implausible amount
 * today?" and does not care where any of it came from.
 *
 * Two ceilings, whichever is lower:
 *
 *  - A rate ceiling derived from the site's own recent history: six times the
 *    median of the last seven days, never below a floor. A sixfold jump is not
 *    a good day, it is an event worth stopping to look at.
 *  - What is left of the monthly allowance, when the site owner has entered
 *    what the allowance is. The spend is read from GET /user/usage, so it
 *    accounts for submissions this plugin knows nothing about, plus everything
 *    recorded here since that snapshot was taken.
 *
 * Nothing here contacts Jotform. The usage snapshot is refreshed from the admin
 * screens, never from a request a visitor is waiting on — the same rule the
 * schema follows.
 *
 * The allowance half is currently switched off (Features::ACCOUNT_QUOTA): the
 * setting is hidden, the spend is not read, and the ceiling it would impose does
 * not apply. What stays on is the daily rate ceiling, which needs no account
 * knowledge and makes no requests, and the reaction to Jotform actually
 * refusing — that is not tracking, it is answering a refusal that has already
 * happened, and ignoring it would only send the next visitor into the same wall.
 */
final class QuotaGuard
{
    public const OPTION = 'jotform_bridge_quota';

    /** Refused because the site sent an implausible amount today. */
    public const REASON_DAILY = 'daily_ceiling';

    /** Refused because the monthly allowance is spent or nearly spent. */
    public const REASON_QUOTA = 'monthly_quota';

    /**
     * Jotform itself said the monthly submission allowance is gone.
     *
     * The most authoritative reason there is: no estimate, no snapshot, the
     * upstream refusing in as many words.
     */
    public const REASON_UPSTREAM_QUOTA = 'upstream_quota';

    /** Jotform itself said the daily API call allowance is gone. */
    public const REASON_UPSTREAM_API_LIMIT = 'upstream_api_limit';

    /**
     * Lowest daily ceiling, used until there is history to derive one from.
     *
     * Set well above what an ordinary contact form sees, because the cost of
     * the two mistakes is not symmetric: too high and a flood gets a few
     * hundred submissions further before it is stopped, too low and a genuinely
     * busy day — a campaign, a mention somewhere — is turned away as an attack.
     * The first is recoverable, the second is lost customers.
     */
    public const MIN_DAILY = 200;

    /**
     * How far above the recent median a day may go before it is stopped.
     */
    public const BURST_FACTOR = 6;

    /** Days of history kept, and the window the median is taken over. */
    private const HISTORY_DAYS = 30;
    private const MEDIAN_DAYS  = 7;

    /**
     * Share of the monthly allowance that turns the admin notice into a warning.
     */
    public const WARNING_SHARE = 0.9;

    /**
     * How long a usage snapshot is considered fresh enough, in seconds.
     */
    public const USAGE_TTL = 3600;

    private Settings $settings;

    public function __construct(?Settings $settings = null)
    {
        $this->settings = $settings ?? new Settings();
    }

    /**
     * The reason this submission may not be sent, or an empty string.
     *
     * Never contacts Jotform and never writes: it is called on every submission,
     * including the ones that are about to fail validation anyway.
     */
    public function check(): string
    {
        $state = $this->state();

        if ($state['tripped_at'] > 0 && $state['day'] === $this->today()) {
            return $state['reason'] !== '' ? $state['reason'] : self::REASON_DAILY;
        }

        $remaining = $this->remainingThisMonth($state);

        if ($remaining !== null && $remaining <= 0) {
            return self::REASON_QUOTA;
        }

        if ($this->countFor($state, $this->today()) >= $this->ceiling($state)) {
            return $remaining !== null && $remaining <= $this->rateCeiling($state)
                ? self::REASON_QUOTA
                : self::REASON_DAILY;
        }

        return '';
    }

    public function allows(): bool
    {
        return $this->check() === '';
    }

    /**
     * Records one submission that Jotform accepted.
     *
     * Only accepted ones: a submission that never reached Jotform spent no part
     * of the allowance, and charging it here would make an upstream outage look
     * like a flood.
     */
    public function record(): void
    {
        $state = $this->state();

        $state['days'][$this->today()] = $this->countFor($state, $this->today()) + 1;
        $state['since_check']++;

        $state = $this->prune($state);

        // Trip on the way past the ceiling rather than on the next request, so
        // the reason is recorded while the numbers that produced it are in hand.
        if ($state['days'][$this->today()] >= $this->ceiling($state)) {
            $remaining = $this->remainingThisMonth($state);

            $state['tripped_at'] = time();
            $state['day']        = $this->today();
            $state['reason']     = $remaining !== null && $remaining <= $this->rateCeiling($state)
                ? self::REASON_QUOTA
                : self::REASON_DAILY;
        }

        $this->save($state);
    }

    /**
     * Trips the breaker because Jotform said so.
     *
     * This is the one path where the reason is not an estimate. Until now the
     * guard could only infer that the account was near its allowance from a
     * snapshot that may be an hour old; an upstream refusal is the account
     * telling us directly, and there is no point letting the next visitor find
     * out the same way.
     */
    public function tripFromUpstream(string $reason): void
    {
        $state = $this->state();

        $state['tripped_at'] = time();
        $state['day']        = $this->today();
        $state['reason']     = $reason;

        $this->save($state);
    }

    /**
     * Remembers the daily API call allowance Jotform reported.
     */
    public function noteLimitLeft(?int $left): void
    {
        if ($left === null) {
            return;
        }

        $state = $this->state();

        $state['limit_left']    = max(0, $left);
        $state['limit_seen_at'] = time();

        $this->save($state);
    }

    /**
     * Clears a trip so the form starts accepting submissions again.
     *
     * Today's count goes with it: leaving it in place would trip the guard again
     * on the very next submission, which is not what anybody pressing a reset
     * button means. The history of previous days is kept, because that is what
     * the ceiling is derived from.
     */
    public function reset(): void
    {
        $state = $this->state();

        $state['tripped_at']           = 0;
        $state['reason']               = '';
        $state['day']                  = '';
        $state['days'][$this->today()] = 0;

        // An upstream refusal is about the account, not about today's rate, so
        // clearing it means the site owner has dealt with the account.

        $this->save($state);
    }

    /**
     * Refreshes the account-wide spend from Jotform.
     *
     * Called from the admin screens only. Returns null when the snapshot is
     * still fresh, so a caller can simply ask on every page load.
     */
    public function refreshUsage(JotformClient $client, bool $force = false): ?ApiResponse
    {
        if (!Features::enabled(Features::ACCOUNT_QUOTA)) {
            return null;
        }

        $state = $this->state();

        if (!$force && $state['usage_checked_at'] > time() - self::USAGE_TTL) {
            return null;
        }

        $response = $client->getUsage();

        if (!$response->isSuccess()) {
            // The previous snapshot is left in place: a stale number is a better
            // basis for the ceiling than no number at all.
            return $response;
        }

        $state['usage_submissions'] = (int) ($response->data()['submissions'] ?? 0);
        $state['usage_checked_at']  = time();
        $state['since_check']       = 0;

        $limitLeft = $response->limitLeft();

        if ($limitLeft !== null) {
            $state['limit_left']    = $limitLeft;
            $state['limit_seen_at'] = time();
        }

        $this->save($state);

        return $response;
    }

    /**
     * Everything the admin screens need to describe the current situation.
     *
     * @return array{
     *     days: array<string, int>,
     *     today: int,
     *     ceiling: int,
     *     median: int,
     *     tripped_at: int,
     *     reason: string,
     *     day: string,
     *     usage_submissions: int,
     *     usage_checked_at: int,
     *     since_check: int,
     *     limit_left: int,
     *     limit_seen_at: int,
     *     monthly_quota: int,
     *     used: int,
     *     remaining: int|null
     * }
     */
    public function status(): array
    {
        $state = $this->state();

        return $state + [
            'ceiling'   => $this->ceiling($state),
            'median'    => $this->median($state),
            'used'      => $state['usage_submissions'] + $state['since_check'],
            'remaining' => $this->remainingThisMonth($state),
        ];
    }

    /**
     * Whether the account is close enough to its allowance to say so.
     */
    public function isNearQuota(): bool
    {
        if (!Features::enabled(Features::ACCOUNT_QUOTA)) {
            return false;
        }

        $state = $this->state();
        $quota = $this->settings->monthlyQuota();

        if ($quota <= 0) {
            return false;
        }

        return ($state['usage_submissions'] + $state['since_check']) >= (int) floor($quota * self::WARNING_SHARE);
    }

    public function isTripped(): bool
    {
        $state = $this->state();

        return $state['tripped_at'] > 0 && $state['day'] === $this->today();
    }

    /**
     * The effective ceiling for today's count: the rate ceiling, capped by
     * whatever is left of the monthly allowance.
     *
     * The allowance is expressed as "how many more", while the ceiling is
     * compared against "how many so far", so what is left has to be added to
     * what today has already spent. Comparing the two directly would charge
     * every submission twice — once to today's count and once to the remaining
     * allowance — and halve the real budget.
     *
     * @param array<string, mixed> $state
     */
    private function ceiling(array $state): int
    {
        $ceiling   = $this->rateCeiling($state);
        $remaining = $this->remainingThisMonth($state);

        if ($remaining !== null) {
            $ceiling = min($ceiling, $this->countFor($state, $this->today()) + max(0, $remaining));
        }

        return $ceiling;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function rateCeiling(array $state): int
    {
        $ceiling = max(self::MIN_DAILY, self::BURST_FACTOR * $this->median($state));

        /**
         * Filters the daily ceiling the quota guard trips at.
         *
         * @param int                  $ceiling Submissions allowed today.
         * @param int                  $median  Median of the last seven days.
         * @param array<string, mixed> $state   Raw guard state.
         */
        return max(1, (int) apply_filters('jotform_bridge_daily_ceiling', $ceiling, $this->median($state), $state));
    }

    /**
     * How much of the monthly allowance is left, or null when it is unknown.
     *
     * The snapshot plus everything sent since it was taken: the snapshot alone
     * would be an undercount between refreshes, and an undercount is the
     * dangerous direction for a safety mechanism.
     *
     * @param array<string, mixed> $state
     */
    private function remainingThisMonth(array $state): ?int
    {
        if (!Features::enabled(Features::ACCOUNT_QUOTA)) {
            return null;
        }

        $quota = $this->settings->monthlyQuota();

        if ($quota <= 0 || $state['usage_checked_at'] <= 0) {
            return null;
        }

        return $quota - ($state['usage_submissions'] + $state['since_check']);
    }

    /**
     * Median of the last seven completed days.
     *
     * Today is excluded on purpose: a day in progress would drag the ceiling up
     * exactly while an event is unfolding, which is when it must not move.
     *
     * @param array<string, mixed> $state
     */
    private function median(array $state): int
    {
        $today  = $this->today();
        $counts = [];

        foreach ($state['days'] as $day => $count) {
            if ((string) $day !== $today) {
                $counts[(string) $day] = (int) $count;
            }
        }

        if ($counts === []) {
            return 0;
        }

        krsort($counts);

        $recent = array_slice(array_values($counts), 0, self::MEDIAN_DAYS);

        sort($recent);

        $middle = (int) floor((count($recent) - 1) / 2);

        return (int) $recent[$middle];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function countFor(array $state, string $day): int
    {
        return isset($state['days'][$day]) ? (int) $state['days'][$day] : 0;
    }

    /**
     * Keeps the option small and bounded.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function prune(array $state): array
    {
        if (count($state['days']) <= self::HISTORY_DAYS) {
            return $state;
        }

        krsort($state['days']);

        $state['days'] = array_slice($state['days'], 0, self::HISTORY_DAYS, true);

        return $state;
    }

    /**
     * UTC rather than site time: the bucket is a rate window, not a report, and
     * a boundary that moves with a timezone setting is a boundary that can be
     * moved to clear the counter.
     */
    private function today(): string
    {
        return gmdate('Y-m-d');
    }

    /**
     * @return array{
     *     days: array<string, int>,
     *     today: int,
     *     tripped_at: int,
     *     reason: string,
     *     day: string,
     *     usage_submissions: int,
     *     usage_checked_at: int,
     *     since_check: int,
     *     limit_left: int,
     *     limit_seen_at: int,
     *     monthly_quota: int
     * }
     */
    private function state(): array
    {
        $stored = get_option(self::OPTION, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        $days = [];

        if (isset($stored['days']) && is_array($stored['days'])) {
            foreach ($stored['days'] as $day => $count) {
                $days[(string) $day] = max(0, (int) $count);
            }
        }

        return [
            'days'              => $days,
            'today'             => $days[$this->today()] ?? 0,
            'tripped_at'        => isset($stored['tripped_at']) ? (int) $stored['tripped_at'] : 0,
            'reason'            => isset($stored['reason']) ? (string) $stored['reason'] : '',
            'day'               => isset($stored['day']) ? (string) $stored['day'] : '',
            'usage_submissions' => isset($stored['usage_submissions']) ? (int) $stored['usage_submissions'] : 0,
            'usage_checked_at'  => isset($stored['usage_checked_at']) ? (int) $stored['usage_checked_at'] : 0,
            'since_check'       => isset($stored['since_check']) ? (int) $stored['since_check'] : 0,
            'limit_left'        => isset($stored['limit_left']) ? (int) $stored['limit_left'] : -1,
            'limit_seen_at'     => isset($stored['limit_seen_at']) ? (int) $stored['limit_seen_at'] : 0,
            'monthly_quota'     => $this->settings->monthlyQuota(),
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    private function save(array $state): void
    {
        update_option(
            self::OPTION,
            [
                'days'              => $state['days'],
                'tripped_at'        => (int) $state['tripped_at'],
                'reason'            => (string) $state['reason'],
                'day'               => (string) $state['day'],
                'usage_submissions' => (int) $state['usage_submissions'],
                'usage_checked_at'  => (int) $state['usage_checked_at'],
                'since_check'       => max(0, (int) $state['since_check']),
                'limit_left'        => (int) $state['limit_left'],
                'limit_seen_at'     => (int) $state['limit_seen_at'],
            ],
            false
        );
    }
}
