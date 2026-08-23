<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Support;

use Brain\Monkey\Functions;
use JotformBridge\Support\Stats;
use JotformBridge\Tests\TestCase;

final class StatsTest extends TestCase
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
        Functions\when('delete_option')->alias(
            function (string $name): bool {
                unset($this->options[$name]);

                return true;
            }
        );
    }

    public function testOutcomesAreCountedPerIntegration(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::OK);
        $stats->record('contact', Stats::OK);
        $stats->record('contact', Stats::SPAM);
        $stats->record('newsletter', Stats::OK);

        $contact = $stats->summary('contact');

        $this->assertSame(2, $contact['ok']);
        $this->assertSame(3, $contact['attempts']);
        $this->assertSame(1, $contact['counts'][Stats::SPAM]);
        $this->assertSame(1, $stats->summary('newsletter')['ok']);
    }

    public function testLostCountsWhatNeverReachedJotform(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::OK);
        $stats->record('contact', Stats::SPAM);
        $stats->record('contact', Stats::THROTTLED);
        $stats->record('contact', Stats::UPSTREAM);
        // A visitor's own mistake is not a loss.
        $stats->record('contact', Stats::INVALID);

        $this->assertSame(3, $stats->summary('contact')['lost']);
    }

    public function testTheLastSuccessIsRemembered(): void
    {
        $stats = new Stats();

        $this->assertSame(0, $stats->lastSuccess('contact'));

        $stats->record('contact', Stats::OK);

        $this->assertGreaterThan(0, $stats->lastSuccess('contact'));
    }

    /**
     * A failure must not look like a success just because it came later.
     */
    public function testAFailureDoesNotUpdateTheLastSuccess(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::UPSTREAM);

        $this->assertSame(0, $stats->lastSuccess('contact'));
    }

    /**
     * Probing must not be able to grow the option one key per invented slug.
     */
    public function testUnresolvedRequestsShareOneBucket(): void
    {
        $stats = new Stats();

        for ($i = 0; $i < 200; $i++) {
            $stats->record(Stats::GLOBAL_SCOPE, Stats::UNKNOWN);
        }

        $this->assertCount(1, $this->options[Stats::OPTION]);
        $this->assertSame(200, $stats->summary(Stats::GLOBAL_SCOPE)['attempts']);
    }

    public function testTheNumberOfTrackedSlugsIsBounded(): void
    {
        $stats = new Stats();

        for ($i = 0; $i < 200; $i++) {
            $stats->record('slug-' . $i, Stats::OK);
        }

        $this->assertLessThanOrEqual(51, count($this->options[Stats::OPTION]));
    }

    public function testFieldErrorsAreTalliedWorstFirst(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::INVALID, ['phone', 'email']);
        $stats->record('contact', Stats::INVALID, ['phone']);
        $stats->record('contact', Stats::INVALID, ['phone']);

        $this->assertSame(['phone' => 3, 'email' => 1], $stats->fieldErrors('contact'));
    }

    public function testHealthIsIdleWithoutTraffic(): void
    {
        $this->assertSame('idle', (new Stats())->health('contact'));
    }

    /**
     * The signal the whole thing exists for: traffic is arriving and none of it
     * is getting through.
     */
    public function testHealthIsBrokenWhenNothingGetsThrough(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::UPSTREAM);
        $stats->record('contact', Stats::UPSTREAM);

        $this->assertSame('broken', $stats->health('contact'));
    }

    public function testHealthIsNoisyWhenAlmostEverythingIsRefused(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::OK);

        for ($i = 0; $i < 30; $i++) {
            $stats->record('contact', Stats::SPAM);
        }

        $this->assertSame('noisy', $stats->health('contact'));
    }

    public function testHealthIsOkOnOrdinaryTraffic(): void
    {
        $stats = new Stats();

        for ($i = 0; $i < 10; $i++) {
            $stats->record('contact', Stats::OK);
        }

        $stats->record('contact', Stats::SPAM);

        $this->assertSame('ok', $stats->health('contact'));
    }

    public function testDeletingAnIntegrationDropsItsTally(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::OK);
        $stats->forget('contact');

        $this->assertSame(0, $stats->summary('contact')['attempts']);
    }

    public function testHistoryIsBounded(): void
    {
        $days = [];

        for ($i = 1; $i <= 60; $i++) {
            $days[gmdate('Y-m-d', time() - $i * 86400)] = ['counts' => [Stats::OK => 1]];
        }

        $this->options[Stats::OPTION] = ['contact' => ['days' => $days]];

        (new Stats())->record('contact', Stats::OK);

        $this->assertLessThanOrEqual(30, count($this->options[Stats::OPTION]['contact']['days']));
    }

    /**
     * Older days must not leak into a shorter window.
     */
    public function testTheWindowRespectsItsLength(): void
    {
        $this->options[Stats::OPTION] = [
            'contact' => [
                'days' => [
                    gmdate('Y-m-d')                        => ['counts' => [Stats::OK => 1]],
                    gmdate('Y-m-d', time() - 3 * 86400)    => ['counts' => [Stats::OK => 5]],
                ],
            ],
        ];

        $stats = new Stats();

        $this->assertSame(1, $stats->summary('contact', 1)['ok']);
        $this->assertSame(6, $stats->summary('contact', 7)['ok']);
    }

    /**
     * The plugin's promise is that submissions live in Jotform; a local copy of
     * anything a visitor typed would break it.
     */
    public function testNoSubmittedValueIsEverStored(): void
    {
        $stats = new Stats();

        $stats->record('contact', Stats::INVALID, ['email']);
        $stats->record('contact', Stats::OK);

        $stored = (string) json_encode($this->options[Stats::OPTION]);

        $this->assertStringNotContainsString('jane@example.com', $stored);
        $this->assertStringNotContainsString('203.0.113', $stored);
    }
}
