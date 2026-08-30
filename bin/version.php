<?php

/**
 * Keeps the plugin's version strings agreeing with each other.
 *
 * The version is written down in three places, and it cannot be written down in
 * fewer. Two of them are read by software that never executes PHP:
 *
 *   - `Version:` in the plugin header is parsed out of the raw file by
 *     WordPress's get_file_data(), with a regular expression;
 *   - `Stable tag:` in readme.txt is parsed by wordpress.org the same way.
 *
 * Neither can be an expression, so neither can be derived from the other. The
 * third, the JOTFORM_BRIDGE_VERSION constant, could in principle read the header
 * at runtime — but it is used to build the asset URLs on every request, and
 * paying a file read and a regex for it on every page load is a bad trade for
 * removing one literal.
 *
 * So a single source of truth is not available. What is available is this: one
 * command that writes all three, and a check that fails the build when they
 * disagree. The plugin header is treated as canonical, because bin/build-zip.sh
 * already names the archive from it.
 *
 * This matters more than tidiness. Both `assets/frontend.js` and
 * `assets/admin.js` are enqueued with the version in their URL, so a release
 * that does not move the version leaves browsers on the previous file: a stale
 * frontend.js computes no proof of work and its submissions are refused, and a
 * stale admin.js leaves the Connect form button inert. A version that fails to
 * change is a functional bug, not a bookkeeping slip.
 *
 * The changelog is deliberately not checked. A version is often bumped before
 * the notes are written, and a check that fires at the wrong moment teaches
 * people to pass --no-verify.
 *
 * Usage:
 *   php bin/version.php                Print the three versions.
 *   php bin/version.php --check        Exit 1 if they disagree.
 *   php bin/version.php --set 0.2.0    Write all three.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

$root       = dirname(__DIR__);
$pluginFile = $root . '/jotform-bridge/jotform-bridge.php';
$readmeFile = $root . '/jotform-bridge/readme.txt';

$args  = array_slice($argv, 1);
$check = in_array('--check', $args, true);
$set   = null;

foreach ($args as $i => $arg) {
    if ($arg === '--set') {
        $set = $args[$i + 1] ?? '';
    } elseif (strpos($arg, '--set=') === 0) {
        $set = substr($arg, 6);
    }
}

/**
 * Where each version lives, and the pattern that finds it.
 *
 * Group 2 is always the version itself, so reading is uniform. Writing uses the
 * site's own `replacement`, spelled out rather than derived from the pattern:
 * the first version of this script counted the pattern's opening parentheses to
 * guess how many groups to put back, counted the escaped ones in `define\(` too,
 * and truncated the line it was editing.
 *
 * @var array<string, array{file:string, pattern:string, replacement:string, label:string}>
 */
$sites = [
    'header' => [
        'file'        => $pluginFile,
        'pattern'     => '/^(\s*\*\s*Version:\s*)(\S+)$/m',
        'replacement' => '${1}%s',
        'label'       => 'jotform-bridge.php, plugin header "Version:"',
    ],
    'constant' => [
        'file'        => $pluginFile,
        'pattern'     => "/^(define\('JOTFORM_BRIDGE_VERSION',\s*')([^']+)('\);)\$/m",
        'replacement' => '${1}%s${3}',
        'label'       => 'jotform-bridge.php, JOTFORM_BRIDGE_VERSION',
    ],
    'stable_tag' => [
        'file'        => $readmeFile,
        'pattern'     => '/^(Stable tag:\s*)(\S+)$/m',
        'replacement' => '${1}%s',
        'label'       => 'readme.txt, "Stable tag:"',
    ],
];

$found = [];

foreach ($sites as $key => $site) {
    $contents = @file_get_contents($site['file']);

    if ($contents === false) {
        fwrite(STDERR, sprintf("error: cannot read %s\n", $site['file']));

        exit(1);
    }

    if (preg_match($site['pattern'], $contents, $matches) !== 1) {
        fwrite(STDERR, sprintf("error: no version found in %s\n", $site['label']));

        exit(1);
    }

    $found[$key] = $matches[2];
}

if ($set !== null) {
    if (preg_match('/^\d+\.\d+\.\d+$/', $set) !== 1) {
        fwrite(STDERR, sprintf("error: \"%s\" is not a three-part version, e.g. 0.2.0\n", $set));

        exit(1);
    }

    // Grouped by file, because two of the three live in the same one and
    // writing it twice would discard the first edit.
    $edits = [];

    foreach ($sites as $site) {
        $edits[$site['file']][] = $site;
    }

    foreach ($edits as $file => $fileSites) {
        $contents = (string) file_get_contents($file);

        foreach ($fileSites as $site) {
            $replaced = preg_replace(
                $site['pattern'],
                sprintf($site['replacement'], $set),
                $contents,
                1,
                $count
            );

            if ($replaced === null || $count !== 1) {
                fwrite(STDERR, sprintf("error: could not rewrite %s\n", $site['label']));

                exit(1);
            }

            $contents = $replaced;
        }

        file_put_contents($file, $contents);
    }

    printf("Version set to %s in all three places.\n", $set);

    foreach ($sites as $site) {
        printf("  %s\n", $site['label']);
    }

    exit(0);
}

$unique = array_values(array_unique($found));

if ($check) {
    if (count($unique) === 1) {
        printf("Version %s, consistent across all three places.\n", $unique[0]);

        exit(0);
    }

    fwrite(STDERR, "error: the version strings disagree.\n");

    foreach ($sites as $key => $site) {
        fwrite(STDERR, sprintf("  %-12s %s\n", $found[$key], $site['label']));
    }

    fwrite(
        STDERR,
        "\nThe plugin header is canonical: bin/build-zip.sh names the archive from it.\n"
        . "Run `php bin/version.php --set <version>` to write all three at once.\n"
    );

    exit(1);
}

foreach ($sites as $key => $site) {
    printf("%-12s %s\n", $found[$key], $site['label']);
}

exit(count($unique) === 1 ? 0 : 1);
