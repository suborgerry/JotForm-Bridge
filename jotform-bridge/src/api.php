<?php

/**
 * The public PHP API of the plugin: plain functions in the global namespace.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('jotform_bridge_render')) {
    /**
     * Returns the rendered HTML of one integration:
     *
     *     echo jotform_bridge_render('contact');
     *
     * Never throws. A misconfigured integration yields '' for visitors and a
     * short diagnostic for administrators.
     */
    function jotform_bridge_render(string $slug): string
    {
        try {
            return Plugin::instance()->renderer()->render($slug);
        } catch (\Throwable $error) {
            return '';
        }
    }
}

if (!function_exists('jotform_bridge_honeypot')) {
    /**
     * The honeypot markup, escaped and safe to print. Templates rendered by the
     * plugin receive the same string as `$honeypot`.
     */
    function jotform_bridge_honeypot(string $slug = ''): string
    {
        $slug = sanitize_key($slug);

        return \JotformBridge\Submission\Guards\Honeypot::markup(
            $slug !== '' ? 'jfb-' . $slug : 'jfb'
        );
    }
}

if (!function_exists('jotform_bridge_challenge')) {
    /**
     * The challenge widget markup; '' when no keys are configured. Templates
     * rendered by the plugin receive the same string as `$turnstile`.
     */
    function jotform_bridge_challenge(): string
    {
        return \JotformBridge\Submission\Guards\Turnstile::markup();
    }
}

if (!function_exists('jotform_bridge_endpoint')) {
    /**
     * The REST endpoint an integration submits to; the same value as `$endpoint`.
     */
    function jotform_bridge_endpoint(string $slug): string
    {
        return \JotformBridge\Rest\SubmissionController::endpoint(sanitize_key($slug));
    }
}
