<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/** A ready-to-serialize REST answer: status code, body and extra headers. */
final class SubmissionOutcome
{
    private int $status;

    /** @var array<string, mixed> */
    private array $body;

    /** @var array<string, string> Extra response headers. */
    private array $headers;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    private function __construct(int $status, array $body, array $headers = [])
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
    }

    /**
     * `redirect` is present only when the target resolved.
     *
     * @param array{url:string, delay:int}|null $redirect
     */
    public static function success(string $message, ?array $redirect = null): self
    {
        $body = [
            'success' => true,
            'message' => $message,
        ];

        if ($redirect !== null && $redirect['url'] !== '') {
            $body['redirect'] = [
                'url'   => $redirect['url'],
                'delay' => max(0, (int) $redirect['delay']),
            ];
        }

        return new self(200, $body);
    }

    /**
     * @param array<string, string> $errors
     */
    public static function invalid(string $message, array $errors): self
    {
        return new self(
            422,
            [
                'success' => false,
                'message' => $message,
                'errors'  => $errors,
            ]
        );
    }

    /**
     * @param array<string, string> $headers Extra headers, e.g. Retry-After.
     */
    public static function error(int $status, string $message, array $headers = []): self
    {
        return new self(
            $status,
            [
                'success' => false,
                'message' => $message,
            ],
            $headers
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return isset($this->body['errors']) && is_array($this->body['errors']) ? $this->body['errors'] : [];
    }
}
