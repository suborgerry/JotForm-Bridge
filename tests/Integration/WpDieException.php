<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

use RuntimeException;

/**
 * What wp_die() becomes in a test.
 *
 * The refusals worth testing — a missing capability, a bad nonce — all end in
 * wp_die(), which on a real request ends the request. Here it has to end the
 * handler and nothing else, so the die handler is filtered to throw this and
 * the test asserts on what it carries.
 */
final class WpDieException extends RuntimeException
{
    private string $body;

    private int $status;

    public function __construct(string $body, int $status)
    {
        parent::__construct($body === '' ? 'wp_die()' : $body);

        $this->body   = $body;
        $this->status = $status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * The HTTP status wp_die() was given, or 0 when it was given none.
     */
    public function status(): int
    {
        return $this->status;
    }
}
