<?php

/**
 * Installs the WordPress the integration suite runs against.
 *
 * Runs in its own process, and only when there is no pristine database to copy.
 * The reason for the separate process is WP_INSTALLING: it has to be true while
 * WordPress creates its tables, it cannot be switched off afterwards, and a
 * suite running with it on would be testing a WordPress that skips its own
 * plugin loading and bypasses the option cache.
 *
 * Usage: php tests/Integration/install.php
 *
 * @package JotformBridge\Tests\Integration
 */

declare(strict_types=1);

$jfbPaths = require __DIR__ . '/config.php';

// Installing means installing: whatever a previous run left behind goes,
// including the -wal and -shm files SQLite writes beside the database.
foreach (['', '-wal', '-shm', '-journal', '.pristine'] as $jfbSuffix) {
    if (is_file($jfbPaths['db'] . $jfbSuffix)) {
        unlink($jfbPaths['db'] . $jfbSuffix);
    }
}

if (!is_dir(DB_DIR) && !mkdir(DB_DIR, 0777, true) && !is_dir(DB_DIR)) {
    fwrite(STDERR, 'error: could not create ' . DB_DIR . "\n");

    exit(1);
}

define('WP_INSTALLING', true);

// The name WordPress requires; see the note in bootstrap.php.
$table_prefix = (string) $GLOBALS['table_prefix'];

require_once ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';

// wp_install() ends by notifying the new administrator by email. There is no
// mail server here, and PHPMailer failing is not a thing this suite should ever
// have to explain.
add_filter('pre_wp_mail', '__return_true');

$jfbInstalled = wp_install(
    'Jotform Bridge integration tests',
    'admin',
    'admin@example.test',
    true,
    '',
    'integration-tests'
);

if (!is_array($jfbInstalled)) {
    fwrite(STDERR, "error: wp_install() did not return a result\n");

    exit(1);
}

// The plugin is active in the pristine database, so every test process loads it
// the way a site does — through wp-settings.php, from wp-content/plugins, with
// its own autoloader — rather than by requiring the file from the bootstrap.
update_option('active_plugins', [$jfbPaths['plugin']]);

// Pretty permalinks, because the REST route the frontend script posts to is
// built by rest_url() and the plain form of that URL is a query string.
update_option('permalink_structure', '/%postname%/');

fwrite(
    STDOUT,
    'Installed WordPress ' . get_bloginfo('version') . ' into ' . $jfbPaths['db'] . "\n"
);
