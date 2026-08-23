<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Submission\Guards\MinimumTime;
use JotformBridge\Tests\TestCase;

final class MinimumTimeTest extends TestCase
{
    public function testAFormFilledInInstantlyIsRejected(): void
    {
        $this->assertFalse($this->check(['t' => 0]));
        $this->assertFalse($this->check(['t' => 1]));
    }

    public function testAPlausibleDurationIsAllowed(): void
    {
        $this->assertTrue($this->check(['t' => MinimumTime::MIN_SECONDS]));
        $this->assertTrue($this->check(['t' => 45]));
    }

    /**
     * The number arrives as JSON, so it can be a string.
     */
    public function testANumericStringIsUnderstood(): void
    {
        $this->assertTrue($this->check(['t' => '30']));
        $this->assertFalse($this->check(['t' => '0']));
    }

    /**
     * Markup that has not been updated must keep working, exactly like a form
     * with no honeypot.
     */
    public function testAMissingMeasurementIsAllowed(): void
    {
        $this->assertTrue($this->check([]));
    }

    /**
     * A tab left open overnight says nothing either way.
     */
    public function testAnImplausiblyLongDurationIsTreatedAsAbsent(): void
    {
        $this->assertTrue($this->check(['t' => 999999]));
        $this->assertTrue($this->check(['t' => -5]));
    }

    public function testANonNumericValueIsRejected(): void
    {
        $this->assertFalse($this->check(['t' => 'soon']));
        $this->assertFalse($this->check(['t' => ['x']]));
    }

    public function testTheThresholdIsFilterable(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_minimum_time' ? 30 : $value;
            }
        );

        $this->assertFalse($this->check(['t' => 10]));
        $this->assertTrue($this->check(['t' => 31]));
    }

    public function testZeroDisablesTheCheck(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_minimum_time' ? 0 : $value;
            }
        );

        $this->assertTrue($this->check(['t' => 0]));
    }

    public function testAnEarlierRejectionIsNotOverruled(): void
    {
        $guard = new MinimumTime();

        $this->assertSame(
            'Blocked.',
            $guard->check('Blocked.', 'contact', [], ['spam' => ['t' => 60]])
        );
    }

    /**
     * @param array<string, mixed> $spam
     *
     * @return bool|string
     */
    private function check(array $spam)
    {
        return (new MinimumTime())->check(true, 'contact', [], ['spam' => $spam]);
    }
}
