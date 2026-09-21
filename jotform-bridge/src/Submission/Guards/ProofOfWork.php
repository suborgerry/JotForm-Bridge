<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Proof of work: the browser must find a nonce making
 * `sha256("slug|timestamp|nonce")` start with BITS zero bits. A missing proof
 * is refused. Checked: difficulty, recency and single use.
 */
final class ProofOfWork
{
    /** Key inside the `spam` container: "timestamp:nonce". */
    public const KEY = 'pow';

    /** Default leading zero bits; must match the frontend script. */
    public const BITS = 16;

    /** Seconds a solution stays valid; the frontend's POW_STALE must stay below it. */
    public const WINDOW = 600;

    /** Tolerance for a client clock that runs ahead. */
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

        // Single use.
        $seen = self::TRANSIENT_PREFIX . md5($slug . '|' . $timestamp . '|' . $nonce);

        if (get_transient($seen) !== false) {
            return false;
        }

        set_transient($seen, 1, self::WINDOW + self::FUTURE_SKEW);

        return $allowed;
    }

    /** Whether one candidate solution is hard enough. */
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

    /** Difficulty for one integration; `Rendering\Assets` hands the same number to the browser. */
    public static function bits(string $slug): int
    {
        /**
         * Filters how much work a submission must cost. Every extra bit
         * doubles it; the browser receives the result through
         * `jotformBridgeSettings.powBits`.
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
         * Useful only while a cached older version of the script is still served.
         *
         * @param bool   $required Whether the proof is mandatory.
         * @param string $slug     Integration slug.
         */
        return (bool) apply_filters('jotform_bridge_pow_required', true, $slug);
    }
}
