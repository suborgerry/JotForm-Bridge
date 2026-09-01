<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Caps how often one address may post to the submission endpoint.
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
 * There are two scopes. The site-wide one is charged before the integration is
 * even looked up, so that probing the endpoint for valid slugs costs the same
 * as submitting: without it, an unknown slug answers 404 for free and the whole
 * guard can be sidestepped by never naming a real form. The per-integration one
 * is charged after, and is the tighter of the two.
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

    /**
     * Submissions allowed from one address per minute.
     *
     * Five, because a person who genuinely needs to send the same form twice
     * in a minute exists — a typo spotted immediately, a second enquiry about
     * a different thing — and a person who needs to send it six times does
     * not. The minute window is what stops a burst; the hour below is what
     * stops a slow drip that never trips it.
     */
    public const DEFAULT_PER_MINUTE = 5;

    /**
     * Submissions allowed from one address per hour.
     *
     * Deliberately far below six times the per-minute allowance. A source
     * sending steadily just under the minute limit for an hour is not a
     * visitor, whatever any single minute of it looks like.
     */
    public const DEFAULT_PER_HOUR = 30;

    /**
     * Requests allowed from one address per minute across every integration.
     *
     * Looser than the per-integration budget, because a site may legitimately
     * have several forms on one page, and tighter than the sum of them, because
     * nobody fills in six different forms in a minute.
     */
    public const DEFAULT_GLOBAL_PER_MINUTE = 15;

    /**
     * Requests allowed from one address per hour across every integration.
     *
     * Not the sum of the per-integration hourly budgets, and not meant to be:
     * a site with six forms does not have visitors who use six forms. This is
     * the ceiling on what one address can cost the server in an hour, whatever
     * it names.
     */
    public const DEFAULT_GLOBAL_PER_HOUR = 60;

    /**
     * Bucket name for the site-wide scope. Not a valid slug, so it can never
     * collide with a per-integration bucket.
     */
    private const GLOBAL_SCOPE = '*';

    private const MINUTE = 60;
    private const HOUR   = 3600;

    /**
     * The site-wide budget, charged before the integration is resolved.
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
            // Without an address every visitor would share one bucket, and the
            // guard would turn into a site-wide outage the first time a burst
            // arrived. Refusing to guess is the safer failure.
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

    /**
     * The bucket key: scope, address and window, hashed.
     *
     * Only a hash is stored, so no address ever ends up in the options table —
     * the same rule the duplicate guard already follows.
     */
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
