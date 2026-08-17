<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit;

use JotformBridge\Tests\TestCase;

/**
 * Smoke test over the shipped package: header, layout and PHP 8.0 compatibility
 * of every plugin file.
 */
final class PluginPackageTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../jotform-bridge';

    public function testMainPluginFileDeclaresTheRequiredHeader(): void
    {
        $header = (string) file_get_contents(self::PLUGIN_DIR . '/jotform-bridge.php');

        $this->assertStringContainsString('Plugin Name:       Jotform Bridge', $header);
        $this->assertStringContainsString('Requires at least: 6.4', $header);
        $this->assertStringContainsString('Requires PHP:      8.0', $header);
        $this->assertStringContainsString('Text Domain:       jotform-bridge', $header);
    }

    public function testEveryPluginFileIsSyntacticallyValid(): void
    {
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN_DIR)),
            '/\.php$/'
        );

        $binary = defined('PHP_BINARY') ? PHP_BINARY : 'php';

        foreach ($files as $file) {
            $path   = $file->getPathname();
            $output = [];
            $code   = 0;

            exec(escapeshellarg($binary) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

            $this->assertSame(0, $code, sprintf('Syntax error in %s: %s', $path, implode("\n", $output)));
        }
    }

    public function testRepositoryRootContainsNoPluginPhp(): void
    {
        $root = glob(__DIR__ . '/../../*.php') ?: [];

        $this->assertSame([], $root, 'Plugin PHP must live in jotform-bridge/, not in the repository root.');
    }
}
