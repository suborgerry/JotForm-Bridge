<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use JotformBridge\Submission\Guards\Honeypot;
use JotformBridge\Tests\TestCase;

/**
 * The honeypot verdict, tested at the filter callback rather than through the
 * pipeline: the point of the provider is that it is a plain callback.
 */
final class HoneypotTest extends TestCase
{
    public function testAnEmptyDecoyIsLeftAlone(): void
    {
        $this->assertTrue($this->check(['hp' => '']));
    }

    public function testWhitespaceOnlyIsStillEmpty(): void
    {
        $this->assertTrue($this->check(['hp' => "  \n\t "]));
    }

    public function testAFilledDecoyIsRejected(): void
    {
        $this->assertFalse($this->check(['hp' => 'https://example.com']));
    }

    /**
     * A template is free not to carry a honeypot, so a submission without one is
     * not evidence of anything.
     */
    public function testAMissingDecoyIsNotTreatedAsAnAttack(): void
    {
        $this->assertTrue($this->check([]));
        $this->assertTrue($this->check(null));
    }

    public function testANonScalarValueIsRejected(): void
    {
        $this->assertFalse($this->check(['hp' => ['a', 'b']]));
    }

    /**
     * A rejection by another provider must survive this one.
     */
    public function testAnEarlierRejectionIsNotOverruled(): void
    {
        $guard = new Honeypot();

        $this->assertSame(
            'Please solve the challenge.',
            $guard->check('Please solve the challenge.', 'contact', [], ['spam' => ['hp' => '']])
        );

        $this->assertFalse($guard->check(false, 'contact', [], ['spam' => ['hp' => '']]));
    }

    public function testTheMarkupCarriesTheSpamAttributeAndHidesItself(): void
    {
        $markup = Honeypot::markup('jfb-contact');

        $this->assertStringContainsString('data-jotform-spam="hp"', $markup);
        $this->assertStringContainsString('name="jfb_website"', $markup);
        $this->assertStringContainsString('tabindex="-1"', $markup);
        $this->assertStringContainsString('autocomplete="off"', $markup);
        $this->assertStringContainsString('aria-hidden="true"', $markup);
        $this->assertStringContainsString('left:-9999px', $markup);

        // It must never look like a semantic field, or the validator would see
        // it and fail the submission.
        $this->assertStringNotContainsString('data-jotform-field', $markup);
    }

    public function testTwoFormsOnOnePageGetDistinctElementIds(): void
    {
        preg_match('/id="([^"]+)"/', Honeypot::markup('jfb-contact'), $first);
        preg_match('/id="([^"]+)"/', Honeypot::markup('jfb-contact'), $second);

        $this->assertNotSame($first[1], $second[1]);
    }

    /**
     * @param array<string, mixed>|null $spam
     *
     * @return bool|string
     */
    private function check($spam)
    {
        return (new Honeypot())->check(true, 'contact', [], $spam === null ? [] : ['spam' => $spam]);
    }
}
