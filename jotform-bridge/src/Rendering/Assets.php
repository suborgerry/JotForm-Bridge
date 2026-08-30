<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Rest\SubmissionController;
use JotformBridge\Submission\Guards\Turnstile;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers and enqueues the frontend script.
 *
 * The script is registered on every front-end request but enqueued only while a
 * Jotform Bridge form is actually being rendered, so pages without a form ship
 * no extra JavaScript. It is plain ES5-compatible vanilla JS: no build step, no
 * jQuery, no dependencies.
 */
final class Assets
{
    public const HANDLE = 'jotform-bridge';

    /**
     * The challenge widget, loaded only when a form is on the page and only
     * when the site has configured it. A third-party script is not something to
     * put on pages that do not need it.
     */
    public const TURNSTILE_HANDLE = 'jotform-bridge-turnstile';

    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered || wp_script_is(self::HANDLE, 'registered')) {
            $this->registered = true;

            return;
        }

        wp_register_script(
            self::HANDLE,
            JOTFORM_BRIDGE_URL . 'assets/frontend.js',
            [],
            JOTFORM_BRIDGE_VERSION,
            true
        );

        if (Turnstile::isConfigured()) {
            // A script on Cloudflare's CDN. Appending a version of ours would
            // be a query string on somebody else's file: it would not describe
            // what is served, and would only defeat their caching.
            wp_register_script(
                self::TURNSTILE_HANDLE,
                Turnstile::SCRIPT_URL,
                [],
                // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party URL, versioned by Cloudflare.
                null,
                ['strategy' => 'defer', 'in_footer' => true]
            );
        }

        wp_localize_script(
            self::HANDLE,
            'jotformBridgeSettings',
            [
                'endpoint' => SubmissionController::endpoint(),
                'messages' => [
                    'error'       => __('The form could not be submitted. Please try again.', 'jotform-bridge'),
                    'network'     => __('The form could not be sent. Check your connection and try again.', 'jotform-bridge'),
                    'unsupported' => __('This browser cannot send this form. Please update it, or try a different one.', 'jotform-bridge'),
                ],
            ]
        );

        $this->registered = true;
    }

    /**
     * Called from the renderer, i.e. only when a form is really on the page.
     */
    public function enqueue(): void
    {
        $this->register();

        wp_enqueue_script(self::HANDLE);

        if (Turnstile::isConfigured()) {
            wp_enqueue_script(self::TURNSTILE_HANDLE);
        }
    }
}
