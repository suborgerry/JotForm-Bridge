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
            'base_url' => 'https://forms.example.com/api/',
        ]);

        $this->assertSame('https://forms.example.com/api', $settings->baseUrl());
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

    public function testStoredKeyIsNeverReturnedInFullForDisplay(): void
    {
        $settings = $this->withStored(['api_key' => 'abcdefgh12345678']);

        $masked = $settings->maskedApiKey();

        $this->assertSame('********5678', $masked);
        $this->assertStringNotContainsString('abcdefgh', $masked);
        $this->assertSame(Settings::SOURCE_OPTION, $settings->apiKeySource());
        $this->assertFalse($settings->isApiKeyLocked());
    }

    public function testEmptyKeyFieldKeepsTheStoredKey(): void
    {
        $settings = $this->withStored(['api_key' => 'stored-key']);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save(['api_key' => '', 'region' => Settings::REGION_EU, 'debug_logging' => '1']);

        $this->assertSame('stored-key', $saved['api_key']);
        $this->assertSame(Settings::REGION_EU, $saved['region']);
        $this->assertTrue($saved['debug_logging']);
    }

    public function testRemoveCheckboxClearsTheStoredKey(): void
    {
        $settings = $this->withStored(['api_key' => 'stored-key']);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save(['api_key' => '', 'remove_api_key' => '1']);

        $this->assertSame('', $saved['api_key']);
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
            'base_url' => 'https://user:pw@Forms.Example.com:8443/api/?key=leak#frag',
        ]);

        $this->assertSame('https://forms.example.com:8443/api', $saved['base_url']);
    }

    public function testAnArrayInsteadOfAScalarDoesNotBecomeAValue(): void
    {
        $settings = $this->withStored(['api_key' => 'stored-key']);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $settings->save([
            'api_key'  => ['nested'],
            'region'   => ['nested'],
            'base_url' => ['nested'],
        ]);

        $this->assertSame('stored-key', $saved['api_key'], 'An array must not overwrite the key.');
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
    public function testConstantWinsOverTheStoredOptionAndCannotBeOverwritten(): void
    {
        define('JOTFORM_API_KEY', 'constant-key');

        $settings = $this->withStored(['api_key' => 'stored-key']);
        $saved    = null;

        Functions\when('update_option')->alias(
            static function (string $name, $value) use (&$saved): bool {
                $saved = $value;

                return true;
            }
        );

        $this->assertSame('constant-key', $settings->apiKey());
        $this->assertSame(Settings::SOURCE_CONSTANT, $settings->apiKeySource());
        $this->assertTrue($settings->isApiKeyLocked());

        $settings->save(['api_key' => 'ui-key']);

        $this->assertSame('stored-key', $saved['api_key']);
    }
}
