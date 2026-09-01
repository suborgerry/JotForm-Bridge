<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes every submission cost a little processor time.
 *
 * The other free providers ask a bot to be careless: fill in a hidden field,
 * or send faster than a person could type. A bot that is not careless walks
 * past both. The paid one, Turnstile, does stop it — but only on sites whose
 * owner went and got keys, which most never will.
 *
 * This is the layer that needs nothing from anybody. Before a form is sent the
 * browser has to find a number that makes
 * `sha256("slug|timestamp|nonce")` start with a run of zero bits. At sixteen
 * bits that is about 65,000 hashes: unnoticeable once, and ruinous at the scale
 * spam is only worth sending at. It does not identify anyone, it does not ask
 * the visitor to do anything, and it involves no third party.
 *
 * Unlike the honeypot and the timing check, a missing proof is refused. Those
 * two are hints in the markup that an old template may not carry; this one
 * comes from the plugin's own script on every form it renders, so the only way
 * to arrive without it is to skip the script — which is exactly what a bot
 * posting straight to the endpoint does. Allowing that would leave the hole
 * these providers exist to close.
 *
 * Three things are checked, and the third is the one people forget: the hash
 * has to be hard enough, the timestamp has to be recent, and the same solution
 * cannot be used twice. Without the last one a bot would solve once and send
 * for as long as the window lasted.
 *
 * A determined attacker can compute solutions ahead of time — the format is
 * public and there is no server secret in it. That is deliberate: binding to a
 * secret would mean putting a per-visitor value in the page, which full-page
 * caching turns into the same value for everybody, or fetching one per view,
 * which costs a request before the form is even used. And precomputation does
 * not actually help: it moves the cost earlier without reducing it, and the
 * cost per submission is the entire point.
 */
final class ProofOfWork
{
    /** Key inside the `spam` container: "timestamp:nonce". */
    public const KEY = 'pow';

    /**
     * Leading zero bits required. Must match the frontend script.
     */
    public const BITS = 16;

    /**
     * How old a solution may be. Generous, because it is computed when the
     * visitor starts filling the form in rather than when they submit it.
     *
     * The frontend recomputes at POW_STALE, 240 seconds, and that has to stay
     * comfortably below this: a browser reusing a solution the guard has aged
     * out gets a refusal it cannot explain to the visitor. Lowering this value
     * without lowering that one is how a form starts failing for anybody who
     * takes more than a few minutes to fill it in.
     */
    public const WINDOW = 600;

    /**
     * Tolerance for a client clock that runs ahead.
     */
    private const FUTURE_SKEW = 120;

    public const TRANSIENT_PREFIX = 'jotform_bridge_pow_';

    public function register(): void
    {
        add_filter(SpamGuard::FILTER, [$this, 'check'], 15, 4);
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
        if ($allowed !== true || !$this->isRequired($slug)) {
            return $allowed;
        }

        $spam  = isset($context['spam']) && is_array($context['spam']) ? $context['spam'] : [];
        $proof = isset($spam[self::KEY]) && is_scalar($spam[self::KEY]) ? trim((string) $spam[self::KEY]) : '';

        if ($proof === '' || preg_match('/^(\d{1,12}):(\d{1,12})$/', $proof, $parts) !== 1) {
            return false;
        }

        $timestamp = (int) $parts[1];
        $nonce     = (int) $parts[2];
        $now       = time();

        if ($timestamp > $now + self::FUTURE_SKEW || $timestamp < $now - self::WINDOW) {
            return false;
        }

        if (!self::meets($slug, $timestamp, $nonce, self::bits($slug))) {
            return false;
        }

        // Solve once, send once. The transient outlives the window the
        // timestamp check accepts, so there is no gap between the two.
        $seen = self::TRANSIENT_PREFIX . md5($slug . '|' . $timestamp . '|' . $nonce);

        if (get_transient($seen) !== false) {
            return false;
        }

        set_transient($seen, 1, self::WINDOW + self::FUTURE_SKEW);

        return $allowed;
    }

    /**
     * Whether one candidate solution is hard enough.
     */
    public static function meets(string $slug, int $timestamp, int $nonce, int $bits = self::BITS): bool
    {
        $hash = hash('sha256', $slug . '|' . $timestamp . '|' . $nonce, true);

        $whole = intdiv($bits, 8);

        for ($i = 0; $i < $whole; $i++) {
            if (ord($hash[$i]) !== 0) {
                return false;
            }
        }

        $rest = $bits % 8;

        return $rest === 0 || (ord($hash[$whole]) >> (8 - $rest)) === 0;
    }

    /**
     * How much work this integration's submissions must cost.
     *
     * Public and static because the browser has to be told the same number, and
     * `Rendering\Assets` asks this method rather than reading the constant: two
     * places evaluating the same filter is how the numbers drift apart, and
     * they used not to be asked at all.
     *
     * The floor and the ceiling are the range in which the guard is still a
     * guard. Below about eight bits the work is free even for a script that
     * solves it once per submission; above 24 a mid-range phone takes long
     * enough that people abandon the form, and refusing real visitors is the
     * failure mode this whole layer exists to avoid.
     */
    public static function bits(string $slug): int
    {
        /**
         * Filters how much work a submission must cost.
         *
         * Every extra bit doubles it. The browser is told the result through
         * `jotformBridgeSettings.powBits`, so a filter here changes both sides
         * and needs nothing done to the script.
         *
         * @param int    $bits Leading zero bits required.
         * @param string $slug Integration slug.
         */
        return max(1, min(24, (int) apply_filters('jotform_bridge_pow_bits', self::BITS, $slug)));
    }

    private function isRequired(string $slug): bool
    {
        /**
         * Filters whether a submission without a proof of work is refused.
         *
         * Turning this off removes the only barrier in front of a bot that
         * posts straight to the endpoint without running any of the page's
         * JavaScript. The one good reason to do it is a site still serving a
         * cached copy of an older version of the plugin's script.
         *
         * @param bool   $required Whether the proof is mandatory.
         * @param string $slug     Integration slug.
         */
        return (bool) apply_filters('jotform_bridge_pow_required', true, $slug);
    }
}
