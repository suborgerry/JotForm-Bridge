<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration;

use JotformBridge\Api\ConnectionState;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Plugin;
use JotformBridge\Settings\Settings;

/**
 * Activation, upgrade, deactivation and uninstall, against a real options
 * table.
 *
 * This is the part of the plugin that runs once per site and therefore never
 * runs on a developer's machine: by the time anyone looks, the site has been
 * activated for months and the legacy options the purge is about were removed
 * by a version nobody is running any more. It is also the part where a mistake
 * is unrecoverable — an upgrade that deleted a synced schema, or an uninstall
 * that kept a stored API key, cannot be undone from the admin.
 *
 * The state written below is what earlier versions of this plugin really
 * wrote: the account form list and its two companions, the cached template
 * registry, the submission tally, the schema transient, and an API key inside
 * the settings option.
 */
final class LifecycleTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    private const PLUGIN = 'jotform-bridge/jotform-bridge.php';

    /**
     * Storage written by versions that no longer exist.
     */
    private const LEGACY_OPTIONS = [
        'jotform_bridge_forms',
        'jotform_bridge_forms_meta',
        'jotform_bridge_forms_hidden',
        'jotform_bridge_templates',
        'jotform_bridge_stats',
    ];

    public function testActivationRemovesLegacyStorageAndRecordsTheVersion(): void
    {
        $this->givenASiteUpgradedFromAnOlderVersion();

        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $this->actAsAdministrator();

        deactivate_plugins([self::PLUGIN]);
        activate_plugin(self::PLUGIN);

        $this->assertLegacyStorageIsGone();
        $this->assertSame(JOTFORM_BRIDGE_VERSION, get_option(Plugin::VERSION_OPTION));
    }

    public function testActivationNeverAsksJotformForAnything(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);

        // Nothing mocks the network: an activation that fetched a schema, a
        // form or an account would fail this test.
        deactivate_plugins([self::PLUGIN]);
        activate_plugin(self::PLUGIN);

        $this->assertFalse($this->plugin()->schemas()->isSynced(self::FORM_ID));
    }

    public function testAnUpgradeCleansUpWithoutTouchingWhatWasConfiguredByHand(): void
    {
        $this->givenASiteUpgradedFromAnOlderVersion();

        $integrationsBefore = get_option(IntegrationRepository::OPTION);
        $schemaBefore       = get_option(SchemaRepository::optionKey(self::FORM_ID));

        // What plugins_loaded does on the first request after the files were
        // replaced.
        Runtime::reboot();

        $this->assertLegacyStorageIsGone();
        $this->assertSame(JOTFORM_BRIDGE_VERSION, get_option(Plugin::VERSION_OPTION));

        $this->assertSame(
            $integrationsBefore,
            get_option(IntegrationRepository::OPTION),
            'Integrations are work somebody did by hand.'
        );
        $this->assertSame(
            $schemaBefore,
            get_option(SchemaRepository::optionKey(self::FORM_ID)),
            'A synced schema survives an upgrade: re-normalizing it behind the site owner is not the plugin\'s call.'
        );
    }

    public function testAnUpgradeTakesAStoredApiKeyOutOfTheDatabase(): void
    {
        $this->givenASiteUpgradedFromAnOlderVersion();

        $this->assertArrayHasKey('api_key', (array) get_option(Settings::OPTION));

        Runtime::reboot();

        $stored = (array) get_option(Settings::OPTION);

        $this->assertArrayNotHasKey('api_key', $stored, 'A key that reached the database has to leave it.');
        $this->assertStringNotContainsString('left-behind-key', (string) wp_json_encode($stored));
        $this->assertSame('eu', $stored['region'], 'The rest of the settings are not collateral damage.');
    }

    public function testTheUpgradeRunsOnceAndThenStopsCosting(): void
    {
        update_option(Plugin::VERSION_OPTION, JOTFORM_BRIDGE_VERSION, false);
        update_option('jotform_bridge_forms', ['240000000000001' => ['title' => 'Contact']]);

        Runtime::reboot();

        $this->assertIsArray(
            get_option('jotform_bridge_forms'),
            'With the version already current there is nothing to do, and nothing is done.'
        );
    }

    public function testDeactivationKeepsEverything(): void
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);
        $this->syncSchema(self::FORM_ID);

        deactivate_plugins([self::PLUGIN]);

        $this->assertNotNull((new IntegrationRepository())->get('contact'));
        $this->assertNotNull((new SchemaRepository($this->plugin()->client()))->stored(self::FORM_ID));

        activate_plugin(self::PLUGIN);
    }

    public function testUninstallRemovesWhatCameFromJotformAndKeepsTheConfiguration(): void
    {
        $this->givenASiteUpgradedFromAnOlderVersion();

        (new ConnectionState())->recordSuccess('example_account');

        $this->uninstall();

        $this->assertNull(get_option(SchemaRepository::optionKey(self::FORM_ID), null));
        $this->assertNull(get_option(SchemaRepository::META_OPTION, null));
        $this->assertNull(get_option(FormRepository::OPTION, null));
        $this->assertNull(get_option(ConnectionState::OPTION, null));
        $this->assertNull(get_option(Plugin::VERSION_OPTION, null));
        $this->assertLegacyStorageIsGone();

        $this->assertIsArray(
            get_option(IntegrationRepository::OPTION, null),
            'Deleting the plugin to fix a broken update must not destroy the integrations.'
        );
        $this->assertIsArray(get_option(Settings::OPTION, null));
    }

    public function testUninstallRemovesTheConfigurationTooWhenTheAdministratorAskedForIt(): void
    {
        $this->givenASiteUpgradedFromAnOlderVersion();

        $settings = (array) get_option(Settings::OPTION);

        $settings['delete_data_on_uninstall'] = true;

        update_option(Settings::OPTION, $settings);

        $this->uninstall();

        $this->assertNull(get_option(IntegrationRepository::OPTION, null));
        $this->assertNull(get_option(Settings::OPTION, null));
    }

    /**
     * A site that has been running since before the removals: every option an
     * earlier version wrote, plus the configuration and one synced schema.
     */
    private function givenASiteUpgradedFromAnOlderVersion(): void
    {
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);
        $this->syncSchema(self::FORM_ID);

        update_option(Plugin::VERSION_OPTION, '0.1.0', false);

        update_option(
            Settings::OPTION,
            [
                'region'        => 'eu',
                'debug_logging' => false,
                // Versions up to 0.1.0 accepted a key on the settings screen.
                'api_key'       => 'left-behind-key',
            ]
        );

        update_option('jotform_bridge_forms', [self::FORM_ID => ['title' => 'Contact Form']]);
        update_option('jotform_bridge_forms_meta', ['fetched_at' => 1750000000]);
        update_option('jotform_bridge_forms_hidden', [self::FORM_ID]);
        update_option('jotform_bridge_templates', ['contact' => ['file' => '/somewhere/contact.php']]);
        update_option('jotform_bridge_stats', ['contact' => ['sent' => 12]]);

        // The two transients the same versions kept beside those options.
        set_transient(FormRepository::LEGACY_TRANSIENT, [self::FORM_ID], HOUR_IN_SECONDS);
        set_transient(SchemaRepository::LEGACY_TRANSIENT_PREFIX . self::FORM_ID, ['fields' => []], HOUR_IN_SECONDS);
    }

    private function assertLegacyStorageIsGone(): void
    {
        foreach (self::LEGACY_OPTIONS as $option) {
            $this->assertNull(get_option($option, null), $option . ' is still in the options table.');
        }

        $this->assertFalse(get_transient(FormRepository::LEGACY_TRANSIENT));
        $this->assertFalse(get_transient(SchemaRepository::LEGACY_TRANSIENT_PREFIX . self::FORM_ID));
    }

    /**
     * Runs uninstall.php the way WordPress runs it when the plugin is deleted.
     */
    private function uninstall(): void
    {
        if (!defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', self::PLUGIN);
        }

        require JOTFORM_BRIDGE_DIR . 'uninstall.php';
    }
}
