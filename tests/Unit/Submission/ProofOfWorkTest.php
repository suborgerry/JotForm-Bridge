<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Submission\Guards\ProofOfWork;
use JotformBridge\Tests\TestCase;

final class ProofOfWorkTest extends TestCase
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

    public function testAValidProofIsAccepted(): void
    {
        $this->assertTrue($this->check($this->solve('contact')));
    }

    /**
     * The number the browser computed and the number this class recomputes have
     * to be the same number, byte for byte, or nothing else here matters.
     *
     * The expectation is pinned rather than derived: it was produced by the
     * frontend solver, so a change to either side that breaks the agreement
     * fails here instead of in production.
     */
    public function testItAgreesWithTheBrowserSolver(): void
    {
        $this->assertTrue(ProofOfWork::meets('contact', 1756000000, 75207, 16));

        // The first 75,207 attempts must all have been genuine misses.
        $this->assertFalse(ProofOfWork::meets('contact', 1756000000, 75206, 16));
        $this->assertFalse(ProofOfWork::meets('contact', 1756000000, 0, 16));
    }

    public function testAnUnsolvedNonceIsRejected(): void
    {
        $this->assertFalse($this->check(time() . ':1'));
    }

    /**
     * Work done for one form must not pay for another.
     */
    public function testAProofIsBoundToItsIntegration(): void
    {
        $proof = $this->solve('contact');

        $this->assertFalse($this->check($proof, 'newsletter'));
    }

    /**
     * Solve once, send once. Without this a bot would solve one and reuse it
     * for as long as the window lasted.
     */
    public function testAProofCannotBeUsedTwice(): void
    {
        $proof = $this->solve('contact');

        $this->assertTrue($this->check($proof));
        $this->assertFalse($this->check($proof));
    }

    public function testAStaleProofIsRejected(): void
    {
        $stale = time() - ProofOfWork::WINDOW - 60;

        $this->assertFalse($this->check($this->solveAt('contact', $stale)));
    }

    public function testAProofFromTheFutureIsRejected(): void
    {
        $ahead = time() + 3600;

        $this->assertFalse($this->check($this->solveAt('contact', $ahead)));
    }

    /**
     * A clock a couple of minutes fast is a clock, not an attack.
     */
    public function testASlightlyFastClockIsTolerated(): void
    {
        $this->assertTrue($this->check($this->solveAt('contact', time() + 60)));
    }

    /**
     * Unlike the honeypot, this one is not optional: arriving without it means
     * the plugin's own script never ran, which is what a bot posting straight
     * to the endpoint looks like.
     */
    public function testAMissingProofIsRejected(): void
    {
        $this->assertFalse($this->check(''));
        $this->assertFalse((new ProofOfWork())->check(true, 'contact', [], []));
    }

    /**
     * @dataProvider malformedProofs
     */
    public function testAMalformedProofIsRejected(string $proof): void
    {
        $this->assertFalse($this->check($proof));
    }

    /**
     * @return array<string, array{0:string}>
     */
    public function malformedProofs(): array
    {
        return [
            'no separator'   => ['12345'],
            'not numeric'    => ['abc:def'],
            'negative'       => ['-1:-1'],
            'too long'       => [str_repeat('9', 40) . ':1'],
            'extra parts'    => ['1:2:3'],
            'injection-ish'  => ["1:1' OR '1"],
        ];
    }

    public function testItCanBeTurnedOff(): void
    {
        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_pow_required' ? false : $value;
            }
        );

        $this->assertTrue((new ProofOfWork())->check(true, 'contact', [], []));
    }

    public function testAnEarlierRejectionIsNotOverruled(): void
    {
        $this->assertSame(
            'Blocked.',
            (new ProofOfWork())->check('Blocked.', 'contact', [], ['spam' => ['pow' => $this->solve('contact')]])
        );
    }

    /**
     * Every extra bit doubles the work, so the setting has to actually bite.
     */
    public function testHarderSettingsRejectEasierProofs(): void
    {
        $easy = $this->solveWithBits('contact', time(), 8);

        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_pow_bits' ? 20 : $value;
            }
        );

        $this->assertFalse($this->check($easy));
    }

    /**
     * @return bool|string
     */
    private function check(string $proof, string $slug = 'contact')
    {
        return (new ProofOfWork())->check(true, $slug, [], ['spam' => ['pow' => $proof]]);
    }

    private function solve(string $slug): string
    {
        return $this->solveAt($slug, time());
    }

    private function solveAt(string $slug, int $timestamp): string
    {
        return $this->solveWithBits($slug, $timestamp, ProofOfWork::BITS);
    }

    private function solveWithBits(string $slug, int $timestamp, int $bits): string
    {
        $nonce = 0;

        while (!ProofOfWork::meets($slug, $timestamp, $nonce, $bits)) {
            $nonce++;
        }

        return $timestamp . ':' . $nonce;
    }
}
