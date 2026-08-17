<?php

declare(strict_types=1);

namespace JotformBridge;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal PSR-4 autoloader.
 *
 * The release package must work right after unzipping into wp-content/plugins,
 * so the plugin ships its own autoloader instead of requiring `composer install`.
 */
final class Autoloader
{
    /**
     * @param string $prefix  Namespace prefix, e.g. "JotformBridge\".
     * @param string $baseDir Directory that maps to the prefix.
     */
    public static function register(string $prefix, string $baseDir): void
    {
        $baseDir = rtrim($baseDir, '/\\') . '/';

        spl_autoload_register(
            static function (string $class) use ($prefix, $baseDir): void {
                if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $path     = $baseDir . str_replace('\\', '/', $relative) . '.php';

                if (is_readable($path)) {
                    require_once $path;
                }
            }
        );
    }
}
