<?php

/**
 * PHPUnit bootstrap for the integration suite.
 *
 * The unit suite stubs WordPress and asserts about one class at a time. This
 * one loads WordPress itself — a real options table, a real REST server, a real
 * nonce, the plugin included from wp-content/plugins the way a site includes it
 * — because the defects this suite exists for live between classes, where a
 * stub is exactly the thing that hides them.
 *
 * Each run starts from the pristine database made by install.php, so no test
 * can be made to pass by something an earlier run left behind.
 *
 * @package JotformBridge\Tests\Integration
 */

declare(strict_types=1);

$jfbPaths = require __DIR__ . '/config.php';

require_once $jfbPaths['root'] . '/vendor/autoload.php';

/**
 * Runs install.php in its own process and keeps the result as the snapshot
 * every later run is restored from.
 */
$jfbInstall = static function (array $paths): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php');

    passthru($command, $status);

    if ($status !== 0) {
        fwrite(STDERR, "error: the WordPress installation failed\n");

        exit(1);
    }

    if (!copy($paths['db'], $paths['pristine'])) {
        fwrite(STDERR, "error: could not snapshot the installed database\n");

        exit(1);
    }
};

// Reinstalled when there is no snapshot, when the environment asks for one, and
// when WordPress itself is newer than the snapshot — an upgraded core with a
// database made by the previous one is the sort of thing that fails in a way
// nobody would connect to this file.
$jfbCoreVersionFile = $jfbPaths['wp'] . '/wp-includes/version.php';

if (
    !is_file($jfbPaths['pristine'])
    || getenv('JFB_WP_REINSTALL') === '1'
    || filemtime($jfbCoreVersionFile) > filemtime($jfbPaths['pristine'])
) {
    $jfbInstall($jfbPaths);
}

// The database file and the two SQLite writes beside it — and nothing else in
// the directory, least of all the snapshot this is about to be restored from.
foreach (['', '-wal', '-shm', '-journal'] as $jfbSuffix) {
    if (is_file($jfbPaths['db'] . $jfbSuffix)) {
        unlink($jfbPaths['db'] . $jfbSuffix);
    }
}

if (!copy($jfbPaths['pristine'], $jfbPaths['db'])) {
    fwrite(STDERR, "error: could not restore the pristine database\n");

    exit(1);
}

/*
 * Plugin::boot() registers the admin screens and their admin-post and
 * admin-ajax handlers only when is_admin() is true, and the composition root is
 * precisely the wiring this suite is here to exercise: a handler registered by
 * the test instead of by the plugin proves nothing about the plugin.
 *
 * is_admin() answers from $GLOBALS['current_screen'] before it looks at
 * WP_ADMIN, which is the only one of the two that can be turned off again. So
 * the boot happens in an admin context and the rest of the suite — the REST
 * endpoint above all, which on a real site is never an admin request — runs
 * without it.
 */
$GLOBALS['current_screen'] = new class {
    public function in_admin(?string $admin = null): bool
    {
        return $admin === null || $admin === 'site';
    }
};

/*
 * Records anything the plugin does that WordPress calls incorrect while it is
 * loading, before init has fired.
 *
 * This is the one window the rest of the suite cannot see into: by the time a
 * test runs, init has happened, and the mistakes that only matter before it —
 * translating on plugins_loaded above all — have already been made and cannot
 * be made again. WordPress accepts a hook registered before it loads
 * (WP_Hook::build_preinitialized_hooks), which is how this gets in front of the
 * plugin's own boot.
 *
 * It caught a real one on the first run: Settings::region() validated the
 * stored region against the translated label list, so every admin request
 * loaded the text domain on plugins_loaded.
 */
$GLOBALS['jfb_doing_it_wrong'] = [];

$GLOBALS['wp_filter'] = [
    'doing_it_wrong_run' => [
        10 => [
            [
                'function'      => static function ($function, $message, $version): void {
                    if (strpos((string) $message, 'jotform-bridge') === false) {
                        return;
                    }

                    $GLOBALS['jfb_doing_it_wrong'][] = (string) $function . ': ' . (string) $message;
                },
                'accepted_args' => 3,
            ],
        ],
    ],
];

// The name is WordPress's, not ours: wp-settings.php reads $table_prefix
// out of the scope it is included from when the global is not already set.
$table_prefix = (string) $GLOBALS['table_prefix'];

require_once ABSPATH . 'wp-settings.php';

unset($GLOBALS['current_screen']);

if (!defined('JOTFORM_BRIDGE_VERSION')) {
    fwrite(STDERR, "error: WordPress loaded but the plugin did not\n");

    exit(1);
}

if (!has_action('admin_post_' . JotformBridge\Admin\IntegrationsPage::ACTION_SAVE)) {
    fwrite(STDERR, "error: the plugin booted without its admin actions\n");

    exit(1);
}

if ($GLOBALS['jfb_doing_it_wrong'] !== []) {
    fwrite(
        STDERR,
        "error: the plugin was called incorrectly while WordPress was still loading:\n  "
        . implode("\n  ", $GLOBALS['jfb_doing_it_wrong']) . "\n"
    );

    exit(1);
}
