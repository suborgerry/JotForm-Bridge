<?php

declare(strict_types=1);

namespace JotformBridge\Rest;

use JotformBridge\Submission\SubmissionPipeline;
use JotformBridge\Support\ClientIp;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The public submission endpoint: turns the request into pipeline arguments
 * and the outcome into a response. Open to anonymous visitors; the pipeline
 * does the protecting.
 */
final class SubmissionController
{
    public const NAMESPACE = 'jotform-bridge/v1';
    public const ROUTE     = '/submit/(?P<integration>[A-Za-z0-9_-]+)';

    /** Hard cap on the raw request body, checked before it is decoded. */
    public const MAX_BODY_BYTES = 262144;

    /** Longest request metadata string handed to the spam extension point. */
    private const MAX_CONTEXT_LENGTH = 512;

    /**
     * Builds the pipeline on the first submission.
     *
     * @var callable(): SubmissionPipeline
     */
    private $factory;

    private ?SubmissionPipeline $pipeline = null;

    /**
     * @param callable(): SubmissionPipeline $factory
     */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /** The URL the frontend script posts to. */
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
                    // Not required: a missing body answers with the documented 422 shape.
                    'fields'      => [],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return $this->respond(
                new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => __('The submission is too large.', 'jotform-bridge'),
                    ],
                    413
                )
            );
        }

        $outcome = $this->pipeline()->submit(
            (string) $request->get_param('integration'),
            $request->get_param('fields'),
            $this->context($request)
        );

        $response = new WP_REST_Response($outcome->body(), $outcome->status());

        foreach ($outcome->headers() as $name => $value) {
            $response->header((string) $name, (string) $value);
        }

        return $this->respond($response);
    }

    private function pipeline(): SubmissionPipeline
    {
        if ($this->pipeline === null) {
            $this->pipeline = ($this->factory)();
        }

        return $this->pipeline;
    }

    private function respond(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Request metadata handed to the spam extension point.
     *
     * @return array<string, mixed>
     */
    private function context(WP_REST_Request $request): array
    {
        $spam = $request->get_param('spam');

        return [
            'ip'         => ClientIp::resolve(),
            'user_agent' => self::header($request, 'user_agent'),
            'referer'    => self::header($request, 'referer'),
            'spam'       => is_array($spam) ? $spam : [],
        ];
    }

    private static function header(WP_REST_Request $request, string $name): string
    {
        $value = $request->get_header($name);

        if (!is_string($value)) {
            return '';
        }

        return substr(sanitize_text_field($value), 0, self::MAX_CONTEXT_LENGTH);
    }
}
