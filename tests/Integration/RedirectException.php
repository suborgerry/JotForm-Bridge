<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

use RuntimeException;

/**
 * What wp_safe_redirect() followed by exit becomes in a test.
 *
 * Thrown from the `wp_redirect` filter, which runs before the header is sent
 * and before the caller reaches its own exit. Where the handler wanted to send
 * the administrator is most of what an admin-post action does, so it is an
 * assertion rather than an accident.
 */
final class RedirectException extends RuntimeException
{
    private string $location;

    private int $status;

    public function __construct(string $location, int $status)
    {
        parent::__construct('Redirect to ' . $location);

        $this->location = $location;
        $this->status   = $status;
    }

    public function location(): string
    {
        return $this->location;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * One query argument from the redirect target.
     */
    public function arg(string $name): ?string
    {
        $query = (string) wp_parse_url($this->location, PHP_URL_QUERY);

        parse_str($query, $args);

        return isset($args[$name]) && is_string($args[$name]) ? $args[$name] : null;
    }
}
