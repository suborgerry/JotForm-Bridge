<?php

/**
 * The screen's live region.
 *
 * Empty, and rendered with the page rather than created when it is first
 * needed: a live region added to the document and written to in the same breath
 * is not reliably announced, so the element has to be there before anything
 * happens. `assets/admin.js` writes into it — today the outcome of a copy,
 * which until now was a coloured word fading in beside a button and nothing at
 * all for anybody not looking at it.
 *
 * One per screen is enough; it is addressed by attribute, not by id, so nothing
 * has to agree on a name.
 *
 * @package JotformBridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

?>
<p class="screen-reader-text" data-jfb-status role="status" aria-live="polite"></p>
