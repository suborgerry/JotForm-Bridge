<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit;

use Brain\Monkey\Functions;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Plugin;
use JotformBridge\Templates\TemplateRegistry;
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
            TemplateRegistry::OPTION          => ['templates' => [], 'generated_at' => 1],
            SchemaRepository::META_OPTION      => ['240000000000001' => ['fingerprint' => 'abc']],
            SchemaRepository::optionKey('240000000000001') => ['fields' => []],
            FormRepository::META_OPTION        => ['count' => 3],
            FormRepository::OPTION             => [['id' => '1']],
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

    public function testDeactivationDropsTheTemplateRegistryAndKeepsEverythingElse(): void
    {
        Plugin::onDeactivate();

        $this->assertArrayNotHasKey(TemplateRegistry::OPTION, $this->options);

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

    public function testActivationDropsTheTemplateRegistryAndRecordsTheVersion(): void
    {
        Plugin::onActivate();

        $this->assertArrayNotHasKey(TemplateRegistry::OPTION, $this->options);
        $this->assertSame(JOTFORM_BRIDGE_VERSION, $this->options[Plugin::VERSION_OPTION]);
        $this->assertArrayHasKey('jotform_bridge_integrations', $this->options);
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
    public function testATemplateRegistryFlushLeavesTheStoredSchemaAlone(): void
    {
        Plugin::flushTemplateRegistry();

        $this->assertArrayNotHasKey(TemplateRegistry::OPTION, $this->options);
        $this->assertArrayHasKey(
            SchemaRepository::optionKey('240000000000001'),
            $this->options,
            'A theme switch says nothing about the Jotform schema.'
        );
    }
}
