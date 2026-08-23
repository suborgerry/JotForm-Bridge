<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Submission\RateLimiter;
use JotformBridge\Tests\TestCase;

final class RateLimiterTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->transients = [];

        Functions\when('get_transient')->alias(
            fn(string $name) => $this->transients[$name] ?? false
        );
        Functions\when('set_transient')->alias(
            function (string $name, $value): bool {
                $this->transients[$name] = $value;

                return true;
            }
        );
    }

    public function testTheFirstSubmissionsAreAllowed(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE; $i++) {
            $this->assertSame(0, $limiter->check('contact', '203.0.113.7'));
        }
    }

    public function testTheMinuteWindowRefusesTheNextOne(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE; $i++) {
            $limiter->check('contact', '203.0.113.7');
        }

        $wait = $limiter->check('contact', '203.0.113.7');

        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(60, $wait);
    }

    public function testAnotherAddressHasItsOwnBudget(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE + 1; $i++) {
            $limiter->check('contact', '203.0.113.7');
        }

        $this->assertSame(0, $limiter->check('contact', '198.51.100.9'));
    }

    public function testAnotherIntegrationHasItsOwnBudget(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE + 1; $i++) {
            $limiter->check('contact', '203.0.113.7');
        }

        $this->assertSame(0, $limiter->check('newsletter', '203.0.113.7'));
    }

    /**
     * The hourly window has to bite once the minute windows have rolled past.
     */
    public function testTheHourlyWindowStillCapsTheTotal(): void
    {
        $limiter = new RateLimiter();

        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_rate_limits'
                    ? ['per_minute' => 0, 'per_hour' => 3]
                    : $value;
            }
        );

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(0, $limiter->check('contact', '203.0.113.7'));
        }

        $wait = $limiter->check('contact', '203.0.113.7');

        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(3600, $wait);
    }

    /**
     * A refusal must not spend the other window's allowance: once the minute
     * limit clears, the visitor still has their hourly budget.
     */
    public function testARefusedAttemptIsNotChargedToTheOtherWindow(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 20; $i++) {
            $limiter->check('contact', '203.0.113.7');
        }

        $hourly = 0;

        foreach ($this->transients as $value) {
            $hourly = max($hourly, (int) $value);
        }

        $this->assertSame(
            RateLimiter::DEFAULT_PER_MINUTE,
            $hourly,
            'Only the accepted attempts may be counted.'
        );
    }

    /**
     * Without an address every visitor would share one bucket, which would turn
     * the guard into a site-wide outage.
     */
    public function testAnUnknownAddressDisablesTheCheck(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(0, $limiter->check('contact', ''));
        }

        $this->assertSame([], $this->transients);
    }

    public function testTheLimitsAreFilterable(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_rate_limits'
                    ? ['per_minute' => 1, 'per_hour' => 1]
                    : $value;
            }
        );

        $limiter = new RateLimiter();

        $this->assertSame(0, $limiter->check('contact', '203.0.113.7'));
        $this->assertGreaterThan(0, $limiter->check('contact', '203.0.113.7'));
    }

    public function testZeroDisablesBothWindows(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_rate_limits'
                    ? ['per_minute' => 0, 'per_hour' => 0]
                    : $value;
            }
        );

        $limiter = new RateLimiter();

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(0, $limiter->check('contact', '203.0.113.7'));
        }
    }

    public function testTheGlobalScopeIsSeparateFromTheIntegrationScope(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE + 1; $i++) {
            $limiter->check('contact', '203.0.113.7');
        }

        // The per-integration budget is spent; the site-wide one is not.
        $this->assertSame(0, $limiter->checkGlobal('203.0.113.7'));
    }

    /**
     * Probing for slugs never reaches the per-integration bucket, so the
     * site-wide one is what has to stop it.
     */
    public function testTheGlobalScopeCapsProbingAcrossIntegrations(): void
    {
        $limiter = new RateLimiter();

        for ($i = 0; $i < RateLimiter::DEFAULT_GLOBAL_PER_MINUTE; $i++) {
            $this->assertSame(0, $limiter->checkGlobal('203.0.113.7'));
        }

        $this->assertGreaterThan(0, $limiter->checkGlobal('203.0.113.7'));
    }

    public function testTheGlobalLimitsAreFilterable(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_global_rate_limits'
                    ? ['per_minute' => 2, 'per_hour' => 2]
                    : $value;
            }
        );

        $limiter = new RateLimiter();

        $this->assertSame(0, $limiter->checkGlobal('203.0.113.7'));
        $this->assertSame(0, $limiter->checkGlobal('203.0.113.7'));
        $this->assertGreaterThan(0, $limiter->checkGlobal('203.0.113.7'));
    }

    /**
     * No address may end up readable in storage.
     */
    public function testNoAddressIsStored(): void
    {
        (new RateLimiter())->check('contact', '203.0.113.7');

        $this->assertNotSame([], $this->transients);
        $this->assertStringNotContainsString(
            '203.0.113.7',
            (string) json_encode(array_keys($this->transients))
        );
    }
}
