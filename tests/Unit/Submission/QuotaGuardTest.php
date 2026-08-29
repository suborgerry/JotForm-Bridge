<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Submission\QuotaGuard;
use JotformBridge\Tests\TestCase;

final class QuotaGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
    }

    public function testAFreshSiteAllowsSubmissions(): void
    {
        $this->assertTrue($this->guard()->allows());
    }

    public function testTheFloorAppliesUntilThereIsHistory(): void
    {
        $guard = $this->guard();

        for ($i = 0; $i < QuotaGuard::MIN_DAILY; $i++) {
            $this->assertTrue($guard->allows(), 'Refused after ' . $i . ' submissions.');
            $guard->record();
        }

        $this->assertFalse($guard->allows());
        $this->assertSame(QuotaGuard::REASON_DAILY, $guard->check());
        $this->assertTrue($guard->isTripped());
    }

    /**
     * A busy site earns a ceiling above the floor: one that normally takes a
     * hundred submissions a day is allowed six times that.
     */
    public function testTheCeilingFollowsTheRecentMedian(): void
    {
        $this->seedHistory([100, 100, 120, 90, 110, 100, 100]);

        $guard    = $this->guard();
        $expected = QuotaGuard::BURST_FACTOR * 100;

        $this->assertSame($expected, $guard->status()['ceiling']);

        for ($i = 0; $i < $expected; $i++) {
            $guard->record();
        }

        $this->assertFalse($guard->allows());
    }

    /**
     * A quiet site gets the floor, not six times almost nothing — otherwise the
     * first busy day it ever has would be read as an attack.
     */
    /**
     * While the allowance half is hidden, nothing about it may influence a
     * submission: no ceiling from it, no warning, no request to find out.
     */

    public function testAQuietSiteStillGetsTheFloor(): void
    {
        $this->seedHistory([2, 1, 3, 0, 1, 2, 1]);

        $this->assertSame(QuotaGuard::MIN_DAILY, $this->guard()->status()['ceiling']);
    }

    /**
     * The day in progress must not raise the ceiling it is being measured
     * against, or a flood would keep buying itself more room.
     */
    public function testTodayIsExcludedFromTheMedian(): void
    {
        $this->seedHistory([10, 10, 10, 10, 10, 10, 10]);

        $guard = $this->guard();
        $before = $guard->status()['ceiling'];

        for ($i = 0; $i < 20; $i++) {
            $guard->record();
        }

        $this->assertSame($before, $guard->status()['ceiling']);
    }

    /**
     * Submissions sent since the snapshot count too — a stale snapshot alone
     * would undercount, which is the dangerous direction here.
     */

    /**
     * Without the allowance entered there is nothing to compare against, so the
     * rate ceiling is the only thing left.
     */

    public function testResettingClearsTheTripAndTodaysCount(): void
    {
        $guard = $this->guard();

        for ($i = 0; $i < QuotaGuard::MIN_DAILY; $i++) {
            $guard->record();
        }

        $this->assertFalse($guard->allows());

        $guard->reset();

        $this->assertTrue($guard->allows());
        $this->assertFalse($guard->isTripped());
        $this->assertSame(0, $guard->status()['today']);
    }

    /**
     * Yesterday's numbers are what the ceiling is derived from, so a reset must
     * not throw them away.
     */
    public function testResettingKeepsTheHistory(): void
    {
        $this->seedHistory([10, 10, 10, 10, 10, 10, 10]);

        $guard   = $this->guard();
        $ceiling = $guard->status()['ceiling'];

        $guard->reset();

        $this->assertSame($ceiling, $guard->status()['ceiling']);
    }

    public function testTheCeilingIsFilterable(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_daily_ceiling' ? 3 : $value;
            }
        );

        $guard = $this->guard();

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($guard->allows());
            $guard->record();
        }

        $this->assertFalse($guard->allows());
    }

    public function testHistoryIsBounded(): void
    {
        $days = [];

        for ($i = 1; $i <= 60; $i++) {
            $days[gmdate('Y-m-d', time() - $i * 86400)] = 1;
        }

        $this->options[QuotaGuard::OPTION] = ['days' => $days];

        $this->guard()->record();

        $this->assertLessThanOrEqual(30, count($this->options[QuotaGuard::OPTION]['days']));
    }

    /**
     * A failed refresh must not throw the previous number away: a stale figure
     * is a better basis for the ceiling than none.
     */

    private function guard(): QuotaGuard
    {
        return new QuotaGuard();
    }

    /**
     * @param array<int, int> $counts Most recent day first.
     */
    private function seedHistory(array $counts): void
    {
        $days = [];

        foreach ($counts as $index => $count) {
            $days[gmdate('Y-m-d', time() - ($index + 1) * 86400)] = $count;
        }

        $this->options[QuotaGuard::OPTION] = ['days' => $days];
    }
}
