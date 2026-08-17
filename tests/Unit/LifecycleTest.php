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
 * The rule these tests pin down: derived data is disposable and gets dropped
 * freely, configuration is not and is never touched automatically.
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
            FormRepository::META_OPTION        => ['count' => 3],
            'jotform_bridge_settings'          => ['api_key' => 'stored-key'],
            'jotform_bridge_integrations'      => ['contact' => ['slug' => 'contact']],
        ];

        $this->transients = [
            FormRepository::TRANSIENT                                => [['id' => '1']],
            SchemaRepository::transientKey('240000000000001')         => ['fields' => []],
        ];

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

    public function testDeactivationDropsEveryCacheAndKeepsTheConfiguration(): void
    {
        Plugin::onDeactivate();

        $this->assertSame([], $this->transients);
        $this->assertArrayNotHasKey(TemplateRegistry::OPTION, $this->options);
        $this->assertArrayNotHasKey(SchemaRepository::META_OPTION, $this->options);

        $this->assertSame(
            ['api_key' => 'stored-key'],
            $this->options['jotform_bridge_settings'],
            'Deactivation must not touch the settings.'
        );
        $this->assertArrayHasKey('jotform_bridge_integrations', $this->options);
    }

    public function testActivationClearsStaleCachesAndRecordsTheVersion(): void
    {
        Plugin::onActivate();

        $this->assertSame([], $this->transients);
        $this->assertSame(JOTFORM_BRIDGE_VERSION, $this->options[Plugin::VERSION_OPTION]);
        $this->assertArrayHasKey('jotform_bridge_integrations', $this->options);
    }

    /**
     * The registry stores absolute paths inside the theme that was active when it
     * was built, so it cannot outlive a theme switch.
     */
    public function testATemplateRegistryFlushLeavesTheSchemaCacheAlone(): void
    {
        Plugin::flushTemplateRegistry();

        $this->assertArrayNotHasKey(TemplateRegistry::OPTION, $this->options);
        $this->assertArrayHasKey(
            SchemaRepository::transientKey('240000000000001'),
            $this->transients,
            'A theme switch says nothing about the Jotform schema.'
        );
    }
}
