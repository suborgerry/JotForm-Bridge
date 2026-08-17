<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Forms;

use JotformBridge\Forms\SemanticKey;
use JotformBridge\Tests\TestCase;

final class SemanticKeyTest extends TestCase
{
    /**
     * @dataProvider names
     */
    public function testNamesAreNormalizedDeterministically(string $name, string $expected): void
    {
        $this->assertSame($expected, SemanticKey::fromName($name, '7'));
        $this->assertSame(
            SemanticKey::fromName($name, '7'),
            SemanticKey::fromName($name, '7'),
            'Key generation must be deterministic.'
        );
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public function names(): array
    {
        return [
            'plain'            => ['email', 'email'],
            'camel case'       => ['fullName', 'full_name'],
            'already snake'    => ['full_name', 'full_name'],
            'spaces'           => ['Your Message', 'your_message'],
            'punctuation'      => ['e-mail (work)', 'e_mail_work'],
            'trailing junk'    => ['__phone__', 'phone'],
            'digits inside'    => ['address2', 'address2'],
            'leading digit'    => ['1stChoice', 'f_1st_choice'],
            'non ascii label'  => ['Сообщение', 'field_7'],
        ];
    }

    public function testEmptyNameFallsBackToTheQid(): void
    {
        $key = SemanticKey::fromName('', '42');

        $this->assertSame('field_42', $key);
        $this->assertTrue(SemanticKey::isFallback($key));
    }

    public function testARegularKeyIsNotAFallback(): void
    {
        $this->assertFalse(SemanticKey::isFallback('email'));
    }

    public function testChildPathsUseDotNotation(): void
    {
        $this->assertSame('name.first', SemanticKey::child('name', 'first'));
        $this->assertSame('address.addr_line1', SemanticKey::child('address', 'addr_line1'));
    }
}
