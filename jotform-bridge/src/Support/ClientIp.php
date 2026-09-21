<?php

declare(strict_types=1);

namespace JotformBridge\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the visitor address. `REMOTE_ADDR` by default; a forwarded header
 * only when the site owner names one:
 *
 *     define( 'JOTFORM_BRIDGE_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );
 */
final class ClientIp
{
    /** Constant naming the forwarded header to trust, if any. */
    public const HEADER_CONSTANT = 'JOTFORM_BRIDGE_TRUSTED_PROXY_HEADER';

    /**
     * @return string A valid IP address, or an empty string when there is none.
     */
    public static function resolve(): string
    {
        $header = self::trustedHeader();

        if ($header !== '' && isset($_SERVER[$header])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP below.
            $forwarded = self::firstAddress((string) wp_unslash($_SERVER[$header]));

            if ($forwarded !== '') {
                return $forwarded;
            }
        }

        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return '';
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated as an IP below.
        return self::validate((string) wp_unslash($_SERVER['REMOTE_ADDR']));
    }

    /**
     * The `$_SERVER` key of the configured header, or ''. Accepts the HTTP form
     * (`CF-Connecting-IP`) and the CGI form (`HTTP_CF_CONNECTING_IP`).
     */
    public static function trustedHeader(): string
    {
        if (!defined(self::HEADER_CONSTANT)) {
            return '';
        }

        $raw = trim((string) constant(self::HEADER_CONSTANT));

        if ($raw === '') {
            return '';
        }

        $normalized = strtoupper(str_replace('-', '_', $raw));

        if (strpos($normalized, 'HTTP_') !== 0) {
            $normalized = 'HTTP_' . $normalized;
        }

        return preg_match('/^[A-Z0-9_]+$/', $normalized) === 1 ? $normalized : '';
    }

    /** The left-most valid address of a comma-separated forwarded chain. */
    private static function firstAddress(string $raw): string
    {
        foreach (explode(',', $raw) as $candidate) {
            $valid = self::validate(trim($candidate));

            if ($valid !== '') {
                return $valid;
            }
        }

        return '';
    }

    private static function validate(string $candidate): string
    {
        $candidate = trim($candidate);

        return filter_var($candidate, FILTER_VALIDATE_IP) === false ? '' : $candidate;
    }
}
