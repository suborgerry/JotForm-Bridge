<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare Turnstile: the layer that works against a real browser.
 *
 * The honeypot and the timing check are free and catch scripts. Neither one
 * catches something driving an actual browser engine, and going headless is
 * precisely what removed Jotform's own answer to that — the CAPTCHA it inserts
 * on its hosted forms when it sees a burst. This puts an equivalent back, and a
 * better one: it is always on rather than appearing when somebody else's
 * heuristics decide it should.
 *
 * It is the only provider here that costs something, which is why it is the
 * only one that is off unless asked for. Enabling it means two constants in
 * wp-config.php — the same place, and the same reasoning, as the API key:
 *
 *     define( 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY', '0x4...' );
 *     define( 'JOTFORM_BRIDGE_TURNSTILE_SECRET',   '0x4...' );
 *
 * The costs are real and worth stating. Verification is a second outbound
 * request in the submission path, on a short timeout. The widget is a
 * third-party script, which some sites cannot accept. And a submission without
 * a token is refused — unlike the honeypot, a challenge that can be skipped by
 * omitting a field is not a challenge at all — which means **the page cache has
 * to be flushed after enabling this**, or visitors served the old markup will
 * be turned away.
 */
final class Turnstile
{
    /** Key inside the `spam` container. */
    public const KEY = 'turnstile';

    public const SITE_KEY_CONSTANT = 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY';
    public const SECRET_CONSTANT   = 'JOTFORM_BRIDGE_TURNSTILE_SECRET';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Short on purpose: a visitor is waiting, and a slow verification is worse
     * for them than the outcome of the fail policy below.
     */
    private const TIMEOUT = 5;

    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Registering is conditional, unlike the free providers: with no keys there
     * is nothing to verify against, and a check that always fails open is worse
     * than no check because it looks like protection.
     */
    public function register(): void
    {
        if (!self::isConfigured()) {
            return;
        }

        add_filter(SpamGuard::FILTER, [$this, 'check'], 30, 4);
    }

    public static function isConfigured(): bool
    {
        return self::siteKey() !== '' && self::secret() !== '';
    }

    public static function siteKey(): string
    {
        return defined(self::SITE_KEY_CONSTANT) ? trim((string) constant(self::SITE_KEY_CONSTANT)) : '';
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
        if ($allowed !== true || !self::isConfigured()) {
            return $allowed;
        }

        $spam  = isset($context['spam']) && is_array($context['spam']) ? $context['spam'] : [];
        $token = isset($spam[self::KEY]) && is_scalar($spam[self::KEY]) ? trim((string) $spam[self::KEY]) : '';

        if ($token === '') {
            /**
             * Filters whether a submission without a challenge token is refused.
             *
             * Returning false makes the challenge optional, which is only
             * sensible while old markup is still being served from a cache.
             *
             * @param bool   $required Whether the token is mandatory.
             * @param string $slug     Integration slug.
             */
            $required = (bool) apply_filters('jotform_bridge_turnstile_required', true, $slug);

            if (!$required) {
                return $allowed;
            }

            $this->log('A submission arrived without a challenge token.', ['integration' => $slug]);

            return $this->rejection();
        }

        $verdict = $this->verify($token);

        if ($verdict === null) {
            // Cloudflare could not be reached or did not answer usefully.
            return $this->onUnavailable($slug) ? $allowed : $this->rejection();
        }

        return $verdict ? $allowed : $this->rejection();
    }

    /**
     * The widget markup, or an empty string when no keys are configured.
     */
    public static function markup(): string
    {
        if (!self::isConfigured()) {
            return '';
        }

        // The attribute goes on the container: Turnstile creates its own hidden
        // input inside it, and the frontend script reads the value from there.
        return sprintf(
            '<div class="cf-turnstile jfb-turnstile" data-jotform-spam="%1$s" data-sitekey="%2$s"></div>',
            esc_attr(self::KEY),
            esc_attr(self::siteKey())
        );
    }

    /**
     * Asks Cloudflare whether the token is good.
     *
     * @return bool|null Null when the answer could not be obtained at all.
     */
    private function verify(string $token): ?bool
    {
        $response = wp_remote_post(
            self::VERIFY_URL,
            [
                'timeout' => self::TIMEOUT,
                'body'    => [
                    'secret'   => self::secret(),
                    'response' => $token,
                ],
            ]
        );

        if (is_wp_error($response)) {
            $this->log('The challenge could not be verified.', [
                'error' => $response->get_error_code(),
            ]);

            return null;
        }

        $parsed = json_decode((string) wp_remote_retrieve_body($response), true);

        if (!is_array($parsed) || !array_key_exists('success', $parsed)) {
            $this->log('The challenge verification returned an unusable answer.', []);

            return null;
        }

        return (bool) $parsed['success'];
    }

    /**
     * What to do when the verification service itself is unavailable.
     *
     * Open by default. Both answers are bad and the site owner is the only one
     * who can weigh them: failing closed means an outage at Cloudflare becomes
     * an outage of your contact form, failing open means the protection is gone
     * for exactly as long as that outage lasts. Losing leads is the more common
     * regret, so that is the default — and it is one filter to change.
     */
    private function onUnavailable(string $slug): bool
    {
        /**
         * Filters whether submissions proceed when the challenge cannot be
         * verified.
         *
         * @param bool   $failOpen Allow the submission through.
         * @param string $slug     Integration slug.
         */
        return (bool) apply_filters('jotform_bridge_turnstile_fail_open', true, $slug);
    }

    /**
     * Visitor-facing, and deliberately actionable: unlike the other providers,
     * this one can refuse somebody who is entirely genuine.
     */
    private function rejection(): string
    {
        return __(
            'The security check did not pass. Please reload the page and try again.',
            'jotform-bridge'
        );
    }

    private static function secret(): string
    {
        return defined(self::SECRET_CONSTANT) ? trim((string) constant(self::SECRET_CONSTANT)) : '';
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function log(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, $context);
        }
    }
}
