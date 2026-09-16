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

    /**
     * Proof-of-work difficulty per integration slug, for the forms on this page.
     *
     * The guard evaluates `jotform_bridge_pow_bits` per slug, so the browser
     * cannot be told a single number: two integrations on one page may be worth
     * different amounts of work. Filled in as forms render, which is why the
     * localized data is rewritten on every enqueue rather than once.
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

        $this->localize();

        $this->registered = true;
    }

    /**
     * Called from the renderer, i.e. only when a form is really on the page.
     *
     * @param string $slug The integration being rendered, so the script can be
     *                     told what its proof of work has to cost.
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

    /**
     * Hands the script everything it may not invent for itself.
     *
     * Called again for each new form on the page, because the difficulty map
     * only becomes complete as they render. Each call replaces the data rather
     * than adding to it: wp_localize_script() prepends to whatever is already
     * there, so localizing twice would print two assignments to the same
     * variable and leave the earlier one as dead weight in the markup.
     *
     * The difficulty has to arrive this way and not as a constant in the
     * script. It used to be written into `assets/frontend.js` by hand, which
     * meant `jotform_bridge_pow_bits` moved the server and left the browser
     * where it was: filtering it upwards refused every submission on the site,
     * and filtering it downwards changed nothing at all while still charging
     * the visitor the old, higher cost.
     */
    private function localize(): void
    {
        // localize() prepends to existing data; an empty string is nothing to
        // prepend to, so this replaces rather than accumulates.
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
