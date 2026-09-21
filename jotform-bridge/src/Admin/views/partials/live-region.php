<?php

/**
 * The screen's live region, rendered empty with the page; `assets/admin.js`
 * writes into it.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

?>
<p class="screen-reader-text" data-jfb-status role="status" aria-live="polite"></p>
