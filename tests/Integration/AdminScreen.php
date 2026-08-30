<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

/**
 * Just enough of WP_Screen for is_admin() to answer true.
 *
 * WordPress asks $GLOBALS['current_screen'] first and the WP_ADMIN constant
 * second, and a constant cannot be switched off again. The plugin registers its
 * admin screens and their admin-post and admin-ajax handlers behind is_admin(),
 * so the suite has to be able to boot inside an admin request and then leave it
 * — the REST endpoint it tests next is never an admin request on a real site.
 *
 * The real class lives in wp-admin/includes/screen.php, which is not loaded
 * outside the admin. Only in_admin() is ever called on this.
 */
final class AdminScreen
{
    // The method name is WP_Screen's; is_admin() calls exactly this.
    public function in_admin(?string $admin = null): bool
    {
        return $admin === null || $admin === 'site';
    }
}
