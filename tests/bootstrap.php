<?php

/**
 * PHPUnit bootstrap.
 *
 * Domain tests run without WordPress: Brain Monkey stubs the WordPress
 * functions, and this file only provides the constants and classes that cannot
 * be expressed as function stubs.
 *
 * @package JotformBridge\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// The plugin files bail out unless they are loaded inside WordPress.
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

// Normally defined by the main plugin file, which is not loaded in unit tests.
//
// Read out of the plugin header rather than written down again. SchemaRepository
// compares a stored schema's version against this constant to decide whether to
// report it as synced by an older release, and LifecycleTest exercises the
// upgrade path — so a literal here that had drifted from the real version would
// leave those tests asserting against a number no release ever carried.
// The plugin derives it from the same header with get_file_data(), which is a
// WordPress function and so is not available here; this is the same read
// without WordPress.
if (!defined('JOTFORM_BRIDGE_VERSION')) {
    $jfbHeader = (string) file_get_contents(__DIR__ . '/../jotform-bridge/jotform-bridge.php');

    if (preg_match('/^\s*\*\s*Version:\s*(\S+)$/m', $jfbHeader, $jfbMatches) !== 1) {
        fwrite(STDERR, "error: no Version: header in jotform-bridge/jotform-bridge.php\n");

        exit(1);
    }

    define('JOTFORM_BRIDGE_VERSION', $jfbMatches[1]);

    unset($jfbHeader, $jfbMatches);
}

if (!defined('JOTFORM_BRIDGE_URL')) {
    define('JOTFORM_BRIDGE_URL', 'https://example.test/wp-content/plugins/jotform-bridge/');
}

// What the update check tells WordPress a release requires.
if (!defined('JOTFORM_BRIDGE_MIN_PHP')) {
    define('JOTFORM_BRIDGE_MIN_PHP', '8.0');
}

if (!defined('JOTFORM_BRIDGE_MIN_WP')) {
    define('JOTFORM_BRIDGE_MIN_WP', '6.4');
}

if (!class_exists('WP_Error')) {
    /**
     * Minimal stand-in for the WordPress error object.
     */
    class WP_Error
    {
        /** @var string */
        private $code;

        /** @var string */
        private $message;

        public function __construct(string $code = '', string $message = '')
        {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }
}

if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/jfb-unit-' . getmypid());
    mkdir(WP_CONTENT_DIR);
    register_shutdown_function(static function (): void {
        @unlink(WP_CONTENT_DIR . '/jotform-bridge-logs.php');
        rmdir(WP_CONTENT_DIR);
    });
}
