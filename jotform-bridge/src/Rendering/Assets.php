<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Rest\SubmissionController;

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

        wp_localize_script(
            self::HANDLE,
            'jotformBridgeSettings',
            [
                'endpoint' => SubmissionController::endpoint(),
                'messages' => [
                    'error'   => __('The form could not be submitted. Please try again.', 'jotform-bridge'),
                    'network' => __('The form could not be sent. Check your connection and try again.', 'jotform-bridge'),
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
    }
}
