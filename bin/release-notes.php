<?php

/**
 * Prints the changelog entry for one version, from readme.txt.
 *
 * The release workflow attaches this to the GitHub Release, and the plugin's
 * update check shows the release body in the "View details" window on the
 * Updates screen. So the notes a site owner reads before pressing "update now"
 * are the ones written in readme.txt, and nowhere else: one place to write
 * them, and no second copy in the release form to drift.
 *
 * A version with no entry is a failure, not an empty release. bin/version.php
 * leaves the changelog alone on purpose — a version is often bumped before the
 * notes exist — but by the time a tag is pushed they have to.
 *
 * Usage:
 *   php bin/release-notes.php 2.1.0        Print the entry for 2.1.0.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

$root   = dirname(__DIR__);
$readme = $root . '/jotform-bridge/readme.txt';

$version = $argv[1] ?? '';
$version = ltrim($version, 'vV');

if (preg_match('/^\d+\.\d+\.\d+$/', $version) !== 1) {
    fwrite(STDERR, "usage: php bin/release-notes.php X.Y.Z\n");

    exit(2);
}

$contents = file_get_contents($readme);

if ($contents === false) {
    fwrite(STDERR, "error: cannot read {$readme}\n");

    exit(1);
}

// The entry runs from its own "= X.Y.Z =" heading to the first blank line: a
// readme.txt entry is one bullet list, and the link to the full history that
// closes the section is not part of the last entry.
$pattern = sprintf('/^= %s =\R(.*?)(?=\R\R|^=|\z)/ms', preg_quote($version, '/'));

if (preg_match($pattern, $contents, $matches) !== 1) {
    fwrite(STDERR, "error: readme.txt has no changelog entry \"= {$version} =\"\n");

    exit(1);
}

$notes = trim($matches[1]);

if ($notes === '') {
    fwrite(STDERR, "error: the changelog entry for {$version} is empty\n");

    exit(1);
}

echo $notes, "\n";
