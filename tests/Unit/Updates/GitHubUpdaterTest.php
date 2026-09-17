<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Updates;

use Brain\Monkey\Functions;
use JotformBridge\Settings\Settings;
use JotformBridge\Support\Logger;
use JotformBridge\Tests\TestCase;
use JotformBridge\Updates\GitHubUpdater;
use WP_Error;

final class GitHubUpdaterTest extends TestCase
{
    private const PLUGIN_FILE = 'jotform-bridge/jotform-bridge.php';

    /** @var array<string, mixed> The plugin header as get_plugin_data() parses it. */
    private const PLUGIN_DATA = [
        'Name'      => 'Jotform Bridge',
        'Version'   => '2.0.0',
        'UpdateURI' => 'https://github.com/suborgerry/JotForm-Bridge',
    ];

    /** @var array<string, mixed> What set_site_transient() was last given. */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('get_option')->justReturn(['debug_logging' => false]);
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('delete_site_transient')->justReturn(true);

        $this->stored = [];

        Functions\when('set_site_transient')->alias(
            function (string $key, $value, int $ttl) {
                $this->stored = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

                return true;
            }
        );
    }

    public function testTheLatestReleaseBecomesAnUpdateWithThePackageAsset(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $this->release()));

        $update = $this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE);

        $this->assertIsArray($update);
        $this->assertSame('2.1.0', $update['version']);
        $this->assertSame(self::PLUGIN_FILE, $update['plugin']);
        $this->assertSame('jotform-bridge', $update['slug']);
        $this->assertSame(self::PLUGIN_DATA['UpdateURI'], $update['id']);
        $this->assertSame(
            'https://github.com/suborgerry/JotForm-Bridge/releases/download/v2.1.0/jotform-bridge-2.1.0.zip',
            $update['package']
        );
        $this->assertSame('https://github.com/suborgerry/JotForm-Bridge/releases/tag/v2.1.0', $update['url']);
        $this->assertSame(JOTFORM_BRIDGE_MIN_WP, $update['requires']);
        $this->assertSame(JOTFORM_BRIDGE_MIN_PHP, $update['requires_php']);

        $this->assertSame(GitHubUpdater::TRANSIENT, $this->stored['key']);
        $this->assertSame(GitHubUpdater::CACHE_TTL, $this->stored['ttl']);
    }

    public function testTheRequestCarriesTheGitHubApiHeaders(): void
    {
        $captured = [];

        Functions\when('wp_remote_get')->alias(
            function (string $url, array $args) use (&$captured) {
                $captured = ['url' => $url, 'args' => $args];

                return $this->httpResponse(200, $this->release());
            }
        );

        $this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE);

        $this->assertSame('https://api.github.com/repos/suborgerry/JotForm-Bridge/releases/latest', $captured['url']);
        $this->assertSame('application/vnd.github+json', $captured['args']['headers']['Accept']);
        $this->assertArrayHasKey('timeout', $captured['args']);
    }

    /**
     * Every plugin hosted on GitHub fires the same filter. Handing our package
     * to one of them would replace it with this plugin on the next update.
     */
    public function testAnotherGitHubPluginPassesThroughUntouched(): void
    {
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \LogicException('Another plugin must not trigger a request.');
            }
        );

        $other = ['Name' => 'Other', 'Version' => '1.0.0', 'UpdateURI' => 'https://github.com/someone/other-plugin'];

        $this->assertFalse($this->updater()->check(false, $other, 'other-plugin/other-plugin.php'));

        $earlier = ['version' => '9.0.0'];
        $this->assertSame($earlier, $this->updater()->check($earlier, $other, 'other-plugin/other-plugin.php'));
    }

    /**
     * @dataProvider repositorySpellings
     */
    public function testTheRepositoryIsRecognisedInAnySpelling(string $uri): void
    {
        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $this->release()));

        $data = ['UpdateURI' => $uri] + self::PLUGIN_DATA;

        $this->assertIsArray($this->updater()->check(false, $data, self::PLUGIN_FILE), $uri);
    }

    /**
     * @return array<string, array{string}>
     */
    public function repositorySpellings(): array
    {
        return [
            'as written'      => ['https://github.com/suborgerry/JotForm-Bridge'],
            'lower case'      => ['https://github.com/suborgerry/jotform-bridge'],
            'trailing slash'  => ['https://github.com/suborgerry/JotForm-Bridge/'],
            'clone URL'       => ['https://github.com/suborgerry/JotForm-Bridge.git'],
        ];
    }

    public function testATagWithoutTheVPrefixIsAcceptedToo(): void
    {
        $release             = $this->release();
        $release['tag_name'] = '2.1.0';

        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $release));

        $update = $this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE);

        $this->assertIsArray($update);
        $this->assertSame('2.1.0', $update['version']);
    }

    /**
     * GitHub's own source archive is not a plugin package: it unpacks into a
     * directory named after the commit and carries the whole repository.
     */
    public function testAReleaseWithoutTheBuiltZipIsNotAnUpdate(): void
    {
        $release           = $this->release();
        $release['assets'] = [];

        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $release));

        $this->assertFalse($this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE));

        // A broken release is a broken release; asking again in an hour will
        // not mend it.
        $this->assertSame(GitHubUpdater::CACHE_TTL, $this->stored['ttl']);
    }

    public function testAnAssetWithTheRightNameFromElsewhereIsRefused(): void
    {
        $release = $this->release();

        $release['assets'][0]['browser_download_url'] = 'https://example.com/jotform-bridge-2.1.0.zip';

        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $release));

        $this->assertFalse($this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE));
    }

    public function testATagThatIsNotAVersionIsNotAnUpdate(): void
    {
        $release             = $this->release();
        $release['tag_name'] = 'nightly';

        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $release));

        $this->assertFalse($this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE));
    }

    public function testNoReleaseYetIsRememberedForTheFullPeriod(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            $this->httpResponse(404, ['message' => 'Not Found'])
        );

        $this->assertFalse($this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE));
        $this->assertSame(GitHubUpdater::CACHE_TTL, $this->stored['ttl']);
    }

    /**
     * @dataProvider failures
     *
     * @param array<string, mixed>|string $body
     */
    public function testAFailedCheckIsRetriedSoonerAndLogged(?int $status, $body): void
    {
        // Built here rather than in the provider: providers run before setUp(),
        // and the stubs httpResponse() installs would not survive into the test.
        Functions\when('wp_remote_get')->justReturn(
            $status === null ? new WP_Error('http_request_failed', 'Could not resolve host') : $this->httpResponse($status, $body)
        );

        // The logger writes through a namespaced fwrite() that another test in
        // the suite may already have taken over; the same aliasing LoggerTest
        // uses keeps this one independent of the order.
        Functions\when('JotformBridge\Support\fwrite')->alias(
            static fn($file, string $data) => \fwrite($file, $data)
        );

        $logger = new Logger(new Settings());
        Functions\when('get_option')->justReturn(['debug_logging' => true]);

        $updater = new GitHubUpdater($logger);

        $this->assertFalse($updater->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE));
        $this->assertSame(GitHubUpdater::RETRY_TTL, $this->stored['ttl']);
        $this->assertNull($this->stored['value']['release']);

        $log = (string) file_get_contents($logger->path());
        $this->assertStringContainsString('[ERROR] The update check', $log);

        @unlink($logger->path());
    }

    /**
     * @return array<string, array{int|null, array<string, mixed>|string}>
     */
    public function failures(): array
    {
        return [
            'unreachable' => [null, ''],
            'rate limit'  => [403, ['message' => 'API rate limit exceeded']],
            'not JSON'    => [200, '<html>maintenance</html>'],
        ];
    }

    public function testARememberedAnswerIsUsedWithoutARequest(): void
    {
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \LogicException('A remembered answer must not trigger a request.');
            }
        );

        Functions\when('get_site_transient')->justReturn([
            'release' => [
                'version'   => '2.2.0',
                'url'       => 'https://github.com/suborgerry/JotForm-Bridge/releases/tag/v2.2.0',
                'package'   => 'https://github.com/suborgerry/JotForm-Bridge/releases/download/v2.2.0/jotform-bridge-2.2.0.zip',
                'notes'     => '',
                'published' => '',
            ],
            'checked' => time(),
        ]);

        $update = $this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE);

        $this->assertIsArray($update);
        $this->assertSame('2.2.0', $update['version']);
    }

    public function testARememberedFailureIsAlsoUsedWithoutARequest(): void
    {
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \LogicException('A remembered failure must not trigger a request.');
            }
        );

        Functions\when('get_site_transient')->justReturn(['release' => null, 'checked' => time()]);

        $this->assertFalse($this->updater()->check(false, self::PLUGIN_DATA, self::PLUGIN_FILE));
    }

    public function testTheDetailsWindowIsAnsweredForThisPluginOnly(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->httpResponse(200, $this->release()));
        Functions\when('esc_html')->returnArg();

        $updater = $this->updater();

        $this->assertFalse($updater->information(false, 'plugin_information', (object) ['slug' => 'other']));
        $this->assertFalse($updater->information(false, 'query_plugins', (object) ['slug' => 'jotform-bridge']));

        $info = $updater->information(false, 'plugin_information', (object) ['slug' => 'jotform-bridge']);

        $this->assertIsObject($info);
        $this->assertSame('2.1.0', $info->version);
        $this->assertSame('jotform-bridge', $info->slug);
        $this->assertStringContainsString('releases/download/v2.1.0/', $info->download_link);
        $this->assertStringContainsString('Updates arrive from GitHub Releases', $info->sections['changelog']);
        $this->assertSame('2026-09-17T10:00:00Z', $info->last_updated);
    }

    public function testForgetDropsTheRememberedAnswer(): void
    {
        $deleted = null;

        Functions\when('delete_site_transient')->alias(
            static function (string $key) use (&$deleted): bool {
                $deleted = $key;

                return true;
            }
        );

        $this->updater()->forget();

        $this->assertSame(GitHubUpdater::TRANSIENT, $deleted);
    }

    private function updater(): GitHubUpdater
    {
        return new GitHubUpdater(new Logger(new Settings()));
    }

    /**
     * @return array<string, mixed>
     */
    private function release(): array
    {
        $path = __DIR__ . '/../../Fixtures/GitHub/release-latest.json';

        $this->assertFileExists($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
