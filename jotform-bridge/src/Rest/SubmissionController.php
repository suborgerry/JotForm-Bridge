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
 * The public submission endpoint.
 *
 * Thin by design: it turns an HTTP request into pipeline arguments and the
 * pipeline outcome into a response. There is no mapping, no validation and no
 * Jotform knowledge in here.
 *
 * The permission callback is open because the endpoint has to serve anonymous
 * visitors. A nonce would not protect it — it would only break page caching —
 * so the protection is the server-side validation the pipeline performs, plus
 * the rate limit, the quota guard and the spam extension point it runs first.
 */
final class SubmissionController
{
    public const NAMESPACE = 'jotform-bridge/v1';
    public const ROUTE     = '/submit/(?P<integration>[A-Za-z0-9_-]+)';

    /**
     * Hard cap on the raw request body, refusing it before it is decoded.
     *
     * Not the earliest possible check, and deliberately not. By the time any of
     * our code runs, WordPress has already read the whole body from php://input;
     * what this saves is the JSON decode of something we were going to refuse.
     * The bound that actually protects the server is PHP's own post_max_size,
     * which acts before PHP code runs at all.
     *
     * Checking Content-Length on rest_pre_dispatch would run earlier still, and
     * was considered and rejected: the header is supplied by the client, and a
     * chunked request carries none, so the check below would have to stay
     * anyway. Two checks, and the earlier one only ever catches the honest
     * caller — which is not the case worth defending against.
     *
     * The validator has its own, tighter limit on the values it accepts. This
     * one is the coarse outer rail: a body this size is not a form submission.
     */
    public const MAX_BODY_BYTES = 262144;

    /**
     * Longest request metadata string handed to the spam extension point.
     */
    private const MAX_CONTEXT_LENGTH = 512;

    /**
     * Built on the first submission rather than handed in ready-made.
     *
     * The route has to be registered on every request; the machinery behind it
     * is needed on almost none of them. A factory keeps that difference honest
     * — and keeps a mistake inside the pipeline from taking down requests that
     * would never have touched it.
     *
     * @var callable(): SubmissionPipeline
     */
    private $factory;

    private ?SubmissionPipeline $pipeline = null;

    /**
     * @param callable(): SubmissionPipeline|SubmissionPipeline $pipeline
     */
    public function __construct($pipeline)
    {
        if ($pipeline instanceof SubmissionPipeline) {
            $this->pipeline = $pipeline;
            $this->factory  = static fn(): SubmissionPipeline => $pipeline;

            return;
        }

        $this->factory = $pipeline;
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

        // Retry-After on a refusal, and whatever a later guard needs to add.
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

    /**
     * A submission answer is never cacheable, whichever way it went.
     */
    private function respond(WP_REST_Response $response): WP_REST_Response
    {
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
            // Resolved in one place, and only from a source the site owner has
            // said is trustworthy.
            'ip'         => ClientIp::resolve(),
            // Client-controlled headers, sanitized and bounded before any
            // extension callback — or a log line — ever sees them.
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
