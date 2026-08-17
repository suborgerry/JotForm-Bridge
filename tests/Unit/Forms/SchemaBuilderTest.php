<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Forms;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Tests\TestCase;

final class SchemaBuilderTest extends TestCase
{
    private SchemaBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new SchemaBuilder();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function questions(string $fixture = 'form-questions'): array
    {
        return array_values($this->fixture($fixture)['content']);
    }

    public function testSchemaIsKeyedBySemanticKey(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());

        $this->assertSame('240000000000001', $schema->formId());
        $this->assertSame(
            [
                'full_name',
                'email',
                'phone_number',
                'address',
                'company',
                'message',
                'how_did_you',
                'preferred_contact',
                'topics_of',
                'team_size',
                'preferred_call',
                'attachment',
            ],
            array_keys($schema->fields())
        );
    }

    public function testLookupsWorkOnSemanticKeysWhileTheQidStaysInternal(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());

        $this->assertTrue($schema->has('email'));
        $this->assertFalse($schema->has('q4'));
        $this->assertSame('4', $schema->qidFor('email'));
        $this->assertNull($schema->qidFor('nope'));
        $this->assertNull($schema->field('nope'));
    }

    public function testRequiredAndSupportedSubsets(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());

        $this->assertSame(
            ['full_name', 'email', 'message', 'preferred_contact'],
            array_keys($schema->requiredFields())
        );
        $this->assertSame(
            ['preferred_call', 'attachment'],
            array_keys($schema->unsupportedFields())
        );
        $this->assertArrayNotHasKey('attachment', $schema->supportedFields());
    }

    public function testSemanticPathsExpandCompositeChildrenOnly(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());
        $paths  = $schema->semanticPaths();

        $this->assertContains('full_name.first', $paths);
        $this->assertContains('full_name.last', $paths);
        $this->assertNotContains('full_name', $paths);
        $this->assertContains('address.addr_line1', $paths);
        $this->assertContains('email', $paths);
        $this->assertNotContains('attachment', $paths, 'Unsupported fields are not renderable paths.');
    }

    public function testRequiredPathsFollowChildRequiredness(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());

        $this->assertSame(
            ['full_name.first', 'full_name.last', 'email', 'message', 'preferred_contact'],
            $schema->requiredPaths()
        );
    }

    public function testUnsupportedFieldProducesADiagnostic(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());

        $codes = array_column($schema->diagnostics(), 'code');

        $this->assertContains(FormSchema::CODE_UNSUPPORTED, $codes);

        $unsupported = array_values(
            array_filter(
                $schema->diagnostics(),
                static fn(array $d): bool => $d['qid'] === '13'
            )
        );

        $this->assertCount(1, $unsupported);
        $this->assertSame(FormSchema::DIAGNOSTIC_WARNING, $unsupported[0]['level']);
        $this->assertStringContainsString('control_datetime', $unsupported[0]['message']);
    }

    public function testTheFixtureFormHasNoErrors(): void
    {
        $schema = $this->builder->build('240000000000001', $this->questions());

        $this->assertSame([], $schema->errors());
        $this->assertTrue($schema->isUsable());
        $this->assertFalse($schema->hasCollisions());
    }

    public function testCollidingKeysAreReportedInsteadOfGuessed(): void
    {
        $schema = $this->builder->build('240000000000002', $this->questions('form-questions-edge'));

        $this->assertTrue($schema->hasCollisions());
        $this->assertFalse($schema->isUsable());

        // The first field keeps the key; the second is parked on its qid.
        $this->assertSame('5', $schema->qidFor('work_email'));
        $this->assertTrue($schema->field('work_email')['collision']);
        $this->assertSame('6', $schema->qidFor('field_6'));
        $this->assertTrue($schema->field('field_6')['collision']);

        $collision = $schema->collisions()[0];

        $this->assertSame(FormSchema::DIAGNOSTIC_ERROR, $collision['level']);
        $this->assertStringContainsString('work_email', $collision['message']);
    }

    public function testMissingMachineNameIsAWarningWithAQidDerivedKey(): void
    {
        $schema = $this->builder->build('240000000000002', $this->questions('form-questions-edge'));

        $this->assertTrue($schema->has('field_7'));

        $warnings = array_values(
            array_filter(
                $schema->diagnostics(),
                static fn(array $d): bool => $d['code'] === FormSchema::CODE_NO_NAME
            )
        );

        $this->assertCount(1, $warnings);
        $this->assertSame('7', $warnings[0]['qid']);
    }

    public function testChoiceFieldWithoutOptionsIsReported(): void
    {
        $schema = $this->builder->build('240000000000002', $this->questions('form-questions-edge'));

        $codes = array_column($schema->diagnostics(), 'code');

        $this->assertContains(FormSchema::CODE_NO_OPTIONS, $codes);
    }

    public function testRequiredUnsupportedFieldIsAnError(): void
    {
        $schema = $this->builder->build('240000000000002', $this->questions('form-questions-edge'));

        $errors = array_values(
            array_filter(
                $schema->errors(),
                static fn(array $d): bool => $d['code'] === FormSchema::CODE_UNSUPPORTED
            )
        );

        $this->assertCount(1, $errors);
        $this->assertSame('9', $errors[0]['qid'], 'The required widget field must block the schema.');
    }

    public function testFingerprintIsStableAcrossRebuilds(): void
    {
        $first  = $this->builder->build('240000000000001', $this->questions());
        $second = $this->builder->build('240000000000001', $this->questions());

        $this->assertNotSame('', $first->fingerprint());
        $this->assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function testFingerprintIgnoresLabelAndOrderChanges(): void
    {
        $questions = $this->questions();
        $baseline  = $this->builder->build('240000000000001', $questions)->fingerprint();

        foreach ($questions as $index => $question) {
            if (($question['qid'] ?? '') === '8') {
                $questions[$index]['text']  = 'Tell us more';
                $questions[$index]['order'] = '99';
            }
        }

        $this->assertSame(
            $baseline,
            $this->builder->build('240000000000001', $questions)->fingerprint()
        );
    }

    /**
     * @dataProvider significantChanges
     *
     * @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $mutate
     */
    public function testFingerprintChangesOnSignificantEdits(callable $mutate): void
    {
        $questions = $this->questions();
        $baseline  = $this->builder->build('240000000000001', $questions)->fingerprint();

        $this->assertNotSame(
            $baseline,
            $this->builder->build('240000000000001', $mutate($questions))->fingerprint()
        );
    }

    /**
     * @return array<string, array{0:callable}>
     */
    public function significantChanges(): array
    {
        return [
            'field removed'   => [
                static function (array $questions): array {
                    return array_values(
                        array_filter($questions, static fn(array $q): bool => $q['qid'] !== '7')
                    );
                },
            ],
            'field added'     => [
                static function (array $questions): array {
                    $questions[] = [
                        'qid'      => '20',
                        'type'     => 'control_textbox',
                        'text'     => 'Job title',
                        'order'    => '20',
                        'name'     => 'jobTitle',
                        'required' => 'No',
                    ];

                    return $questions;
                },
            ],
            'qid changed'     => [
                static fn(array $questions): array => self::patch($questions, '7', ['qid' => '77']),
            ],
            'required flipped' => [
                static fn(array $questions): array => self::patch($questions, '7', ['required' => 'Yes']),
            ],
            'type changed'    => [
                static fn(array $questions): array => self::patch($questions, '7', ['type' => 'control_textarea']),
            ],
            'options changed' => [
                static fn(array $questions): array => self::patch(
                    $questions,
                    '10',
                    ['options' => 'E-mail|Phone|Carrier pigeon']
                ),
            ],
            'children changed' => [
                static fn(array $questions): array => self::patch($questions, '3', ['middle' => 'Yes']),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     * @param array<string, mixed>             $patch
     *
     * @return array<int, array<string, mixed>>
     */
    private static function patch(array $questions, string $qid, array $patch): array
    {
        foreach ($questions as $index => $question) {
            if (($question['qid'] ?? '') === $qid) {
                $questions[$index] = array_merge($question, $patch);
            }
        }

        return $questions;
    }
}
