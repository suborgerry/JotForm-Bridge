<?php

declare(strict_types=1);

namespace JotformBridge\Submission\Guards;

use JotformBridge\Submission\SpamGuard;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cloudflare Turnstile challenge. Off unless both constants are defined in
 * wp-config.php:
 *
 *     define( 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY', '0x4...' );
 *     define( 'JOTFORM_BRIDGE_TURNSTILE_SECRET',   '0x4...' );
 *
 * A submission without a token is refused, so the page cache has to be
 * flushed after enabling it.
 */
final class Turnstile
{
    /** Key inside the `spam` container. */
    public const KEY = 'turnstile';

    public const SITE_KEY_CONSTANT = 'JOTFORM_BRIDGE_TURNSTILE_SITE_KEY';
    public const SECRET_CONSTANT   = 'JOTFORM_BRIDGE_TURNSTILE_SECRET';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const TIMEOUT = 5;

    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /** Registers only when both keys are configured. */
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
             * Useful only while old markup is still served from a cache.
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
            return $this->onUnavailable($slug) ? $allowed : $this->rejection();
        }

        return $verdict ? $allowed : $this->rejection();
    }

    /** The widget markup, or '' when no keys are configured. */
    public static function markup(): string
    {
        if (!self::isConfigured()) {
            return '';
        }

        // Turnstile creates its own hidden input inside the container.
        return sprintf(
            '<div class="cf-turnstile jfb-turnstile" data-jotform-spam="%1$s" data-sitekey="%2$s"></div>',
            esc_attr(self::KEY),
            esc_attr(self::siteKey())
        );
    }

    /**
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

    /** Whether to fail open when the verification service is unavailable. */
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
