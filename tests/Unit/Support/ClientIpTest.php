<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Support;

use Brain\Monkey\Functions;
use JotformBridge\Support\ClientIp;
use JotformBridge\Tests\TestCase;

/**
 * The constant that names a trusted header cannot be undefined once it is set,
 * so the tests that need it defined run in separate processes.
 */
final class ClientIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Functions\when('wp_unslash')->alias(static fn($value) => $value);

        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);

        parent::tearDown();
    }

    public function testRemoteAddrIsTheDefaultSource(): void
    {
        $this->assertSame('203.0.113.7', ClientIp::resolve());
    }

    /**
     * The whole point of the opt-in: an unconfigured site must ignore a header
     * a visitor can write, however plausible it looks.
     */
    public function testAForwardedHeaderIsIgnoredWithoutTheConstant(): void
    {
        $_SERVER['HTTP_X_FORWARDED_FOR']  = '198.51.100.9';
        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';

        $this->assertSame('203.0.113.7', ClientIp::resolve());
    }

    public function testAMalformedRemoteAddrYieldsNothing(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';

        $this->assertSame('', ClientIp::resolve());
    }

    public function testAMissingRemoteAddrYieldsNothing(): void
    {
        unset($_SERVER['REMOTE_ADDR']);

        $this->assertSame('', ClientIp::resolve());
    }

    public function testIpv6IsAccepted(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';

        $this->assertSame('2001:db8::1', ClientIp::resolve());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAConfiguredHeaderIsUsedWhenItHoldsAnAddress(): void
    {
        define(ClientIp::HEADER_CONSTANT, 'CF-Connecting-IP');

        $_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.9';

        $this->assertSame('HTTP_CF_CONNECTING_IP', ClientIp::trustedHeader());
        $this->assertSame('198.51.100.9', ClientIp::resolve());
    }

    /**
     * A chain ends with the proxies; the visitor is the left-most entry.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheLeftMostAddressOfAChainIsUsed(): void
    {
        define(ClientIp::HEADER_CONSTANT, 'X-Forwarded-For');

        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9, 70.41.3.18, 150.172.238.178';

        $this->assertSame('198.51.100.9', ClientIp::resolve());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAGarbageHeaderFallsBackToRemoteAddr(): void
    {
        define(ClientIp::HEADER_CONSTANT, 'X-Forwarded-For');

        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'nonsense, <script>';

        $this->assertSame('203.0.113.7', ClientIp::resolve());
    }

    /**
     * The CGI spelling has to work too — it is what people copy out of notes.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testTheCgiSpellingOfTheHeaderIsAccepted(): void
    {
        define(ClientIp::HEADER_CONSTANT, 'HTTP_CF_CONNECTING_IP');

        $this->assertSame('HTTP_CF_CONNECTING_IP', ClientIp::trustedHeader());
    }
}
