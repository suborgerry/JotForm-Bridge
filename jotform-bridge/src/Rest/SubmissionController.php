<?php

declare(strict_types=1);

namespace JotformBridge\Rest;

use JotformBridge\Submission\SubmissionPipeline;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The public submission endpoint.
 *
 * Thin by design: it turns an HTTP request into pipeline arguments and the
 * pipeline outcome into a response. There is no mapping, no validation and no
 * Jotform knowledge in here.
 *
 * The permission callback is open because the endpoint has to serve anonymous
 * visitors. A nonce would not protect it — it would only break page caching —
 * so the protection is the server-side validation the pipeline performs, plus
 * the spam extension point.
 */
final class SubmissionController
{
    public const NAMESPACE = 'jotform-bridge/v1';
    public const ROUTE     = '/submit/(?P<integration>[A-Za-z0-9_-]+)';

    private SubmissionPipeline $pipeline;

    public function __construct(SubmissionPipeline $pipeline)
    {
        $this->pipeline = $pipeline;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * The base URL the frontend script posts to, with the slug appended.
     */
    public static function endpoint(string $slug = ''): string
    {
        return rest_url(self::NAMESPACE . '/submit/' . $slug);
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => 'POST',
                'callback'            => [$this, 'handle'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'integration' => [
                        'type'              => 'string',
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    // Deliberately not declared required: a body without
                    // fields is a validation failure with the documented 422
                    // shape, not a differently shaped REST framework error.
                    'fields'      => [],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $outcome = $this->pipeline->submit(
            (string) $request->get_param('integration'),
            $request->get_param('fields'),
            $this->context($request)
        );

        $response = new WP_REST_Response($outcome->body(), $outcome->status());

        // A submission answer is never cacheable.
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Request metadata handed to the spam extension point.
     *
     * `spam` is a deliberately separate part of the body: a future challenge
     * token must not travel inside `fields`, where the validator would — quite
     * correctly — reject it as an unknown field.
     *
     * @return array<string, mixed>
     */
    private function context(WP_REST_Request $request): array
    {
        $spam = $request->get_param('spam');

        return [
            'ip'         => isset($_SERVER['REMOTE_ADDR'])
                ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']))
                : '',
            'user_agent' => $request->get_header('user_agent') ?? '',
            'referer'    => $request->get_header('referer') ?? '',
            'spam'       => is_array($spam) ? $spam : [],
        ];
    }
}
