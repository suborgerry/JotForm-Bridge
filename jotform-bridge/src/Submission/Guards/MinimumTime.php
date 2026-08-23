<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Refuses a form that was filled in faster than a person can fill one in.
 *
 * The second shipped provider, and the second one that costs a visitor nothing:
 * no challenge, no third-party script, no extra request. It pairs with the
 * honeypot rather than replacing it — a bot that leaves the decoy alone still
 * has to spend real seconds on the page, and a bot that spends real seconds is
 * a much more expensive bot to run at scale.
 *
 * The measurement comes from the browser, which means it can be forged. That is
 * a deliberate trade: the alternatives are a server-side stamp in the markup,
 * which full-page caching turns into the same already-old value for everybody,
 * or a token issued per page view, which costs a request before the form is even
 * used. Neither is worth it for a check whose whole purpose is to be free.
 *
 * A submission that carries no measurement is allowed through, exactly like a
 * form with no honeypot: templates and integrations must keep working when the
 * markup has not been updated, and refusing them would break sites rather than
 * bots.
 */
final class MinimumTime
{
    /**
     * Key inside the `spam` container, in seconds.
     */
    public const KEY = 't';

    /**
     * How long a form must have been open before it may be submitted.
     *
     * Two seconds is under what it takes to read a single label, and well above
     * anything a person could trip over — including somebody pasting a prepared
     * answer, which is the case a longer threshold would punish.
     */
    public const MIN_SECONDS = 2;

    /**
     * Beyond this the number says nothing useful, and is treated as absent: a
     * tab left open overnight is not evidence of anything.
     */
    private const MAX_SECONDS = 86400;

    public function register(): void
    {
        add_filter(SpamGuard::FILTER, [$this, 'check'], 20, 4);
    }

    /**
     * @param bool|string                              $allowed Verdict so far.
     * @param array<string, string|array<int, string>> $values  Sanitized values.
     * @param array<string, mixed>                     $context Request metadata.
     *
     * @return bool|string
     */
    public function check($allowed, string $slug, array $values, array $context)
    {
        if ($allowed !== true) {
            return $allowed;
        }

        $spam = isset($context['spam']) && is_array($context['spam']) ? $context['spam'] : [];

        if (!array_key_exists(self::KEY, $spam)) {
            return $allowed;
        }

        $raw = $spam[self::KEY];

        if (!is_numeric($raw)) {
            return false;
        }

        $seconds = (int) $raw;

        if ($seconds < 0 || $seconds > self::MAX_SECONDS) {
            return $allowed;
        }

        return $seconds >= $this->threshold($slug) ? $allowed : false;
    }

    private function threshold(string $slug): int
    {
        /**
         * Filters how long a form must be open before it may be submitted.
         *
         * Return 0 to disable the check.
         *
         * @param int    $seconds Minimum seconds.
         * @param string $slug    Integration slug.
         */
        return max(0, (int) apply_filters('jotform_bridge_minimum_time', self::MIN_SECONDS, $slug));
    }
}
