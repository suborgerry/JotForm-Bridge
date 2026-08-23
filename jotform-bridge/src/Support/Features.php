<?php

declare(strict_types=1);

namespace JotformBridge\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Switches for parts of the plugin that are built but not yet shown.
 *
 * Not dead code and not a half-finished branch: the machinery behind each of
 * these runs, is tested, and keeps its data. What the switch controls is
 * whether it is exposed — a screen, a setting, an outbound request. Hiding
 * something this way is reversible in one line and loses nothing that was
 * collected while it was hidden.
 *
 * The alternative would have been deleting the feature and writing it again
 * later, which throws away the tests along with the code and guarantees the
 * second version is not the same as the first.
 */
final class Features
{
    /**
     * The per-integration submission tally on the admin screens.
     *
     * Counting always happens: it is cheap, and a tally that only starts when
     * somebody switches the screen on has nothing to show on the day they do.
     */
    public const STATS_UI = 'stats_ui';

    /**
     * Everything that tracks the Jotform account's monthly allowance: the
     * setting that holds its size, the periodic read of what has been spent,
     * the ceiling derived from what is left, and the warning as it runs out.
     *
     * The daily rate ceiling is deliberately not part of this. It needs no
     * account knowledge, makes no requests, and is the guard that actually
     * stops a flood.
     */
    public const ACCOUNT_QUOTA = 'account_quota';

    /**
     * @var array<string, bool>
     */
    private const DEFAULTS = [
        self::STATS_UI      => false,
        self::ACCOUNT_QUOTA => false,
    ];

    public static function enabled(string $feature): bool
    {
        $default = self::DEFAULTS[$feature] ?? false;

        /**
         * Filters whether one optional part of the plugin is exposed.
         *
         * @param bool   $enabled Current state.
         * @param string $feature Feature name.
         */
        return (bool) apply_filters('jotform_bridge_feature_enabled', $default, $feature);
    }
}
