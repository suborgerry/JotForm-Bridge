<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Support;

use Brain\Monkey\Functions;
use JotformBridge\Support\Features;
use JotformBridge\Tests\TestCase;

final class FeaturesTest extends TestCase
{
    public function testTheOptionalPartsAreHiddenByDefault(): void
    {
        $this->assertFalse(Features::enabled(Features::STATS_UI));
        $this->assertFalse(Features::enabled(Features::ACCOUNT_QUOTA));
    }

    public function testAnUnknownFeatureIsOff(): void
    {
        $this->assertFalse(Features::enabled('something-else'));
    }

    public function testAFeatureCanBeSwitchedOnWithoutTouchingTheOthers(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value, ...$args) {
                if ($hook !== 'jotform_bridge_feature_enabled') {
                    return $value;
                }

                return $args[0] === Features::STATS_UI ? true : $value;
            }
        );

        $this->assertTrue(Features::enabled(Features::STATS_UI));
        $this->assertFalse(Features::enabled(Features::ACCOUNT_QUOTA));
    }
}
