<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Updates;

use JotformBridge\Tests\Integration\TestCase;
use JotformBridge\Updates\GitHubUpdater;

/**
 * The update check through Core's own wp_update_plugins(), not through the
 * filter called directly: the header has to parse, the hostname has to match
 * the filter name Core builds, and the answer has to land where the Updates
 * screen reads it. None of that is visible to a test of the class alone.
 */
final class UpdateCheckTest extends TestCase
{
    private const PLUGIN = 'jotform-bridge/jotform-bridge.php';

    private const WPORG = 'api.wordpress.org';

    /** @var array<int, string> URLs the mocked transport answered. */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

        $this->requests = [];

        // Core's transient is not the plugin's and survives Runtime's purge;
        // its last_checked would make every wp_update_plugins() after the
        // first return before asking anybody.
        delete_site_transient('update_plugins');
    }

    public function testTheHeaderCarriesTheUpdateUri(): void
    {
        $data = get_plugin_data(JOTFORM_BRIDGE_FILE, false, false);

        $this->assertSame('https://github.com/suborgerry/JotForm-Bridge', $data['UpdateURI']);
    }

    public function testANewerReleaseLandsInTheUpdatesTransient(): void
    {
        $version = $this->newerVersion();

        $this->mockUpdateSources($this->release($version));

        wp_update_plugins();

        $updates = get_site_transient('update_plugins');

        $this->assertIsObject($updates);
        $this->assertArrayHasKey(self::PLUGIN, $updates->response);

        $update = $updates->response[self::PLUGIN];

        $this->assertSame($version, $update->new_version);
        $this->assertSame(self::PLUGIN, $update->plugin);
        $this->assertSame(
            sprintf(
                'https://github.com/suborgerry/JotForm-Bridge/releases/download/v%1$s/jotform-bridge-%1$s.zip',
                $version
            ),
            $update->package
        );
        $this->assertSame('jotform-bridge', $update->slug);

        // Exactly one visit to GitHub per check.
        $this->assertCount(1, $this->githubRequests());
    }

    /**
     * Core compares the versions; the current release is reported as
     * `no_update`, which is what gives the plugin row its details link.
     */
    public function testTheCurrentReleaseIsFiledAsNoUpdate(): void
    {
        $this->mockUpdateSources($this->release(JOTFORM_BRIDGE_VERSION));

        wp_update_plugins();

        $updates = get_site_transient('update_plugins');

        $this->assertIsObject($updates);
        $this->assertArrayNotHasKey(self::PLUGIN, $updates->response);
        $this->assertArrayHasKey(self::PLUGIN, $updates->no_update);
        $this->assertSame(JOTFORM_BRIDGE_VERSION, $updates->no_update[self::PLUGIN]->new_version);
    }

    public function testNoReleaseYetLeavesThePluginOutOfBothLists(): void
    {
        $this->mockUpdateSources(null);

        wp_update_plugins();

        $updates = get_site_transient('update_plugins');

        $this->assertIsObject($updates);
        $this->assertArrayNotHasKey(self::PLUGIN, $updates->response);
        $this->assertArrayNotHasKey(self::PLUGIN, $updates->no_update);
    }

    /**
     * "Check again" on the Updates screen is `update-core.php?force-check=1`,
     * and the plugin check it runs is Core's `wp_update_plugins()` on
     * `load-update-core.php`. That page has to reach GitHub even when the
     * remembered answer is fresh, or the person who asked for a new check gets
     * the twelve-hour-old one — which is what happened when this test still
     * called wp_clean_plugins_cache() directly and never looked at the page.
     */
    public function testCheckAgainAsksGitHubAgainAndAnOrdinaryVisitDoesNot(): void
    {
        $this->mockUpdateSources($this->release($this->newerVersion()));

        wp_update_plugins();
        $this->assertCount(1, $this->githubRequests());

        // The screen a minute later, without the button: Core's throttle on
        // that page is one minute, and our answer is still fresh.
        $this->ageCoreCheck();
        do_action('load-update-core.php');
        $this->assertCount(1, $this->githubRequests());
        $this->assertNotFalse(get_site_transient(GitHubUpdater::TRANSIENT));

        // The button.
        $this->ageCoreCheck();
        $_GET['force-check'] = '1';

        try {
            do_action('load-update-core.php');
        } finally {
            unset($_GET['force-check']);
        }

        $this->assertCount(2, $this->githubRequests());
    }

    /**
     * The upgrader clears Core's plugin cache after installing something, and
     * so does `wp transient delete update_plugins --network`; both take our
     * answer with them.
     */
    public function testClearingCoresPluginCacheClearsOurs(): void
    {
        $this->mockUpdateSources($this->release($this->newerVersion()));

        wp_update_plugins();
        $this->assertNotFalse(get_site_transient(GitHubUpdater::TRANSIENT));

        wp_clean_plugins_cache(true);
        $this->assertFalse(get_site_transient(GitHubUpdater::TRANSIENT));
    }

    public function testTheDetailsWindowComesFromTheReleaseNotWordPressOrg(): void
    {
        $version = $this->newerVersion();

        $this->mockUpdateSources($this->release($version));

        $info = plugins_api('plugin_information', ['slug' => 'jotform-bridge']);

        $this->assertIsObject($info);
        $this->assertSame($version, $info->version);
        $this->assertStringContainsString('Updates arrive from GitHub Releases', $info->sections['changelog']);

        // Nothing went to wordpress.org for it.
        $this->assertSame([], array_filter(
            $this->requests,
            static fn(string $url): bool => str_contains($url, self::WPORG . '/plugins/info')
        ));
    }

    /**
     * Answers wordpress.org with an empty result and GitHub with the release,
     * or with 404 when there is none.
     *
     * @param array<string, mixed>|null $release
     */
    private function mockUpdateSources(?array $release): void
    {
        $this->mockHttp(
            function (string $url) use ($release): ?array {
                $this->requests[] = $url;

                // One body serves both update-check endpoints: the Updates
                // screen runs the theme check on the same action as the
                // plugin one.
                if (str_contains($url, self::WPORG)) {
                    return $this->httpResponse(200, ['plugins' => [], 'themes' => [], 'translations' => [], 'no_update' => []]);
                }

                if (str_contains($url, 'api.github.com/repos/suborgerry/JotForm-Bridge/releases/latest')) {
                    return $release === null
                        ? $this->httpResponse(404, ['message' => 'Not Found'])
                        : $this->httpResponse(200, $release);
                }

                return null;
            }
        );
    }

    /**
     * Moves Core's last plugin check back past the one-minute throttle of the
     * Updates screen, so a visit to it asks the filter at all. Written directly
     * rather than deleted: deleting the transient fires the action the plugin
     * listens on, which is the other path and not the one under test.
     */
    private function ageCoreCheck(): void
    {
        $current = get_site_transient('update_plugins');

        $this->assertIsObject($current);

        $current->last_checked = time() - 2 * MINUTE_IN_SECONDS;

        set_site_transient('update_plugins', $current);
    }

    /**
     * @return array<int, string>
     */
    private function githubRequests(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn(string $url): bool => str_contains($url, 'api.github.com')
        ));
    }

    /**
     * The fixture, re-tagged with the given version.
     *
     * @return array<string, mixed>
     */
    private function release(string $version): array
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/GitHub/release-latest.json';

        $this->assertFileExists($path);

        $json = str_replace('2.1.0', $version, (string) file_get_contents($path));

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * A version above whatever the plugin currently is, so the test does not
     * stop passing the day the plugin catches up with the fixture.
     */
    private function newerVersion(): string
    {
        $parts = explode('.', JOTFORM_BRIDGE_VERSION);

        return ((int) $parts[0] + 1) . '.0.0';
    }
}
