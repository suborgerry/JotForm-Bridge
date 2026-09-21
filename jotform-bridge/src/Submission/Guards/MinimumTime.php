<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Refuses a form submitted faster than MIN_SECONDS after it was first touched.
 * The measurement comes from the browser. An absent value is allowed; a value
 * the script could not have produced is refused.
 */
final class MinimumTime
{
    /** Key inside the `spam` container, in seconds. */
    public const KEY = 't';

    /** Seconds a form must have been open before it may be submitted. */
    public const MIN_SECONDS = 2;

    /** Beyond this the value is treated as absent. */
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

        // The script clamps at zero, so a negative value is a forgery.
        if ($seconds < 0) {
            return false;
        }

        if ($seconds > self::MAX_SECONDS) {
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
