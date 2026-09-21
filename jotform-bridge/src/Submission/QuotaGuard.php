<?php

declare(strict_types=1);

namespace JotformBridge\Submission;


if (!defined('ABSPATH')) {
    exit;
}

/**
 * Site-wide circuit breaker protecting the Jotform account's allowance.
 *
 * Trips when today's accepted submissions pass a ceiling derived from the
 * site's own history (BURST_FACTOR × the median of the last MEDIAN_DAYS,
 * never below MIN_DAILY), or when Jotform itself refuses for quota reasons.
 * Never contacts Jotform.
 */
final class QuotaGuard
{
    public const OPTION = 'jotform_bridge_quota';

    /** Refused because the site sent an implausible amount today. */
    public const REASON_DAILY = 'daily_ceiling';

    /** Jotform said the monthly submission allowance is gone. */
    public const REASON_UPSTREAM_QUOTA = 'upstream_quota';

    /** Jotform said the daily API call allowance is gone. */
    public const REASON_UPSTREAM_API_LIMIT = 'upstream_api_limit';

    /** Lowest daily ceiling. */
    public const MIN_DAILY = 200;

    /** Multiple of the recent median a day may reach before tripping. */
    public const BURST_FACTOR = 6;

    /** Completed days the median is taken over. */
    private const MEDIAN_DAYS = 7;

    /** Days of history kept; handed in full to the ceiling filter. */
    private const HISTORY_DAYS = 30;

    /** The reason this submission may not be sent, or an empty string. Read-only. */
    public function check(): string
    {
        $state = $this->state();

        if ($state['tripped_at'] > 0 && $state['day'] === $this->today()) {
            return $state['reason'] !== '' ? $state['reason'] : self::REASON_DAILY;
        }

        if ($this->countFor($state, $this->today()) >= $this->ceiling($state)) {
            return self::REASON_DAILY;
        }

        return '';
    }

    public function allows(): bool
    {
        return $this->check() === '';
    }

    /** Records one submission Jotform accepted. */
    public function record(): void
    {
        $state = $this->state();

        $state['days'][$this->today()] = $this->countFor($state, $this->today()) + 1;

        $state = $this->prune($state);

        if ($state['days'][$this->today()] >= $this->ceiling($state)) {
            $state['tripped_at'] = time();
            $state['day']        = $this->today();
            $state['reason']     = self::REASON_DAILY;
        }

        $this->save($state);
    }

    /** Trips the breaker on a quota refusal from Jotform. */
    public function tripFromUpstream(string $reason): void
    {
        $state = $this->state();

        $state['tripped_at'] = time();
        $state['day']        = $this->today();
        $state['reason']     = $reason;

        $this->save($state);
    }

    /** Remembers the daily API call allowance Jotform reported. */
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

    /** Clears a trip and today's count; earlier history is kept. */
    public function reset(): void
    {
        $state = $this->state();

        $state['tripped_at']           = 0;
        $state['reason']               = '';
        $state['day']                  = '';
        $state['days'][$this->today()] = 0;

        $this->save($state);
    }

    /**
     * State for the admin screens.
     *
     * @return array{
     *     days: array<string, int>,
     *     today: int,
     *     ceiling: int,
     *     median: int,
     *     tripped_at: int,
     *     reason: string,
     *     day: string,
     *     limit_left: int,
     *     limit_seen_at: int
     * }
     */
    public function status(): array
    {
        $state = $this->state();

        return $state + [
            'ceiling' => $this->ceiling($state),
            'median'  => $this->median($state),
        ];
    }

    public function isTripped(): bool
    {
        $state = $this->state();

        return $state['tripped_at'] > 0 && $state['day'] === $this->today();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function ceiling(array $state): int
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
     * Median of the last MEDIAN_DAYS completed days; today is excluded.
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

    /** UTC day, so the window does not move with the site timezone. */
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
     *     limit_left: int,
     *     limit_seen_at: int
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
            'days'          => $days,
            'today'         => $days[$this->today()] ?? 0,
            'tripped_at'    => isset($stored['tripped_at']) ? (int) $stored['tripped_at'] : 0,
            'reason'        => isset($stored['reason']) ? (string) $stored['reason'] : '',
            'day'           => isset($stored['day']) ? (string) $stored['day'] : '',
            'limit_left'    => isset($stored['limit_left']) ? (int) $stored['limit_left'] : -1,
            'limit_seen_at' => isset($stored['limit_seen_at']) ? (int) $stored['limit_seen_at'] : 0,
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
                'days'          => $state['days'],
                'tripped_at'    => (int) $state['tripped_at'],
                'reason'        => (string) $state['reason'],
                'day'           => (string) $state['day'],
                'limit_left'    => (int) $state['limit_left'],
                'limit_seen_at' => (int) $state['limit_seen_at'],
            ],
            false
        );
    }
}
