<?php

declare(strict_types=1);

namespace JotformBridge\Support;

use JotformBridge\Settings\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/** Opt-in debug logging of technical metadata; never credentials or payloads. */
final class Logger
{
    private const HEADER = "<?php exit; ?>\n";

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

        $line = sprintf('[%s UTC][jotform-bridge][%s] %s', gmdate('Y-m-d H:i:s'), $level, $message);

        if ($context !== []) {
            $line .= ' ' . wp_json_encode($this->scrub($context));
        }

        if (!$this->write($line . PHP_EOL, false)) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('[jotform-bridge][ERROR] Could not write the plugin log file.');
        }
    }

    public function path(): string
    {
        return WP_CONTENT_DIR . '/jotform-bridge-logs.php';
    }

    public function clear(): bool
    {
        if (!file_exists($this->path())) {
            return true;
        }

        return $this->write('', true);
    }

    /** Locked append or truncation; the PHP header blocks direct web reads. */
    private function write(string $line, bool $clear): bool
    {
        $path = $this->path();

        if (is_link($path)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $file = @fopen($path, 'c+b');

        if ($file === false) {
            return false;
        }

        try {
            if (!flock($file, LOCK_EX)) {
                return false;
            }

            if ($clear && !ftruncate($file, 0)) {
                return false;
            }

            if (fseek($file, 0, SEEK_END) !== 0) {
                return false;
            }

            $data = (ftell($file) === 0 ? self::HEADER : '') . $line;
            $length = strlen($data);
            $offset = 0;

            while ($offset < $length) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                $written = fwrite($file, substr($data, $offset));

                if ($written === false || $written === 0) {
                    return false;
                }

                $offset += $written;
            }

            return fflush($file);
        } finally {
            flock($file, LOCK_UN);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($file);
        }
    }

    /**
     * Redacts secret-looking keys and the API key itself.
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
