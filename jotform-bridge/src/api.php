<?php

/**
 * The public PHP API of the plugin.
 *
 * Loaded from the main plugin file rather than through the autoloader, because
 * these are plain functions in the global namespace: that is what a theme
 * developer expects to call, and it is the only part of the plugin that other
 * code is meant to depend on.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

use JotformBridge\Plugin;

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('jotform_form')) {
    /**
     * Returns the rendered HTML of one integration.
     *
     * Usage in a theme:
     *
     *     echo jotform_form('contact');
     *
     * Never throws and never prints: an unknown, disabled or misconfigured
     * integration yields an empty string for visitors and a short diagnostic for
     * administrators, so a template mistake cannot break the page.
     */
    function jotform_form(string $slug): string
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
     * The honeypot markup for a custom template.
     *
     * Templates rendered through the plugin already receive the same string as
     * `$honeypot`; this function exists for markup built outside that context.
     * The output is escaped and safe to print:
     *
     *     <?php echo jotform_bridge_honeypot(); ?>
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
     * The challenge widget markup for a custom template, if one is configured.
     *
     * Empty when the site has no challenge keys, so it is safe to print
     * unconditionally. Templates rendered through the plugin already receive
     * the same string as `$turnstile`.
     */
    function jotform_bridge_challenge(): string
    {
        return \JotformBridge\Submission\Guards\Turnstile::markup();
    }
}

if (!function_exists('jotform_bridge_endpoint')) {
    /**
     * The REST endpoint an integration submits to.
     *
     * Useful for a template that wants to set the form action itself; the
     * rendering context already provides the same value as `$endpoint`.
     */
    function jotform_bridge_endpoint(string $slug): string
    {
        return \JotformBridge\Rest\SubmissionController::endpoint(sanitize_key($slug));
    }
}
