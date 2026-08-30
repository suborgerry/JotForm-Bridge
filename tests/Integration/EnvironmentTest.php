<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Rest\SubmissionController;

/**
 * Proves the harness itself, before anything is asserted through it.
 *
 * Every other test in this directory is only worth as much as these: a suite
 * that quietly stopped loading the plugin, or stopped resetting between tests,
 * would go green while testing nothing.
 */
final class EnvironmentTest extends TestCase
{
    public function testWordPressAndThePluginAreLoaded(): void
    {
        $this->assertTrue(function_exists('wp_insert_post'), 'WordPress is not loaded.');
        $this->assertTrue(defined('JOTFORM_BRIDGE_VERSION'));
        $this->assertTrue(
            version_compare(get_bloginfo('version'), '6.4', '>='),
            'The plugin header promises WordPress 6.4 or newer.'
        );
    }

    public function testThePluginIsActiveTheWayASiteActivatesIt(): void
    {
        $this->assertContains(
            'jotform-bridge/jotform-bridge.php',
            (array) get_option('active_plugins', []),
            'The plugin has to be loaded by wp-settings.php, not required by the bootstrap.'
        );
    }

    public function testTheOptionsTableIsReal(): void
    {
        global $wpdb;

        update_option('jotform_bridge_probe', ['value' => 1]);

        $stored = $wpdb->get_var(
            $wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'jotform_bridge_probe')
        );

        $this->assertIsString($stored, 'The option did not reach the database.');
        $this->assertSame(['value' => 1], maybe_unserialize($stored));
    }

    public function testStorageDoesNotSurviveIntoTheNextTest(): void
    {
        $this->assertFalse(
            get_option('jotform_bridge_probe', false),
            'The previous test\'s option is still there.'
        );
    }

    public function testTheSubmissionRouteIsRegistered(): void
    {
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey('/' . SubmissionController::NAMESPACE . SubmissionController::ROUTE, $routes);
    }

    public function testTheAdminActionsAreRegistered(): void
    {
        foreach (
            [
                'admin_post_' . IntegrationsPage::ACTION_SAVE,
                'admin_post_' . IntegrationsPage::ACTION_DELETE,
                'admin_post_' . IntegrationsPage::ACTION_SYNC,
                'wp_ajax_' . IntegrationsPage::ACTION_CONNECT,
            ] as $hook
        ) {
            $this->assertNotFalse(has_action($hook), $hook . ' is not registered.');
        }
    }

    public function testNothingWasCalledIncorrectlyWhileWordPressLoaded(): void
    {
        // Filled by the bootstrap, which fails the run outright if it is not
        // empty. Asserted here as well so the reason is visible in the suite
        // rather than only in a startup error nobody reads twice.
        $this->assertSame(
            [],
            $GLOBALS['jfb_doing_it_wrong'],
            'The plugin does something WordPress calls incorrect before init.'
        );
    }

    public function testTheNetworkIsUnreachable(): void
    {
        $this->expectExceptionMessageMatches('/HTTP request nothing mocked/');

        wp_remote_get('https://api.jotform.com/user');
    }
}
