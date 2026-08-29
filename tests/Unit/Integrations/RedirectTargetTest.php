<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Integrations;

use Brain\Monkey\Functions;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\RedirectTarget;
use JotformBridge\Tests\TestCase;

/**
 * The one place that decides whether a redirect may be served.
 *
 * Every "no" here is the same no: no URL in the answer, and the submission is
 * unaffected. The tests therefore assert on the resolved URL as much as on the
 * state, because the state is only a label — the empty URL is the guarantee.
 */
final class RedirectTargetTest extends TestCase
{
    private const PAGE_ID = 42;

    /** @var array<int, string> Post ID => post status. */
    private array $statuses = [];

    /** @var array<int, string|false> Post ID => permalink. */
    private array $permalinks = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->statuses   = [self::PAGE_ID => 'publish'];
        $this->permalinks = [self::PAGE_ID => 'https://example.com/thanks/'];

        Functions\when('home_url')->alias(
            static fn(string $path = ''): string => 'https://example.com' . $path
        );

        Functions\when('get_post_status')->alias(
            fn($post) => $this->statuses[(int) $post] ?? false
        );

        Functions\when('get_permalink')->alias(
            fn($post) => $this->permalinks[(int) $post] ?? false
        );

        // Mirrors the real helper closely enough for what the plugin relies on:
        // a location outside the site falls back to the given default.
        Functions\when('wp_validate_redirect')->alias(
            static function (string $location, string $default = ''): string {
                if (str_starts_with($location, '//')) {
                    $location = 'http:' . $location;
                }

                $host = parse_url($location, PHP_URL_HOST);

                return is_string($host) && strcasecmp($host, 'example.com') === 0 ? $location : $default;
            }
        );
    }

    public function testAnIntegrationWithoutRedirectResolvesToNothing(): void
    {
        $target = (new RedirectTarget())->check($this->integration(Integration::SUCCESS_MESSAGE));

        $this->assertSame(RedirectTarget::STATE_DISABLED, $target['state']);
        $this->assertSame('', $target['url']);
        $this->assertFalse(RedirectTarget::isBroken($target), 'No redirect configured is not a problem.');
    }

    public function testAPublishedPageResolvesToItsPermalink(): void
    {
        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_OK, $target['state']);
        $this->assertSame('https://example.com/thanks/', $target['url']);
        $this->assertSame(0, $target['delay']);
    }

    public function testThePermalinkIsResolvedOnEveryCallRatherThanRemembered(): void
    {
        $redirects = new RedirectTarget();

        $this->assertSame('https://example.com/thanks/', $redirects->check($this->integration())['url']);

        $this->permalinks[self::PAGE_ID] = 'https://example.com/thank-you/';

        $this->assertSame(
            'https://example.com/thank-you/',
            $redirects->check($this->integration())['url'],
            'A changed permalink must be reflected immediately.'
        );
    }

    public function testRedirectWithoutAPageIsReportedAsBroken(): void
    {
        $target = (new RedirectTarget())->check($this->integration(Integration::SUCCESS_REDIRECT, 0));

        $this->assertSame(RedirectTarget::STATE_UNSET, $target['state']);
        $this->assertSame('', $target['url']);
        $this->assertTrue(RedirectTarget::isBroken($target));
    }

    public function testADeletedPageYieldsNoRedirect(): void
    {
        $this->statuses = [];

        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_MISSING, $target['state']);
        $this->assertSame('', $target['url']);
        $this->assertTrue(RedirectTarget::isBroken($target));
    }

    public function testATrashedPageYieldsNoRedirect(): void
    {
        $this->statuses[self::PAGE_ID] = 'trash';

        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_TRASHED, $target['state']);
        $this->assertSame('', $target['url']);
    }

    /**
     * @dataProvider unpublishedStatuses
     */
    public function testAnUnpublishedPageYieldsNoRedirect(string $status): void
    {
        $this->statuses[self::PAGE_ID] = $status;

        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_UNPUBLISHED, $target['state']);
        $this->assertSame('', $target['url']);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function unpublishedStatuses(): array
    {
        return [
            'draft'   => ['draft'],
            'pending' => ['pending'],
            'private' => ['private'],
            'future'  => ['future'],
        ];
    }

    public function testAPermalinkOnAnotherHostIsRefused(): void
    {
        $this->permalinks[self::PAGE_ID] = 'https://evil.example/thanks/';

        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_INVALID_URL, $target['state']);
        $this->assertSame('', $target['url']);
    }

    public function testAProtocolRelativePermalinkIsRefused(): void
    {
        $this->permalinks[self::PAGE_ID] = '//evil.example/thanks/';

        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_INVALID_URL, $target['state']);
        $this->assertSame('', $target['url']);
    }

    public function testAPageWithoutAPermalinkIsRefused(): void
    {
        $this->permalinks[self::PAGE_ID] = false;

        $target = (new RedirectTarget())->check($this->integration());

        $this->assertSame(RedirectTarget::STATE_INVALID_URL, $target['state']);
        $this->assertSame('', $target['url']);
        $this->assertTrue(RedirectTarget::isBroken($target));
    }

    public function testTheDelayIsCarriedThroughAndClamped(): void
    {
        $this->assertSame(
            5,
            (new RedirectTarget())->check($this->integration(Integration::SUCCESS_REDIRECT, self::PAGE_ID, 5))['delay']
        );

        $this->assertSame(
            Integration::MAX_REDIRECT_DELAY,
            (new RedirectTarget())->check(
                $this->integration(Integration::SUCCESS_REDIRECT, self::PAGE_ID, 9000)
            )['delay']
        );
    }

    private function integration(
        string $action = Integration::SUCCESS_REDIRECT,
        int $pageId = self::PAGE_ID,
        int $delay = 0
    ): Integration {
        return new Integration(
            'contact',
            'Contact',
            '240000000000001',
            Integration::MODE_CUSTOM,
            'contact',
            0,
            0,
            $action,
            $pageId,
            $delay
        );
    }
}
