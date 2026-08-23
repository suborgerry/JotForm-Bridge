<?php

declare(strict_types=1);

namespace JotformBridge\Api;

use JotformBridge\Settings\Settings;
use JotformBridge\Support\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The only low-level layer that talks to the Jotform REST API.
 *
 * Authentication and endpoints follow the official documentation
 * (https://api.jotform.com/docs/): the key travels in the `APIKEY` header,
 * never as a query parameter, and every response uses the
 * `{responseCode, message, content}` envelope.
 */
final class JotformClient
{
    public const ERROR_NO_API_KEY   = 'no_api_key';
    public const ERROR_TRANSPORT    = 'transport_error';
    public const ERROR_HTTP_STATUS  = 'http_error';
    public const ERROR_INVALID_JSON = 'invalid_json';
    public const ERROR_API          = 'api_error';
    public const ERROR_UNEXPECTED   = 'unexpected_response';

    private const DEFAULT_TIMEOUT   = 15;
    private const FORMS_PAGE_LIMIT  = 1000;

    private string $apiKey;

    private string $baseUrl;

    private ?Logger $logger;

    private int $timeout;

    public function __construct(string $apiKey, string $baseUrl, ?Logger $logger = null, int $timeout = self::DEFAULT_TIMEOUT)
    {
        $this->apiKey  = trim($apiKey);
        $this->baseUrl = untrailingslashit(trim($baseUrl));
        $this->logger  = $logger;
        $this->timeout = $timeout;
    }

    public static function fromSettings(Settings $settings, ?Logger $logger = null): self
    {
        return new self($settings->apiKey(), $settings->baseUrl(), $logger);
    }

    /**
     * GET /user — used as a read-only connection test.
     */
    public function testConnection(): ApiResponse
    {
        $response = $this->get('/user');

        if (!$response->isSuccess()) {
            return $response;
        }

        $content = $response->data();

        return ApiResponse::success(
            [
                'username' => isset($content['username']) ? (string) $content['username'] : '',
                'email'    => isset($content['email']) ? (string) $content['email'] : '',
                'status'   => isset($content['status']) ? (string) $content['status'] : '',
            ],
            $response->status()
        );
    }

    /**
     * GET /user/usage — how much of the monthly allowance the account has spent.
     *
     * Account-wide, which is the point: the plugin's own counter only knows
     * about submissions the plugin sent, while the quota that can disable every
     * form is spent by everything — embedded forms, direct links, other
     * integrations on the same account.
     *
     * @return ApiResponse Data is `['submissions' => int]`.
     */
    public function getUsage(): ApiResponse
    {
        $response = $this->get('/user/usage');

        if (!$response->isSuccess()) {
            return $response;
        }

        $content = $response->data();

        return ApiResponse::success(
            [
                'submissions' => isset($content['submissions']) ? (int) $content['submissions'] : 0,
            ],
            $response->status()
        );
    }

    /**
     * GET /user/forms — the account form list, reduced to the fields we need.
     */
    public function getForms(): ApiResponse
    {
        $response = $this->get(
            '/user/forms',
            [
                'limit'   => self::FORMS_PAGE_LIMIT,
                'orderby' => 'title',
            ]
        );

        if (!$response->isSuccess()) {
            return $response;
        }

        $content = $response->data();
        $forms   = [];

        foreach ($content as $form) {
            if (!is_array($form) || empty($form['id'])) {
                continue;
            }

            $forms[] = [
                'id'      => (string) $form['id'],
                'title'   => isset($form['title']) ? (string) $form['title'] : '',
                'status'  => isset($form['status']) ? (string) $form['status'] : '',
                'updated' => isset($form['updated_at']) ? (string) $form['updated_at'] : '',
            ];
        }

        return ApiResponse::success($forms, $response->status());
    }

    /**
     * GET /form/{formID}/questions — the question/schema definition of one form.
     *
     * Documented at https://www.jotform.com/apidocs-v1/ ("Get form questions").
     * `content` is an object keyed by qid, so the list is re-indexed and sorted
     * by the `order` property before it leaves the client. No normalization
     * happens here: that is FieldNormalizer's job.
     *
     * @return ApiResponse Data is a list of raw question arrays.
     */
    public function getFormQuestions(string $formId): ApiResponse
    {
        $formId = trim($formId);

        if ($formId === '' || !ctype_digit($formId)) {
            return ApiResponse::failure(
                self::ERROR_UNEXPECTED,
                __('The Jotform form ID is missing or invalid.', 'jotform-bridge')
            );
        }

        $response = $this->get('/form/' . $formId . '/questions');

        if (!$response->isSuccess()) {
            return $response;
        }

        $questions = [];

        foreach ($response->data() as $qid => $question) {
            if (!is_array($question)) {
                continue;
            }

            // The envelope key is the qid; the property is normally present too.
            if (!isset($question['qid']) || (string) $question['qid'] === '') {
                $question['qid'] = (string) $qid;
            }

            $questions[] = $question;
        }

        usort(
            $questions,
            static function (array $a, array $b): int {
                $orderA = isset($a['order']) ? (int) $a['order'] : 0;
                $orderB = isset($b['order']) ? (int) $b['order'] : 0;

                if ($orderA === $orderB) {
                    return (int) $a['qid'] <=> (int) $b['qid'];
                }

                return $orderA <=> $orderB;
            }
        );

        return ApiResponse::success($questions, $response->status());
    }

    /**
     * POST /form/{formID}/submissions — creates one submission.
     *
     * The parameter names are built by SubmissionMapper; this method only knows
     * how to put them on the wire. Jotform expects the submission parameters as
     * `application/x-www-form-urlencoded` body fields, the same encoding the
     * official client libraries use.
     *
     * @param array<string, string|array<int, string>> $params Already-mapped
     *                                                         `submission[...]` parameters.
     *
     * @return ApiResponse Data is `['submission_id' => string]` on success.
     */
    public function createSubmission(string $formId, array $params): ApiResponse
    {
        $formId = trim($formId);

        if ($formId === '' || !ctype_digit($formId)) {
            return ApiResponse::failure(
                self::ERROR_UNEXPECTED,
                __('The Jotform form ID is missing or invalid.', 'jotform-bridge')
            );
        }

        if ($params === []) {
            return ApiResponse::failure(
                self::ERROR_UNEXPECTED,
                __('The submission contains no values to send.', 'jotform-bridge')
            );
        }

        $response = $this->post('/form/' . $formId . '/submissions', $params);

        if (!$response->isSuccess()) {
            return $response;
        }

        $content = $response->data();

        return ApiResponse::success(
            [
                'submission_id' => isset($content['submissionID']) ? (string) $content['submissionID'] : '',
            ],
            $response->status()
        );
    }

    /**
     * Performs a GET request and unwraps the Jotform response envelope.
     *
     * @param array<string, scalar> $query
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        if ($this->apiKey === '') {
            return ApiResponse::failure(
                self::ERROR_NO_API_KEY,
                __('No Jotform API key is configured.', 'jotform-bridge')
            );
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url = add_query_arg($query, $url);
        }

        $response = wp_remote_get(
            $url,
            [
                'timeout' => $this->timeout,
                'headers' => [
                    'APIKEY' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]
        );

        return $this->unwrap($response, $path);
    }

    /**
     * Performs a POST request and unwraps the Jotform response envelope.
     *
     * @param array<string, string|array<int, string>> $params
     */
    public function post(string $path, array $params): ApiResponse
    {
        if ($this->apiKey === '') {
            return ApiResponse::failure(
                self::ERROR_NO_API_KEY,
                __('No Jotform API key is configured.', 'jotform-bridge')
            );
        }

        $response = wp_remote_post(
            $this->baseUrl . '/' . ltrim($path, '/'),
            [
                'timeout' => $this->timeout,
                'headers' => [
                    'APIKEY'       => $this->apiKey,
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body'    => self::encodeBody($params),
            ]
        );

        return $this->unwrap($response, $path);
    }

    /**
     * Encodes mapped parameters into a form-urlencoded body.
     *
     * A list value is repeated under the same name, which is how Jotform
     * documents multi-value answers (`submission[31][]=A&submission[31][]=B`).
     *
     * @param array<string, string|array<int, string>> $params
     */
    public static function encodeBody(array $params): string
    {
        $pairs = [];

        foreach ($params as $name => $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $item);
            }
        }

        return implode('&', $pairs);
    }

    /**
     * Turns a wp_remote_* result into an ApiResponse.
     *
     * @param array<string, mixed>|\WP_Error $response
     */
    private function unwrap($response, string $path): ApiResponse
    {
        if (is_wp_error($response)) {
            $this->log('Jotform request failed on transport level.', [
                'path'  => $path,
                'error' => $response->get_error_code(),
            ]);

            return ApiResponse::failure(
                self::ERROR_TRANSPORT,
                __('Could not reach the Jotform API. Check the site network connection and try again.', 'jotform-bridge')
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = (string) wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        if (!is_array($parsed)) {
            $this->log('Jotform returned a body that is not valid JSON.', [
                'path'   => $path,
                'status' => $status,
            ]);

            return ApiResponse::failure(
                self::ERROR_INVALID_JSON,
                __('The Jotform API returned a malformed response.', 'jotform-bridge'),
                $status
            );
        }

        if ($status < 200 || $status >= 300) {
            $this->log('Jotform returned a non-2xx status.', [
                'path'   => $path,
                'status' => $status,
            ]);

            return ApiResponse::failure(
                self::ERROR_HTTP_STATUS,
                $this->envelopeMessage($parsed, $status),
                $status
            );
        }

        // The envelope carries its own response code, which can disagree with HTTP status.
        if (isset($parsed['responseCode']) && (int) $parsed['responseCode'] !== 200) {
            $this->log('Jotform reported an API-level error.', [
                'path'          => $path,
                'status'        => $status,
                'response_code' => (int) $parsed['responseCode'],
            ]);

            return ApiResponse::failure(
                self::ERROR_API,
                $this->envelopeMessage($parsed, (int) $parsed['responseCode']),
                (int) $parsed['responseCode']
            );
        }

        if (!array_key_exists('content', $parsed) || !is_array($parsed['content'])) {
            $this->log('Jotform response has no usable content.', [
                'path'   => $path,
                'status' => $status,
            ]);

            return ApiResponse::failure(
                self::ERROR_UNEXPECTED,
                __('The Jotform API response did not contain the expected data.', 'jotform-bridge'),
                $status
            );
        }

        return ApiResponse::success($parsed['content'], $status);
    }

    /**
     * Extracts a safe, human-readable message from the response envelope.
     *
     * @param array<mixed> $parsed
     */
    private function envelopeMessage(array $parsed, int $status): string
    {
        $message = '';

        if (isset($parsed['message']) && is_string($parsed['message'])) {
            $message = trim($parsed['message']);
        }

        if ($message === '' && isset($parsed['info']) && is_string($parsed['info'])) {
            $message = trim($parsed['info']);
        }

        if ($message === '') {
            return sprintf(
                /* translators: %d: HTTP or API status code */
                __('The Jotform API returned an error (code %d).', 'jotform-bridge'),
                $status
            );
        }

        return sprintf(
            /* translators: 1: status code, 2: message returned by Jotform */
            __('Jotform error %1$d: %2$s', 'jotform-bridge'),
            $status,
            $this->stripSecrets($message)
        );
    }

    /**
     * Guards against an upstream message echoing the key back at us.
     */
    private function stripSecrets(string $message): string
    {
        if ($this->apiKey === '') {
            return $message;
        }

        return str_replace($this->apiKey, '[redacted]', $message);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function log(string $message, array $context): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, $context);
        }
    }
}
