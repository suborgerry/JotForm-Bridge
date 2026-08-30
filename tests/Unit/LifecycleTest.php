<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit;

use Brain\Monkey\Functions;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Plugin;
use JotformBridge\Tests\TestCase;

/**
 * Activation, deactivation and cache invalidation.
 *
 * The rule these tests pin down: the template registry is disposable and gets
 * dropped freely, while configuration and the manually synced schemas are not
 * and are never touched automatically.
 */
final class LifecycleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [
            Plugin::VERSION_OPTION            => '0.0.9',
            'jotform_bridge_templates'        => ['templates' => [], 'generated_at' => 1],
            SchemaRepository::META_OPTION      => ['240000000000001' => ['fingerprint' => 'abc']],
            SchemaRepository::optionKey('240000000000001') => ['fields' => []],
            FormRepository::OPTION             => ['240000000000001' => ['id' => '240000000000001']],
            FormRepository::LEGACY_OPTION        => [['id' => '1']],
            FormRepository::LEGACY_META_OPTION   => ['count' => 3],
            FormRepository::LEGACY_HIDDEN_OPTION => ['2'],
            'jotform_bridge_settings'          => ['api_key' => 'legacy-key', 'region' => 'eu'],
            'jotform_bridge_integrations'      => ['contact' => ['slug' => 'contact']],
        ];

        $this->transients = [];

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('delete_option')->alias(
            function (string $name): bool {
                unset($this->options[$name]);

                return true;
            }
        );
        Functions\when('get_transient')->alias(
            fn(string $name) => $this->transients[$name] ?? false
        );
        Functions\when('delete_transient')->alias(
            function (string $name): bool {
                unset($this->transients[$name]);

                return true;
            }
        );
    }

    /**
     * There is nothing derived left to drop: the template list is read from the
     * theme on demand and never stored. So deactivation has to leave the site
     * exactly as it found it.
     */
    public function testDeactivationTouchesNothing(): void
    {
        $before = $this->options;

        Plugin::onDeactivate();

        $this->assertSame($before, $this->options);

        $this->assertArrayHasKey(
            SchemaRepository::optionKey('240000000000001'),
            $this->options,
            'A synced schema is not a cache: deactivation must leave it in place.'
        );
        $this->assertArrayHasKey(SchemaRepository::META_OPTION, $this->options);
        $this->assertArrayHasKey(FormRepository::OPTION, $this->options);

        $this->assertSame(
            ['api_key' => 'legacy-key', 'region' => 'eu'],
            $this->options['jotform_bridge_settings'],
            'Deactivation must not touch the settings.'
        );
        $this->assertArrayHasKey('jotform_bridge_integrations', $this->options);
    }

    public function testActivationRecordsTheVersionAndKeepsConfiguration(): void
    {
        Plugin::onActivate();

        $this->assertSame(JOTFORM_BRIDGE_VERSION, $this->options[Plugin::VERSION_OPTION]);
        $this->assertArrayHasKey('jotform_bridge_integrations', $this->options);
        $this->assertArrayHasKey(SchemaRepository::optionKey('240000000000001'), $this->options);
    }

    /**
     * An option written by a version that still cached the template scan is
     * dead weight, and an upgrade is the moment to take it out.
     */
    public function testActivationRemovesTheOldTemplateCache(): void
    {
        Plugin::onActivate();

        $this->assertArrayNotHasKey('jotform_bridge_templates', $this->options);
    }

    /**
     * The account form list and everything that managed it.
     *
     * Nothing writes those three options any more: forms are connected one at a
     * time by ID, so there is no list to keep, and no trashed form to hide from
     * a list it is not in.
     */
    public function testActivationRemovesTheOldAccountFormList(): void
    {
        Plugin::onActivate();

        $this->assertArrayNotHasKey(FormRepository::LEGACY_OPTION, $this->options);
        $this->assertArrayNotHasKey(FormRepository::LEGACY_META_OPTION, $this->options);
        $this->assertArrayNotHasKey(FormRepository::LEGACY_HIDDEN_OPTION, $this->options);
    }

    /**
     * The connected-form records are not the account list. They name the forms
     * integrations actually point at, and an upgrade must not blank the label
     * on every integration on the site.
     */
    public function testAnUpgradeKeepsTheConnectedForms(): void
    {
        Plugin::onActivate();

        $this->assertArrayHasKey(FormRepository::OPTION, $this->options);
    }

    /**
     * An upgrade may normalize schemas differently, but discarding them would
     * take every form on the site down until each integration was synced by
     * hand. The staleness is reported on screen instead.
     */
    public function testAnUpgradeKeepsTheSyncedSchemas(): void
    {
        Plugin::onActivate();

        $this->assertArrayHasKey(SchemaRepository::optionKey('240000000000001'), $this->options);
        $this->assertArrayHasKey(SchemaRepository::META_OPTION, $this->options);
    }

    /**
     * Older versions could keep the API key in the settings option. Upgrading
     * has to take it out of the database, not just stop reading it.
     */
    public function testActivationRemovesAKeyLeftInTheSettingsOption(): void
    {
        Plugin::onActivate();

        $this->assertSame(
            ['region' => 'eu'],
            $this->options['jotform_bridge_settings'],
            'The key must not survive in the option.'
        );
    }

    /**
     * The registry stores absolute paths inside the theme that was active when it
     * was built, so it cannot outlive a theme switch.
     */

    /**
     * A network activation fires the hook once, not once per site, so a step
     * that only touched the current blog would leave every other one carrying
     * whatever the previous version left behind.
     */
    public function testANetworkActivationVisitsEverySite(): void
    {
        $visited = [];

        Functions\when('is_multisite')->justReturn(true);
        Functions\when('get_sites')->justReturn([1, 7, 42]);
        Functions\when('switch_to_blog')->alias(
            function (int $siteId) use (&$visited): bool {
                $visited[] = $siteId;

                return true;
            }
        );
        Functions\when('restore_current_blog')->justReturn(true);

        Plugin::onActivate(true);

        $this->assertSame([1, 7, 42], $visited);
    }

    /**
     * A single-site activation must not start switching blogs around.
     */
    public function testASingleSiteActivationDoesNotSwitchBlogs(): void
    {
        Functions\when('is_multisite')->justReturn(true);
        Functions\when('switch_to_blog')->alias(
            static function (): void {
                throw new \RuntimeException('A per-site activation must not switch blogs.');
            }
        );

        Plugin::onActivate(false);

        $this->assertSame(JOTFORM_BRIDGE_VERSION, $this->options[Plugin::VERSION_OPTION]);
    }
}
