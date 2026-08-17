<?php

declare(strict_types=1);

namespace JotformBridge\Submission;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A ready-to-serialize REST answer: status code plus response body.
 *
 * Keeping it a plain value object lets the whole pipeline be exercised in tests
 * without WordPress REST classes, and leaves the controller with nothing to do
 * but hand it to WP_REST_Response.
 */
final class SubmissionOutcome
{
    private int $status;

    /** @var array<string, mixed> */
    private array $body;

    /**
     * @param array<string, mixed> $body
     */
    private function __construct(int $status, array $body)
    {
        $this->status = $status;
        $this->body   = $body;
    }

    public static function success(string $message): self
    {
        return new self(
            200,
            [
                'success' => true,
                'message' => $message,
            ]
        );
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

    public static function error(int $status, string $message): self
    {
        return new self(
            $status,
            [
                'success' => false,
                'message' => $message,
            ]
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
