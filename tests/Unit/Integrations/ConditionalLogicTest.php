<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Integrations;

use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Integrations\ConditionalLogic;
use JotformBridge\Integrations\Integration;
use JotformBridge\Tests\TestCase;

final class ConditionalLogicTest extends TestCase
{
    /** @dataProvider comparisons */
    public function testComparisons(string $operator, $value, string $expected, bool $matches): void
    {
        $rule = $this->rule(['operator' => $operator, 'value' => $expected]);
        $this->assertSame(['message' => ['show' => $matches]], ConditionalLogic::state([$rule], ['preferred_contact' => $value]));
    }

    public function comparisons(): array
    {
        return [
            ['equals', 'E-mail', 'E-mail', true],
            ['equals', 'e-mail', 'E-mail', false],
            ['equals', ['Support', 'Sales'], 'Sales', true],
            ['not_equals', ['Support'], 'Sales', true],
            ['empty', [], '', true],
            ['empty', '0', '', false],
            ['not_empty', '', '', false],
            ['not_empty', [''], '', false],
        ];
    }

    public function testUnknownPathsAndUnsupportedFieldsAreRefused(): void
    {
        $schema = (new SchemaBuilder())->build('1', array_values($this->fixture('form-questions')['content']));
        $this->assertSame([], ConditionalLogic::errors([$this->rule()], $schema));
        $this->assertNotEmpty(ConditionalLogic::errors([$this->rule(['target' => 'does_not_exist'])], $schema));
        $this->assertNotEmpty(ConditionalLogic::errors([$this->rule(['source' => 'full_name'])], $schema));
    }

    public function testDuplicateActionsAndHiddenSourcesAreRefused(): void
    {
        $this->assertNotEmpty(ConditionalLogic::errors([$this->rule(), $this->rule()]));
        $this->assertNotEmpty(ConditionalLogic::errors([
            $this->rule(), $this->rule(['source' => 'message', 'target' => 'email']),
        ]));
        $this->assertSame([], ConditionalLogic::errors([
            $this->rule(), $this->rule(['action' => 'require']),
        ]));
    }

    public function testLiteralOptionValuesSurviveSanitizationAndStorage(): void
    {
        foreach (['<2 people', 'Discount %20', '<strong>Literal</strong>', 'R&D'] as $value) {
            $rules = ConditionalLogic::sanitize([$this->rule(['value' => $value])]);
            $this->assertSame($value, $rules[0]['value']);
            $this->assertSame(['message' => ['show' => true]], ConditionalLogic::state($rules, ['preferred_contact' => $value]));
            $integration = Integration::fromInput(['conditions' => $rules]);
            $this->assertSame($value, Integration::fromArray($integration->toArray())->conditions()[0]['value']);
        }
    }

    public function testMalformedInputAndExcessRulesDoNotDisappear(): void
    {
        foreach ([null, 'bad', [['target' => ['forged']]], array_fill(0, 51, $this->rule())] as $input) {
            $this->assertNotEmpty(ConditionalLogic::errors(ConditionalLogic::sanitize($input)));
        }
    }

    public function testOldIntegrationsDefaultToNoRulesAndRulesSurviveStorage(): void
    {
        $this->assertSame([], Integration::fromArray(['slug' => 'contact'])->conditions());
        $integration = Integration::fromInput(['slug' => 'contact', 'conditions' => [$this->rule()]]);
        $this->assertSame([$this->rule()], Integration::fromArray($integration->toArray())->conditions());
    }

    private function rule(array $changes = []): array
    {
        return array_merge(['action' => 'show', 'target' => 'message', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'E-mail'], $changes);
    }
}
