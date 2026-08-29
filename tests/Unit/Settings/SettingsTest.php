<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use JotformBridge\Settings\Settings;
use JotformBridge\Tests\TestCase;

final class SettingsTest extends TestCase
{
    /**
     * @param array<string, mixed> $stored
     */
    private function withStored(array $stored): Settings
    {
        Functions\when('get_option')->alias(
            static function (string $name, $default = false) use ($stored) {
                return $name === Settings::OPTION ? $stored : $default;
            }
        );

        return new Settings();
    }

    public function testDefaultsApplyWhenNothingIsStored(): void
    {
        $settings = $this->withStored([]);

        $this->assertSame('', $settings->apiKey());
        $this->assertFalse($settings->hasApiKey());
        $this->assertSame(Settings::SOURCE_NONE, $settings->apiKeySource());
        $this->assertSame(Settings::REGION_STANDARD, $settings->region());
        $this->assertSame('https://api.jotform.com', $settings->baseUrl());
        $this->assertFalse($settings->debugEnabled());
    }

    public function testEuRegionResolvesToTheEuBaseUrl(): void
    {
        $settings = $this->withStored(['region' => Settings::REGION_EU]);

        $this->assertSame('https://eu-api.jotform.com', $settings->baseUrl());
    }

    public function testCustomRegionUsesTheCustomUrlWithoutTrailingSlash(): void
    {
        $settings = $this->withStored([
            'region'   => Settings::REGION_CUSTOM,
            'base_url' => 'https://enterprise.jotform.com/api/',
        ]);

        $this->assertSame('https://enterprise.jotform.com/api', $settings->baseUrl());
    }

    /**
     * The custom region exists for Jotform deployments the plugin does not know
     * about, not for sending the API key to an arbitrary server.
     */
    public function testACustomUrlOnAnotherHostIsRefused(): void
    {
        $settings = $this->withStored([
            'region'   => Settings::REGION_CUSTOM,
            'base_url' => 'https://forms.example.com/api/',
        ]);

        $this->assertSame('https://api.jotform.com', $settings->baseUrl());
    }

    /**
     * A host that merely ends with the same letters is a different host.
     */
    public function testALookalikeHostIsRefused(): void
    {
        $this->assertFalse(Settings::isAllowedHost('notjotform.com'));
        $this->assertFalse(Settings::isAllowedHost('jotform.com.evil.example'));
        $this->assertTrue(Settings::isAllowedHost('jotform.com'));
        $this->assertTrue(Settings::isAllowedHost('eu-api.jotform.com'));
        $this->assertTrue(Settings::isAllowedHost('API.JOTFORM.COM'));
    }

