<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Settings\Settings;
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
     * A site that normally takes ten submissions a day is allowed five times
     * that, and stopped above it.
     */
    public function testTheCeilingFollowsTheRecentMedian(): void
    {
        $this->seedHistory([10, 10, 12, 9, 11, 10, 10]);

        $guard    = $this->guard();
        $expected = QuotaGuard::BURST_FACTOR * 10;

        $this->assertSame($expected, $guard->status()['ceiling']);

        for ($i = 0; $i < $expected; $i++) {
            $guard->record();
        }

        $this->assertFalse($guard->allows());
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

    public function testTheRemainingAllowanceCapsTheCeiling(): void
    {
        $this->options[Settings::OPTION] = ['monthly_quota' => 100];
        $this->options[QuotaGuard::OPTION] = [
            'days'              => [],
            'usage_submissions' => 93,
            'usage_checked_at'  => time(),
            'since_check'       => 0,
        ];

        $guard = $this->guard();

        // Seven left, which is far below the floor of fifty.
        $this->assertSame(7, $guard->status()['remaining']);
        $this->assertSame(7, $guard->status()['ceiling']);

        for ($i = 0; $i < 7; $i++) {
            $this->assertTrue($guard->allows());
            $guard->record();
        }

        $this->assertFalse($guard->allows());
        $this->assertSame(QuotaGuard::REASON_QUOTA, $guard->check());
    }

    /**
     * Submissions sent since the snapshot count too — a stale snapshot alone
     * would undercount, which is the dangerous direction here.
     */
    public function testSubmissionsSinceTheSnapshotCountTowardsTheAllowance(): void
    {
        $this->options[Settings::OPTION]   = ['monthly_quota' => 100];
        $this->options[QuotaGuard::OPTION] = [
            'days'              => [],
            'usage_submissions' => 90,
            'usage_checked_at'  => time(),
            'since_check'       => 0,
        ];

        $guard = $this->guard();
        $guard->record();
        $guard->record();

        $this->assertSame(92, $guard->status()['used']);
        $this->assertSame(8, $guard->status()['remaining']);
    }

    public function testAnExhaustedAllowanceRefusesEverything(): void
    {
        $this->options[Settings::OPTION]   = ['monthly_quota' => 100];
        $this->options[QuotaGuard::OPTION] = [
            'days'              => [],
            'usage_submissions' => 100,
            'usage_checked_at'  => time(),
            'since_check'       => 0,
        ];

        $this->assertSame(QuotaGuard::REASON_QUOTA, $this->guard()->check());
    }

    /**
     * Without the allowance entered there is nothing to compare against, so the
     * rate ceiling is the only thing left.
     */
    public function testAnUnknownAllowanceLeavesOnlyTheRateCeiling(): void
    {
        $this->options[QuotaGuard::OPTION] = [
            'days'              => [],
            'usage_submissions' => 5000,
            'usage_checked_at'  => time(),
        ];

        $guard = $this->guard();

        $this->assertNull($guard->status()['remaining']);
        $this->assertSame(QuotaGuard::MIN_DAILY, $guard->status()['ceiling']);
    }

    public function testTheWarningFiresBeforeTheAllowanceIsGone(): void
    {
        $this->options[Settings::OPTION]   = ['monthly_quota' => 100];
        $this->options[QuotaGuard::OPTION] = [
            'usage_submissions' => 90,
            'usage_checked_at'  => time(),
        ];

        $guard = $this->guard();

        $this->assertTrue($guard->isNearQuota());
        $this->assertTrue($guard->allows(), 'A warning must not stop submissions on its own.');
    }

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

    public function testAFreshSnapshotIsNotRefetched(): void
    {
        $this->options[QuotaGuard::OPTION] = [
            'usage_submissions' => 12,
            'usage_checked_at'  => time(),
        ];

        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \RuntimeException('A fresh snapshot must not be refetched.');
            }
        );

        $this->assertNull(
            $this->guard()->refreshUsage(new JotformClient('key', 'https://api.jotform.com'))
        );
    }

    public function testARefreshStoresTheSpendAndClearsTheSinceCounter(): void
    {
        $this->options[QuotaGuard::OPTION] = ['since_check' => 9, 'usage_checked_at' => 0];

        $response = $this->httpResponse(200, ['responseCode' => 200, 'content' => ['submissions' => 42]]);

        Functions\when('wp_remote_get')->justReturn($response);

        $guard  = $this->guard();
        $result = $guard->refreshUsage(new JotformClient('key', 'https://api.jotform.com'));

        $this->assertNotNull($result);
        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $guard->status()['usage_submissions']);
        $this->assertSame(0, $guard->status()['since_check']);
    }

    /**
     * A failed refresh must not throw the previous number away: a stale figure
     * is a better basis for the ceiling than none.
     */
    public function testAFailedRefreshKeepsThePreviousSnapshot(): void
    {
        $this->options[QuotaGuard::OPTION] = [
            'usage_submissions' => 42,
            'usage_checked_at'  => 1,
        ];

        Functions\when('wp_remote_get')->justReturn(new \WP_Error('http_request_failed', 'down'));

        $guard  = $this->guard();
        $result = $guard->refreshUsage(new JotformClient('key', 'https://api.jotform.com'));

        $this->assertNotNull($result);
        $this->assertFalse($result->isSuccess());
        $this->assertSame(42, $guard->status()['usage_submissions']);
    }

    private function guard(): QuotaGuard
    {
        return new QuotaGuard(new Settings());
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
