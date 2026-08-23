<?php

declare(strict_types=1);

namespace JotformBridge\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolves the address a submission came from.
 *
 * `REMOTE_ADDR` is the only source trusted by default, because it is the only
 * one the site cannot be lied to about. Behind a CDN or a reverse proxy it is
 * the proxy's address — every visitor then shares one bucket — so a forwarded
 * header can be used instead, but only when the site owner has said which one:
 *
 *     define( 'JOTFORM_BRIDGE_TRUSTED_PROXY_HEADER', 'CF-Connecting-IP' );
 *
 * That opt-in is the whole point. A forwarded header is client-controlled: read
 * unconditionally, it would let an attacker not only rotate past a rate limit
 * but also spend somebody else's budget by claiming their address. Only the
 * person who knows what sits in front of the site knows whether the header can
 * be believed, so the plugin refuses to guess — the same rule the API key
 * follows.
 */
final class ClientIp
{
    /**
     * Constant naming the forwarded header to trust, if any.
     */
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
     * The `$_SERVER` key of the configured header, or an empty string.
     *
     * Accepts either the HTTP form (`CF-Connecting-IP`) or the CGI form
     * (`HTTP_CF_CONNECTING_IP`), because both spellings are what people have in
     * their notes.
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

    /**
     * The left-most address of a comma-separated forwarded chain.
     *
     * That entry is the one closest to the visitor. It is also the one a
     * visitor can forge, which is exactly why reading the header at all is
     * opt-in.
     */
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