    public function testTheAllowedHostsAreFilterable(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_allowed_api_hosts'
                    ? ['jotform.com', 'forms.internal.example']
                    : $value;
            }
        );

        $this->assertTrue(Settings::isAllowedHost('forms.internal.example'));
        $this->assertSame(
            'https://forms.internal.example/api',
            Settings::sanitizeBaseUrl('https://forms.internal.example/api/')
        );
    }

    public function testCustomRegionWithoutUrlFallsBackToStandard(): void
    {
        $settings = $this->withStored(['region' => Settings::REGION_CUSTOM, 'base_url' => '']);

        $this->assertSame('https://api.jotform.com', $settings->baseUrl());
    }

    public function testUnknownRegionFallsBackToStandard(): void
    {
        $settings = $this->withStored(['region' => 'mars']);

        $this->assertSame(Settings::REGION_STANDARD, $settings->region());
    }

    public function testAKeyLeftInTheOptionIsNotAKeySource(): void
    {
        $settings = $this->withStored(['api_key' => 'legacy-key']);

        $this->assertSame('', $settings->apiKey());
        $this->assertFalse($settings->hasApiKey());
        $this->assertSame(Settings::SOURCE_NONE, $settings->apiKeySource());
        $this->assertSame('', $settings->maskedApiKey());
        $this->assertArrayNotHasKey('api_key', $settings->all());
    }

    public function testSavingDropsAKeyLeftInTheOptionAndIgnoresASubmittedOne(): void
    {
        $settings = $this->withStored(['api_key' => 'legacy-key']);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save(['api_key' => 'ui-key', 'region' => Settings::REGION_EU, 'debug_logging' => '1']);

        $this->assertArrayNotHasKey('api_key', $saved);
        $this->assertSame(Settings::REGION_EU, $saved['region']);
        $this->assertTrue($saved['debug_logging']);
    }

    public function testPurgeRemovesALegacyKeyFromTheOption(): void
    {
        $saved = null;

        Functions\when('get_option')->alias(
            static function (string $name, $default = false) {
                return $name === Settings::OPTION
                    ? ['api_key' => 'legacy-key', 'region' => Settings::REGION_EU]
                    : $default;
            }
        );
        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        Settings::purgeStoredKey();

        $this->assertIsArray($saved);
        $this->assertArrayNotHasKey('api_key', $saved);
        $this->assertSame(Settings::REGION_EU, $saved['region']);
    }

    public function testPurgeDoesNotWriteWhenThereIsNothingToRemove(): void
    {
        $written = false;

        Functions\when('get_option')->alias(
            static function (string $name, $default = false) {
                return $name === Settings::OPTION ? ['region' => Settings::REGION_EU] : $default;
            }
        );
        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$written): bool {
                $written = true;

                return true;
            }
        );

        Settings::purgeStoredKey();

        $this->assertFalse($written);
    }

    public function testInvalidRegionInputIsRejectedOnSave(): void
    {
        $settings = $this->withStored([]);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save(['region' => '<script>eu</script>']);

        $this->assertSame(Settings::REGION_STANDARD, $saved['region']);
    }

    /**
     * The custom base URL decides where the API key is sent, so anything that is
     * not an absolute http(s) URL with a host must not survive the save.
     *
     * @dataProvider rejectedBaseUrls
     */
    public function testAnUnusableCustomBaseUrlIsDiscarded(string $submitted): void
    {
        $settings = $this->withStored([]);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save(['region' => Settings::REGION_CUSTOM, 'base_url' => $submitted]);

        $this->assertSame('', $saved['base_url'], sprintf('"%s" must not be stored.', $submitted));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function rejectedBaseUrls(): array
    {
        return [
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme'       => ['data:text/plain,hi'],
            'file scheme'       => ['file:///etc/passwd'],
            'ftp scheme'        => ['ftp://example.com'],
            'no scheme'         => ['api.jotform.com'],
            'scheme only'       => ['https://'],
            'markup'            => ['<script>https://evil.test</script>'],
        ];
    }

    public function testACustomBaseUrlKeepsOnlySchemeHostAndPath(): void
    {
        $settings = $this->withStored([]);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save([
            'region'   => Settings::REGION_CUSTOM,
            'base_url' => 'https://user:pw@EU-API.Jotform.com:8443/api/?key=leak#frag',
        ]);

        $this->assertSame('https://eu-api.jotform.com:8443/api', $saved['base_url']);
    }

    public function testAnArrayInsteadOfAScalarDoesNotBecomeAValue(): void
    {
        $settings = $this->withStored([]);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save([
            'region'   => ['nested'],
            'base_url' => ['nested'],
        ]);

        $this->assertSame(Settings::REGION_STANDARD, $saved['region']);
        $this->assertSame('', $saved['base_url']);
    }

    public function testUninstallCleanupIsOffByDefaultAndOptIn(): void
    {
        $this->assertFalse($this->withStored([])->deletesDataOnUninstall());
        $this->assertTrue(
            $this->withStored(['delete_data_on_uninstall' => true])->deletesDataOnUninstall()
        );

        $saved = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $this->withStored([])->save(['delete_data_on_uninstall' => '1']);

        $this->assertTrue($saved['delete_data_on_uninstall']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheConstantIsTheOnlyKeySourceAndSurvivesASave(): void
    {
        define('JOTFORM_API_KEY', ' constant-key ');

        $settings = $this->withStored(['api_key' => 'legacy-key']);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $this->assertSame('constant-key', $settings->apiKey());
        $this->assertTrue($settings->hasApiKey());
        $this->assertSame(Settings::SOURCE_CONSTANT, $settings->apiKeySource());

        $settings->save(['api_key' => 'ui-key']);

        $this->assertArrayNotHasKey('api_key', $saved);
        $this->assertSame('constant-key', $settings->apiKey());
    }

    /**
     * Six methods on Settings read the option, and Logger asks whether
     * debugging is on for every line it writes.
     */
    public function testTheOptionIsReadOncePerRequest(): void
    {
        $reads = 0;

        Functions\when('get_option')->alias(
            function (string $name, $default = false) use (&$reads) {
                if ($name === Settings::OPTION) {
                    $reads++;
                }

                return ['region' => Settings::REGION_EU];
            }
        );

        $settings = new Settings();

        $settings->region();
        $settings->baseUrl();
        $settings->debugEnabled();
        $settings->deletesDataOnUninstall();

        $this->assertSame(1, $reads);
    }

    public function testASaveIsVisibleImmediately(): void
    {
        $stored = ['region' => Settings::REGION_STANDARD];

        Functions\when('get_option')->alias(
            static function (string $name, $default = false) use (&$stored) {
                return $stored;
            }
        );
        Functions\when('update_option')->alias(
            function (string $name, $value) use (&$stored): bool {
                $stored = $value;

                return true;
            }
        );

        $settings = new Settings();

        $this->assertSame(Settings::REGION_STANDARD, $settings->region());

        $settings->save(['region' => Settings::REGION_EU]);

        $this->assertSame(Settings::REGION_EU, $settings->region());
    }

    /**
     * purgeStoredKey() is static, so it can change the option without any
     * instance knowing. A live instance must not keep serving what it read
     * before the purge.
     */
    public function testAStaticPurgeIsVisibleToALiveInstance(): void
    {
        $stored = ['region' => Settings::REGION_EU, 'api_key' => 'legacy'];

        Functions\when('get_option')->alias(
            static function (string $name, $default = false) use (&$stored) {
                return $stored;
            }
        );
        Functions\when('update_option')->alias(
            function (string $name, $value) use (&$stored): bool {
                $stored = $value;

                return true;
            }
        );

        $settings = new Settings();

        $this->assertSame(Settings::REGION_EU, $settings->region());

        $stored['region'] = Settings::REGION_HIPAA;

        Settings::purgeStoredKey();

        $this->assertSame(Settings::REGION_HIPAA, $settings->region());
        $this->assertArrayNotHasKey('api_key', $stored);
    }
}
