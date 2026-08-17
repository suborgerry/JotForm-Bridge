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

        Functions\when('trailingslashit')->alias(
            static fn(string $value): string => rtrim($value, '/\\') . '/'
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

        Functions\when('sanitize_textarea_field')->alias(
            static fn($value): string => trim(strip_tags((string) $value))
        );

        Functions\when('sanitize_email')->alias(
            static fn($value): string => trim((string) filter_var((string) $value, FILTER_SANITIZE_EMAIL))
        );

        Functions\when('is_email')->alias(
            static fn($value) => filter_var((string) $value, FILTER_VALIDATE_EMAIL) !== false
                ? (string) $value
                : false
        );

        // The real esc_url_raw() drops anything outside the allowed schemes;
        // the stub reproduces that much, because the plugin relies on it.
        Functions\when('esc_url_raw')->alias(
            static function ($value, ?array $protocols = null): string {
                $value     = (string) $value;
                $protocols = $protocols ?? ['http', 'https', 'ftp', 'mailto'];
                $scheme    = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                return in_array($scheme, $protocols, true) ? $value : '';
            }
        );

        Functions\when('wp_parse_url')->alias(
            static function (string $url, int $component = -1) {
                return parse_url($url, $component);
            }
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
     * Loads a sanitized Jotform API fixture.
     *
     * @return array<string, mixed>
     */
    protected function fixture(string $name): array
    {
        $path = __DIR__ . '/Fixtures/Jotform/' . $name . '.json';

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, sprintf('Fixture %s is not valid JSON.', $name));

        return $decoded;
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
