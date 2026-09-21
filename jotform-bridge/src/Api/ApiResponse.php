<?php

declare(strict_types=1);

namespace JotformBridge\Api;

if (!defined('ABSPATH')) {
    exit;
}

/** Result of a Jotform API call. */
final class ApiResponse
{
    private bool $success;

    /** @var array<mixed> */
    private array $data;

    private string $errorCode;

    private string $errorMessage;

    private ?int $status;

    /**
     * Facts about the call itself, e.g. the remaining daily API allowance.
     *
     * @var array<string, mixed>
     */
    private array $meta;

    /**
     * @param array<mixed>         $data
     * @param array<string, mixed> $meta
     */
    private function __construct(
        bool $success,
        array $data,
        string $errorCode,
        string $errorMessage,
        ?int $status,
        array $meta = []
    ) {
        $this->success      = $success;
        $this->data         = $data;
        $this->errorCode    = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->status       = $status;
        $this->meta         = $meta;
    }

    /**
     * @param array<mixed>         $data
     * @param array<string, mixed> $meta
     */
    public static function success(array $data, ?int $status = null, array $meta = []): self
    {
        return new self(true, $data, '', '', $status, $meta);
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function failure(string $code, string $message, ?int $status = null, array $meta = []): self
    {
        return new self(false, [], $code, $message, $status, $meta);
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

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /** Remaining daily API calls, when Jotform reported them. */
    public function limitLeft(): ?int
    {
        return isset($this->meta['limit_left']) ? (int) $this->meta['limit_left'] : null;
    }
}
