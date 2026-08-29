<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Admin;

use Brain\Monkey\Functions;
use JotformBridge\Admin\AdminAssets;
use JotformBridge\Tests\TestCase;

/**
 * The admin stylesheet and script exist so that nothing is printed inline. What
 * matters beyond that is where they load: an admin that pulls two extra files
 * into every screen of every other plugin is the behaviour this class exists to
 * avoid.
 */
final class AdminAssetsTest extends TestCase
{
    /** @var array<int, string> */
    private array $enqueued = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->enqueued = [];

        Functions\when('wp_enqueue_style')->alias(
            function (string $handle): void {
                $this->enqueued[] = 'style:' . $handle;
            }
        );
        Functions\when('wp_enqueue_script')->alias(
            function (string $handle): void {
                $this->enqueued[] = 'script:' . $handle;
            }
        );
    }

    public function testBothAssetsLoadOnThePluginsOwnScreens(): void
    {
        (new AdminAssets())->enqueue('toplevel_page_jotform-bridge');

        $this->assertSame(
            ['style:jotform-bridge-admin', 'script:jotform-bridge-admin'],
            $this->enqueued
        );
    }

    public function testTheSubmenuScreensAreCoveredToo(): void
    {
        (new AdminAssets())->enqueue('jotform-bridge_page_jotform-bridge-settings');

        $this->assertNotSame([], $this->enqueued);
    }

    /**
     * @dataProvider foreignScreens
     */
    public function testNothingLoadsAnywhereElse(string $hook): void
    {
        (new AdminAssets())->enqueue($hook);

        $this->assertSame([], $this->enqueued, $hook . ' is not our screen.');
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function foreignScreens(): array
    {
        return [
            'dashboard'       => ['index.php'],
            'post editor'     => ['post.php'],
            'plugin list'     => ['plugins.php'],
            'another plugin'  => ['toplevel_page_some-other-plugin'],
            'core settings'   => ['options-general.php'],
        ];
    }

    /**
     * Called without a hook, the screen has to be asked directly rather than
     * assumed to be ours.
     */
    public function testWithoutAHookAndWithoutAScreenNothingLoads(): void
    {
        (new AdminAssets())->enqueue('');

        $this->assertSame([], $this->enqueued);
    }
}
