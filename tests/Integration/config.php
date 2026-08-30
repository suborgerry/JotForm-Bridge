<?php

/**
 * The WordPress installation the integration suite runs against.
 *
 * Included by both install.php and bootstrap.php, because the two processes
 * have to agree about every one of these constants: the second would open a
 * different database from the one the first created.
 *
 * There is no wp-config.php on disk. WordPress only requires that these
 * constants exist before wp-settings.php is included, and defining them here
 * keeps the whole configuration of the test site in one readable file instead
 * of in a generated one nobody reads.
 *
 * @package JotformBridge\Tests\Integration
 */

declare(strict_types=1);

$jfbRoot  = dirname(__DIR__, 2);
$jfbWpDir = getenv('JFB_WP_DIR');
$jfbWpDir = is_string($jfbWpDir) && $jfbWpDir !== '' ? rtrim($jfbWpDir, '/') : $jfbRoot . '/.wordpress';

if (!is_file($jfbWpDir . '/wp-settings.php')) {
    fwrite(
        STDERR,
        "error: no WordPress in {$jfbWpDir}.\n"
        . "Run bin/install-wp.sh (composer test:integration does it for you).\n"
    );

    exit(1);
}

// A file per suite rather than the drop-in's default name: a WordPress that
// somebody has also been using by hand keeps its own database beside this one.
define('DB_DIR', $jfbWpDir . '/wp-content/database/');
define('DB_FILE', 'jotform-bridge-tests.sqlite');

// Unused by the SQLite drop-in, and required by WordPress before it loads one.
define('DB_NAME', 'jotform_bridge_tests');
define('DB_USER', '');
define('DB_PASSWORD', '');
define('DB_HOST', 'localhost');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

// Fixed rather than random. A nonce is derived from these, so a suite that
// generated them per run could never assert that a nonce minted in one place
// verifies in another.
define('AUTH_KEY', 'jotform-bridge-tests-auth');
define('SECURE_AUTH_KEY', 'jotform-bridge-tests-secure-auth');
define('LOGGED_IN_KEY', 'jotform-bridge-tests-logged-in');
define('NONCE_KEY', 'jotform-bridge-tests-nonce');
define('AUTH_SALT', 'jotform-bridge-tests-auth-salt');
define('SECURE_AUTH_SALT', 'jotform-bridge-tests-secure-auth-salt');
define('LOGGED_IN_SALT', 'jotform-bridge-tests-logged-in-salt');
define('NONCE_SALT', 'jotform-bridge-tests-nonce-salt');

// The plugin reads its key from this constant and from nowhere else. It is a
// fixed fake: no test may contact Jotform, so the only thing the value has to
// do is be non-empty. A real key must never appear here — see the "Secrets and
// the test environment" section of AGENTS.md.
define('JOTFORM_API_KEY', 'integration-suite-not-a-real-key');

define('WP_SITEURL', 'https://example.test');
define('WP_HOME', 'https://example.test');

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', false);
define('WP_DEBUG_DISPLAY', false);

// Nothing in a test may reach the network, and this is the belt to the
// pre_http_request braces in TestCase: the plugin's whole upstream boundary is
// the WordPress HTTP API, so a request that escaped both would be a live call
// to Jotform from a test run.
define('WP_HTTP_BLOCK_EXTERNAL', true);

// A loopback request to wp-cron.php in the middle of a test would be exactly
// the network call the line above exists to prevent.
define('DISABLE_WP_CRON', true);

// WordPress reads these off the request. There is no request here.
$_SERVER['HTTP_HOST']       = 'example.test';
$_SERVER['SERVER_NAME']     = 'example.test';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = '/';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTPS']           = 'on';

$GLOBALS['table_prefix'] = 'wptests_';

if (!defined('ABSPATH')) {
    define('ABSPATH', $jfbWpDir . '/');
}

return [
    'root'     => $jfbRoot,
    'wp'       => $jfbWpDir,
    'db'       => DB_DIR . DB_FILE,
    'pristine' => DB_DIR . DB_FILE . '.pristine',
    'plugin'   => 'jotform-bridge/jotform-bridge.php',
];
