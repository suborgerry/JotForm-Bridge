<?php

declare(strict_types=1);

namespace JotformBridge\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Base test case wiring Brain Monkey and the WordPress helpers the plugin uses.
 */
abstract class TestCase extends PHPUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubTranslationFunctions();
        Functions\stubEscapeFunctions();

        Functions\when('untrailingslashit')->alias(
            static fn(string $value): string => rtrim($value, '/')
        );

        Functions\when('add_query_arg')->alias(
            static function (array $args, string $url): string {
                $separator = str_contains($url, '?') ? '&' : '?';

                return $url . $separator . http_build_query($args);
            }
        );

        Functions\when('sanitize_text_field')->alias(
            static fn($value): string => trim(strip_tags((string) $value))
        );

        Functions\when('sanitize_key')->alias(
            static fn($value): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? ''
        );

        Functions\when('esc_url_raw')->alias(
            static fn($value): string => (string) $value
        );

        Functions\when('is_wp_error')->alias(
            static fn($thing): bool => $thing instanceof \WP_Error
        );

        Functions\when('wp_json_encode')->alias(
            static fn($data): string => (string) json_encode($data)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Builds a wp_remote_get() style response array.
     *
     * @param array<string, mixed>|string $body
     *
     * @return array<string, mixed>
     */
    protected function httpResponse(int $status, $body): array
    {
        $payload = is_string($body) ? $body : (string) json_encode($body);

        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn($payload);

        return [
            'response' => ['code' => $status],
            'body'     => $payload,
        ];
    }
}
