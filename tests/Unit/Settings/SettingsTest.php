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
