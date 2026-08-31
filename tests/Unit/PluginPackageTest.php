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

    public function testLifecycleHooksAreRegistered(): void
    {
        $main = (string) file_get_contents(self::PLUGIN_DIR . '/jotform-bridge.php');

        $this->assertStringContainsString("register_activation_hook(__FILE__, [Plugin::class, 'onActivate'])", $main);
        $this->assertStringContainsString("register_deactivation_hook(__FILE__, [Plugin::class, 'onDeactivate'])", $main);

        $plugin = (string) file_get_contents(self::PLUGIN_DIR . '/src/Plugin.php');

        // Templates are read from the theme on demand, so no cache has to be
        // invalidated when the theme changes.
        $this->assertStringNotContainsString("add_action('switch_theme'", $plugin);
    }

    /**
     * Uninstall must not destroy configuration nobody asked it to destroy.
     */
    public function testUninstallOnlyRemovesConfigurationWhenItWasOptedIn(): void
    {
        $uninstall = (string) file_get_contents(self::PLUGIN_DIR . '/uninstall.php');

        $this->assertMatchesRegularExpression(
            '/delete_data_on_uninstall.*\n(.*\n)*?.*delete_option\(\'jotform_bridge_integrations\'\)/',
            $uninstall,
            'The integrations option may only be deleted inside the opt-in branch.'
        );

        foreach (['jotform_bridge_integrations', 'jotform_bridge_settings'] as $guarded) {
            $before = (string) strstr($uninstall, 'delete_data_on_uninstall', true);

            $this->assertStringNotContainsString(
                sprintf("delete_option('%s')", $guarded),
                $before,
                sprintf('%s must not be deleted unconditionally.', $guarded)
            );
        }
    }

    /**
     * The shipped plugin must not require any Composer or Node artifact.
     */
    public function testThePackageHasNoBuildOrDependencyArtifacts(): void
    {
        foreach (['vendor', 'node_modules', 'composer.json', 'package.json', 'tests'] as $unwanted) {
            $this->assertFileDoesNotExist(
                self::PLUGIN_DIR . '/' . $unwanted,
                sprintf('%s must not be part of the plugin directory.', $unwanted)
            );
        }

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN_DIR)),
            '/\.php$/'
        );

        foreach ($files as $file) {
            $source = (string) file_get_contents($file->getPathname());

            $this->assertStringNotContainsString(
                'vendor/autoload.php',
                $source,
                sprintf('%s expects a Composer autoloader.', $file->getPathname())
            );
        }
    }

    /**
     * Neither shipped script says a sentence of its own.
     *
     * Every string these files show a visitor or an administrator arrives
     * through wp_localize_script, because a sentence written into JavaScript
     * source is a sentence no `.po` file can reach. The rule was already
     * written down for admin.js, and admin.js kept it; frontend.js carried an
     * English fallback in the one branch that runs when the localized object is
     * missing — so the single case where it was reachable was the single case
     * where it could not be translated.
     *
     * The check reads the source of both, because "the file we remembered to
     * look at" is how the last markup rule was got round.
     */
    public function testNeitherShippedScriptCarriesASentenceOfItsOwn(): void
    {
        foreach (['frontend.js', 'admin.js'] as $name) {
            $source = (string) file_get_contents(self::PLUGIN_DIR . '/assets/' . $name);

            // Comments are prose on purpose; only executable source is at issue.
            $source = (string) preg_replace('#/\*.*?\*/#s', '', $source);
            $source = (string) preg_replace('#^\s*//.*$#m', '', $source);

            // A quoted literal opening with a capital, carrying a space and
            // closing on sentence punctuation. Selectors, attribute names and
            // separators do not look like that.
            preg_match_all('/([\'"])([A-Z][^\'"\n]*?\s+[^\'"\n]*?[.!?])\1/', $source, $matches);

            $this->assertSame(
                [],
                $matches[2],
                sprintf(
                    'assets/%s says something no .po file can reach: %s',
                    $name,
                    implode(' | ', $matches[2])
                )
            );
        }
    }

    public function testRepositoryRootContainsNoPluginPhp(): void
    {
        $root = glob(__DIR__ . '/../../*.php') ?: [];

        $this->assertSame([], $root, 'Plugin PHP must live in jotform-bridge/, not in the repository root.');
    }
}
