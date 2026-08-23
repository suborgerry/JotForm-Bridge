<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Submission\Guards\Turnstile;
use JotformBridge\Tests\TestCase;

/**
 * The keys live in constants, which cannot be undefined once set, so every test
 * that needs them configured runs in its own process.
 */
final class TurnstileTest extends TestCase
{
    public function testItStaysOutOfTheWayWithoutKeys(): void
    {
        $this->assertFalse(Turnstile::isConfigured());
        $this->assertSame('', Turnstile::markup());

        // No keys, nothing to verify against, no verdict.
        $this->assertTrue($this->check(['turnstile' => '']));
    }

    /**
     * A check that always fails open is worse than none: it looks like
     * protection and is not.
     */
    public function testItDoesNotRegisterWithoutKeys(): void
    {
        Functions\expect('add_filter')->never();

        (new Turnstile())->register();
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheWidgetCarriesTheSpamAttributeAndSiteKey(): void
    {
        $this->configure();

        $markup = Turnstile::markup();

        $this->assertStringContainsString('data-jotform-spam="turnstile"', $markup);
        $this->assertStringContainsString('data-sitekey="site-key"', $markup);
        $this->assertStringContainsString('cf-turnstile', $markup);

        // It must never look like a semantic field.
        $this->assertStringNotContainsString('data-jotform-field', $markup);

        // The secret must not reach the browser.
        $this->assertStringNotContainsString('secret-key', $markup);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAGoodTokenIsAccepted(): void
    {
        $this->configure();
        $this->mockVerify(['success' => true]);

        $this->assertTrue($this->check(['turnstile' => 'good-token']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testABadTokenIsRejected(): void
    {
        $this->configure();
        $this->mockVerify(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->assertIsString($this->check(['turnstile' => 'bad-token']));
    }

    /**
     * Unlike the honeypot, a challenge that can be skipped by leaving the field
     * out is not a challenge.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAMissingTokenIsRejected(): void
    {
        $this->configure();

        Functions\when('wp_remote_post')->alias(
            static function (): void {
                throw new \RuntimeException('A missing token must not be verified.');
            }
        );

        $this->assertIsString($this->check([]));
    }

    /**
     * The escape hatch for a site still serving markup from a cache that
     * predates the challenge.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAMissingTokenCanBeMadeOptional(): void
    {
        $this->configure();

        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_turnstile_required' ? false : $value;
            }
        );

        $this->assertTrue($this->check([]));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnUnreachableVerifierFailsOpenByDefault(): void
    {
        $this->configure();

        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_request_failed', 'down'));

        $this->assertTrue($this->check(['turnstile' => 'token']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFailingClosedIsOneFilterAway(): void
    {
        $this->configure();

        Functions\when('wp_remote_post')->justReturn(new \WP_Error('http_request_failed', 'down'));
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_turnstile_fail_open' ? false : $value;
            }
        );

        $this->assertIsString($this->check(['turnstile' => 'token']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnUnusableAnswerIsTreatedAsUnavailable(): void
    {
        $this->configure();
        $this->mockVerify(['nothing' => 'useful']);

        $this->assertTrue($this->check(['turnstile' => 'token']));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheSecretIsSentToCloudflareAndNowhereElse(): void
    {
        $this->configure();

        $captured = [];

        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use (&$captured) {
                $captured = ['url' => $url, 'args' => $args];

                return $this->httpResponse(200, ['success' => true]);
            }
        );

        $this->check(['turnstile' => 'token']);

        $this->assertStringStartsWith('https://challenges.cloudflare.com/', $captured['url']);
        $this->assertSame('secret-key', $captured['args']['body']['secret']);
        $this->assertSame('token', $captured['args']['body']['response']);

        // The visitor's address is not ours to hand over.
        $this->assertArrayNotHasKey('remoteip', $captured['args']['body']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnEarlierRejectionIsNotOverruled(): void
    {
        $this->configure();

        Functions\when('wp_remote_post')->alias(
            static function (): void {
                throw new \RuntimeException('A settled verdict must not be re-checked.');
            }
        );

        $this->assertSame('Blocked.', (new Turnstile())->check('Blocked.', 'contact', [], []));
    }

    private function configure(): void
    {
        define(Turnstile::SITE_KEY_CONSTANT, 'site-key');
        define(Turnstile::SECRET_CONSTANT, 'secret-key');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mockVerify(array $body): void
    {
        $response = $this->httpResponse(200, $body);

        Functions\when('wp_remote_post')->justReturn($response);
    }

    /**
     * @param array<string, mixed> $spam
     *
     * @return bool|string
     */
    private function check(array $spam)
    {
        return (new Turnstile())->check(true, 'contact', [], ['spam' => $spam]);
    }
}
