<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caps how often one address may submit one integration.
 *
 * This protects the site, not the Jotform account: it stops a single source
 * from spending the server's time on validation and the account's quota on
 * junk. A distributed flood walks straight past it, which is what QuotaGuard is
 * for — the two are deliberately separate mechanisms with separate jobs.
 *
 * Every attempt is counted, including the ones that go on to fail validation.
 * Counting only successes would make probing the form free, which is precisely
 * the activity worth making expensive.
 *
 * Fixed windows on transients, not a sliding log: the storage is one integer per
 * window per address, it expires on its own, and the worst case — a burst
 * landing on a window boundary — costs at most one extra window's allowance.
 * That is the right trade for a guard whose job is to stop floods, not to meter
 * traffic precisely.
 */
final class RateLimiter
{
    public const TRANSIENT_PREFIX = 'jotform_bridge_rate_';

    /** Submissions allowed from one address per minute. */
    public const DEFAULT_PER_MINUTE = 5;

    /** Submissions allowed from one address per hour. */
    public const DEFAULT_PER_HOUR = 30;

    private const MINUTE = 60;
    private const HOUR   = 3600;

    /**
     * Decides whether one attempt may proceed, and records it when it may.
     *
     * @param string $ip Visitor address; an empty string disables the check.
     *
     * @return int Seconds to wait, or 0 when the attempt is allowed.
     */
    public function check(string $slug, string $ip): int
    {
        if ($ip === '') {
            // Without an address every visitor would share one bucket, and the
            // guard would turn into a site-wide outage the first time a burst
            // arrived. Refusing to guess is the safer failure.
            return 0;
        }

        $limits = $this->limits($slug);

        $windows = [
            [self::MINUTE, (int) $limits['per_minute']],
            [self::HOUR, (int) $limits['per_hour']],
        ];

        $wait    = 0;
        $pending = [];

        foreach ($windows as [$window, $limit]) {
            if ($limit <= 0) {
                continue;
            }

            $key   = $this->key($slug, $ip, $window);
            $count = (int) get_transient($key);

            if ($count >= $limit) {
                $wait = max($wait, $this->secondsLeft($window));

                continue;
            }

            $pending[$key] = [$count + 1, $window];
        }

        if ($wait > 0) {
            // Nothing is recorded for a refused attempt: the window that
            // refused it is already full, and the other one must not be
            // charged for a submission that never happened.
            return $wait;
        }

        foreach ($pending as $key => [$count, $window]) {
            set_transient($key, $count, $window);
        }

        return 0;
    }

    /**
     * @return array{per_minute:int, per_hour:int}
     */
    public function limits(string $slug): array
    {
        $defaults = [
            'per_minute' => self::DEFAULT_PER_MINUTE,
            'per_hour'   => self::DEFAULT_PER_HOUR,
        ];

        /**
         * Filters how many submissions one address may send.
         *
         * Set either value to 0 to disable that window.
         *
         * @param array{per_minute:int, per_hour:int} $limits Current limits.
         * @param string                              $slug   Integration slug.
         */
        $filtered = apply_filters('jotform_bridge_rate_limits', $defaults, $slug);

        if (!is_array($filtered)) {
            return $defaults;
        }

        return [
            'per_minute' => isset($filtered['per_minute']) ? max(0, (int) $filtered['per_minute']) : $defaults['per_minute'],
            'per_hour'   => isset($filtered['per_hour']) ? max(0, (int) $filtered['per_hour']) : $defaults['per_hour'],
        ];
    }

    /**
     * The bucket key: integration, address and window, hashed.
     *
     * Only a hash is stored, so no address ever ends up in the options table —
     * the same rule the duplicate guard already follows.
     */
    private function key(string $slug, string $ip, int $window): string
    {
        return self::TRANSIENT_PREFIX . md5($slug . '|' . $ip . '|' . $window . '|' . $this->windowStart($window));
    }

    private function windowStart(int $window): int
    {
        return (int) floor(time() / $window) * $window;
    }

    private function secondsLeft(int $window): int
    {
        return max(1, $this->windowStart($window) + $window - time());
    }
}
