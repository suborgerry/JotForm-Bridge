<?php

declare(strict_types=1);

namespace JotformBridge\Support;

use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Opt-in debug logging.
 *
 * Only technical metadata is logged. Credentials and full submission payloads
 * must never reach the log.
 */
final class Logger
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->log('DEBUG', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        if (!$this->settings->debugEnabled()) {
            return;
        }

        $line = sprintf('[jotform-bridge][%s] %s', $level, $message);

        if ($context !== []) {
            $line .= ' ' . wp_json_encode($this->scrub($context));
        }

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log($line);
    }

    /**
     * Defensive filter so a careless caller cannot leak the API key.
     *
     * @param array<string, scalar|null> $context
     *
     * @return array<string, scalar|null>
     */
    private function scrub(array $context): array
    {
        $blocked = ['api_key', 'apikey', 'key', 'password', 'token', 'secret'];
        $apiKey  = $this->settings->apiKey();

        foreach ($context as $name => $value) {
            if (in_array(strtolower((string) $name), $blocked, true)) {
                $context[$name] = '[redacted]';
                continue;
            }

            if ($apiKey !== '' && is_string($value) && str_contains($value, $apiKey)) {
                $context[$name] = str_replace($apiKey, '[redacted]', $value);
            }
        }

        return $context;
    }
}
