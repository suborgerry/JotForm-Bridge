<?php

/**
 * What jotform-bridge.php defines at boot, told to PHPStan.
 *
 * The plugin defines these with define() after an early `return`, which guards
 * against the file being loaded twice. A static analyser cannot see past that
 * conditional return, so without this file every use of the constants reads as
 * "constant not found" and the real findings are lost in the noise.
 *
 * The values are deliberately not the real ones. PHPStan needs the type and
 * nothing else, and a genuine version string here would be a second copy of
 * the one in the plugin header, free to drift from it.
 *
 * The constants a site defines in wp-config.php — JOTFORM_BRIDGE_TURNSTILE_*
 * and JOTFORM_BRIDGE_TRUSTED_PROXY_HEADER — are deliberately absent. Every use
 * of those is behind defined(), which is the contract, and declaring them here
 * would stop PHPStan noticing if one ever were not.
 */

declare(strict_types=1);

define('JOTFORM_BRIDGE_VERSION', '');
define('JOTFORM_BRIDGE_FILE', '');
define('JOTFORM_BRIDGE_DIR', '');
define('JOTFORM_BRIDGE_URL', '');
define('JOTFORM_BRIDGE_MIN_PHP', '');
define('JOTFORM_BRIDGE_MIN_WP', '');
