<?php

declare(strict_types=1);

namespace JotformBridge\Rendering;

use JotformBridge\Rest\SubmissionController;
use JotformBridge\Submission\Guards\ProofOfWork;
use JotformBridge\Submission\Guards\Turnstile;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the frontend script on every request and enqueues it only while a
 * form is rendered.
 */
final class Assets
{
    public const HANDLE = 'jotform-bridge';

    /** The challenge widget; loaded only with a form and only when configured. */
    public const TURNSTILE_HANDLE = 'jotform-bridge-turnstile';

    private bool $registered = false;

    /**
     * Proof-of-work difficulty per integration slug, filled in as forms render.
     *
     * @var array<string, int>
     */
    private array $powBits = [];

    /** @var array<string, array{rules:array<int, array<string, string>>, required:array<int, string>}> */
    private array $conditions = [];

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
            wp_register_script(
                self::TURNSTILE_HANDLE,
                Turnstile::SCRIPT_URL,
                [],
                // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- third-party URL, versioned by Cloudflare.
                null,
                ['strategy' => 'defer', 'in_footer' => true]
            );
        }

        $this->localize();

        $this->registered = true;
    }

    /**
     * Called from the renderer, only when a form is on the page.
     *
     * @param string $slug The integration being rendered.
     * @param array<int, array<string, string>> $rules Conditional rules.
     * @param array<int, string> $required Required schema paths.
     */
    public function enqueue(string $slug = '', array $rules = [], array $required = []): void
    {
        $this->register();

        if ($slug !== '' && !isset($this->powBits[$slug])) {
            $this->powBits[$slug] = ProofOfWork::bits($slug);

            $this->localize();
        }

        if ($slug !== '' && $rules !== []) {
            $this->conditions[$slug] = ['rules' => $rules, 'required' => $required];
            $this->localize();
        }

        wp_enqueue_script(self::HANDLE);

        if (Turnstile::isConfigured()) {
            wp_enqueue_script(self::TURNSTILE_HANDLE);
        }
    }

    /** Localizes the script data; called again for each new form on the page. */
    private function localize(): void
    {
        // wp_localize_script() prepends; clearing first replaces instead.
        wp_scripts()->add_data(self::HANDLE, 'data', '');

        wp_localize_script(
            self::HANDLE,
            'jotformBridgeSettings',
            [
                'endpoint' => SubmissionController::endpoint(),
                'powBits'  => (object) $this->powBits,
                'conditions' => (object) $this->conditions,
                'messages' => [
                    'required'    => __('(required)', 'jotform-bridge'),
                    'error'       => __('The form could not be submitted. Please try again.', 'jotform-bridge'),
                    'network'     => __('The form could not be sent. Check your connection and try again.', 'jotform-bridge'),
                    'unsupported' => __('This browser cannot send this form. Please update it, or try a different one.', 'jotform-bridge'),
                ],
            ]
        );
    }
}
