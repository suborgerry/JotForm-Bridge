<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Forms;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Tests\TestCase;

/**
 * The schema comes back out of a transient, so its shape is a trust boundary:
 * anything a later layer reads unconditionally has to be verified here, or a
 * corrupted cache entry turns into a fatal error in the middle of a page.
 */
final class FormSchemaTest extends TestCase
{
    public function testAWellFormedFieldSurvivesTheRoundTrip(): void
    {
        $schema = FormSchema::fromArray($this->stored(['email' => $this->field('email')]));

        $this->assertTrue($schema->has('email'));
        $this->assertSame(['email'], $schema->semanticPaths());
        $this->assertSame('4', $schema->qidFor('email'));
        $this->assertSame('fp', $schema->fingerprint());
    }

    /**
     * @dataProvider brokenFields
     *
     * @param mixed $field
     */
    public function testAMalformedFieldIsDroppedInsteadOfFatalling($field): void
    {
        $schema = FormSchema::fromArray($this->stored(['email' => $field]));

        $this->assertSame([], $schema->fields());
        $this->assertSame([], $schema->semanticPaths());
        $this->assertSame([], $schema->requiredPaths());
        $this->assertSame([], $schema->supportedFields());
        $this->assertSame([], $schema->unsupportedFields());
        $this->assertSame([], $schema->requiredFields());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public function brokenFields(): array
    {
        $missingType = $this->field('email');
        unset($missingType['type']);

        $missingSupported = $this->field('email');
        unset($missingSupported['supported']);

        $scalarChildren             = $this->field('email');
        $scalarChildren['children'] = 'nope';

        $scalarOptions            = $this->field('email');
        $scalarOptions['options'] = 'nope';

        return [
            'a string instead of a field' => ['broken'],
            'an integer'                  => [7],
            'null'                        => [null],
            'an empty array'              => [[]],
            'no type'                     => [$missingType],
            'no supported flag'           => [$missingSupported],
            'children is not a list'      => [$scalarChildren],
            'options is not a list'       => [$scalarOptions],
        ];
    }

    public function testAMalformedEnvelopeYieldsAnEmptySchema(): void
    {
        $schema = FormSchema::fromArray(
            [
                'form_id'     => ['nested'],
                'fields'      => 'not-an-array',
                'diagnostics' => 'not-an-array',
                'fingerprint' => ['nested'],
            ]
        );

        $this->assertSame('', $schema->formId());
        $this->assertSame('', $schema->fingerprint());
        $this->assertSame([], $schema->fields());
        $this->assertSame([], $schema->diagnostics());
        $this->assertTrue($schema->isUsable());
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function stored(array $fields): array
    {
        return [
            'form_id'     => '240000000000001',
            'fields'      => $fields,
            'diagnostics' => [],
            'fingerprint' => 'fp',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $key): array
    {
        return [
            'key'         => $key,
            'qid'         => '4',
            'type'        => 'email',
            'label'       => 'Email',
            'required'    => true,
            'supported'   => true,
            'multiple'    => false,
            'allow_other' => false,
            'children'    => [],
            'options'     => [],
            'meta'        => [],
        ];
    }
}
