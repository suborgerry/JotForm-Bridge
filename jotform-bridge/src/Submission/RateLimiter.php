<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caps how often one address may post to the submission endpoint. Every
 * attempt counts. Two scopes: site-wide (charged before the integration is
 * looked up) and per integration. Fixed windows stored as transients.
 */
final class RateLimiter
{
    public const TRANSIENT_PREFIX = 'jotform_bridge_rate_';

    /** Per-integration allowance for one address. */
    public const DEFAULT_PER_MINUTE = 5;
    public const DEFAULT_PER_HOUR   = 30;

    /** Site-wide allowance for one address, across every integration. */
    public const DEFAULT_GLOBAL_PER_MINUTE = 15;
    public const DEFAULT_GLOBAL_PER_HOUR   = 60;

    /** Bucket name for the site-wide scope; never a valid slug. */
    private const GLOBAL_SCOPE = '*';

    private const MINUTE = 60;
    private const HOUR   = 3600;

    /**
     * The site-wide budget.
     *
     * @param string $ip Visitor address; an empty string disables the check.
     *
     * @return int Seconds to wait, or 0 when the attempt is allowed.
     */
    public function checkGlobal(string $ip): int
    {
        return $this->consume(self::GLOBAL_SCOPE, $ip, $this->globalLimits());
    }

    /**
     * The per-integration budget.
     *
     * @param string $ip Visitor address; an empty string disables the check.
     *
     * @return int Seconds to wait, or 0 when the attempt is allowed.
     */
    public function check(string $slug, string $ip): int
    {
        return $this->consume($slug, $ip, $this->limits($slug));
    }

    /**
     * Decides whether one attempt may proceed, and records it when it may.
     *
     * @param array{per_minute:int, per_hour:int} $limits
     */
    private function consume(string $scope, string $ip, array $limits): int
    {
        if ($ip === '') {
            // Without an address every visitor would share one bucket.
            return 0;
        }

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

            $key   = $this->key($scope, $ip, $window);
            $count = (int) get_transient($key);

            if ($count >= $limit) {
                $wait = max($wait, $this->secondsLeft($window));

                continue;
            }

            $pending[$key] = [$count + 1, $window];
        }

        if ($wait > 0) {
            // A refused attempt is not recorded.
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
    public function globalLimits(): array
    {
        $defaults = [
            'per_minute' => self::DEFAULT_GLOBAL_PER_MINUTE,
            'per_hour'   => self::DEFAULT_GLOBAL_PER_HOUR,
        ];

        /**
         * Filters how many submission requests one address may send to the
         * plugin as a whole, whichever integration they name.
         *
         * Set either value to 0 to disable that window.
         *
         * @param array{per_minute:int, per_hour:int} $limits Current limits.
         */
        return $this->normalize(apply_filters('jotform_bridge_global_rate_limits', $defaults), $defaults);
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
        return $this->normalize(apply_filters('jotform_bridge_rate_limits', $defaults, $slug), $defaults);
    }

    /**
     * @param mixed                               $filtered
     * @param array{per_minute:int, per_hour:int} $defaults
     *
     * @return array{per_minute:int, per_hour:int}
     */
    private function normalize($filtered, array $defaults): array
    {
        if (!is_array($filtered)) {
            return $defaults;
        }

        return [
            'per_minute' => isset($filtered['per_minute'])
                ? max(0, (int) $filtered['per_minute'])
                : $defaults['per_minute'],
            'per_hour'   => isset($filtered['per_hour'])
                ? max(0, (int) $filtered['per_hour'])
                : $defaults['per_hour'],
        ];
    }

    /** Bucket key: scope, address and window, hashed so no address is stored. */
    private function key(string $scope, string $ip, int $window): string
    {
        return self::TRANSIENT_PREFIX . md5($scope . '|' . $ip . '|' . $window . '|' . $this->windowStart($window));
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
