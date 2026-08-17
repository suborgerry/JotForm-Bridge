<?php

declare(strict_types=1);

namespace JotformBridge\Api;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Result of a Jotform API call.
 *
 * Deliberately not a WP_Error: the domain layer stays testable without
 * WordPress, and the admin layer decides how to present the failure.
 */
final class ApiResponse
{
    private bool $success;

    /** @var array<mixed> */
    private array $data;

    private string $errorCode;

    private string $errorMessage;

    private ?int $status;

    /**
     * @param array<mixed> $data
     */
    private function __construct(
        bool $success,
        array $data,
        string $errorCode,
        string $errorMessage,
        ?int $status
    ) {
        $this->success      = $success;
        $this->data         = $data;
        $this->errorCode    = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->status       = $status;
    }

    /**
     * @param array<mixed> $data
     */
    public static function success(array $data, ?int $status = null): self
    {
        return new self(true, $data, '', '', $status);
    }

    public static function failure(string $code, string $message, ?int $status = null): self
    {
        return new self(false, [], $code, $message, $status);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<mixed>
     */
    public function data(): array
    {
        return $this->data;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function errorMessage(): string
    {
        return $this->errorMessage;
    }

    public function status(): ?int
    {
        return $this->status;
    }
}
