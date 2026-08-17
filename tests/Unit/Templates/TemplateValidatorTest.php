<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Templates;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Templates\CompatibilityReport;
use JotformBridge\Templates\TemplateValidator;
use JotformBridge\Tests\TestCase;

final class TemplateValidatorTest extends TestCase
{
    private TemplateValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new TemplateValidator();
    }

    public function testATemplateDeclaringEveryFieldIsCompatible(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['email', 'company']);

        $this->assertSame(CompatibilityReport::STATUS_COMPATIBLE, $report->status());
        $this->assertTrue($report->isValid());
        $this->assertSame([], $report->errors());
        $this->assertSame([], $report->warnings());
        $this->assertCount(2, $report->matched());
    }

    public function testAMissingOptionalFieldIsOnlyAWarning(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['email']);

        $this->assertSame(CompatibilityReport::STATUS_WARNINGS, $report->status());
        $this->assertTrue($report->isValid());
        $this->assertCount(1, $report->warnings());
        $this->assertSame(CompatibilityReport::MISSING_OPTIONAL, $report->warnings()[0]['status']);
        $this->assertSame('company', $report->warnings()[0]['path']);
    }

    public function testAMissingRequiredFieldIsInvalid(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['company']);

        $this->assertSame(CompatibilityReport::STATUS_INVALID, $report->status());
        $this->assertFalse($report->isValid());
        $this->assertSame(CompatibilityReport::MISSING_REQUIRED, $report->errors()[0]['status']);
        $this->assertSame('email', $report->errors()[0]['path']);
    }

    public function testAnUnknownTemplateFieldIsInvalid(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['email', 'company', 'nickname']);

        $this->assertSame(CompatibilityReport::STATUS_INVALID, $report->status());
        $this->assertSame(CompatibilityReport::UNKNOWN, $report->errors()[0]['status']);
        $this->assertSame('nickname', $report->errors()[0]['path']);
    }

    public function testCompositeChildrenAreValidatedAsIndividualPaths(): void
    {
        $schema = $this->schema(array_values($this->fixture('form-questions')['content']));

        $report = $this->validator->validate(
            $schema,
            ['full_name.first', 'full_name.last', 'email', 'message', 'preferred_contact']
        );

        $matched = array_column($report->matched(), 'path');

        $this->assertContains('full_name.first', $matched);
        $this->assertContains('full_name.last', $matched);

        // Optional composite children are still reported when absent.
        $missing = array_column($report->warnings(), 'path');

        $this->assertContains('address.city', $missing);
        $this->assertContains('address.postal', $missing);
    }

    public function testTheCompositeParentAloneDoesNotSatisfyItsRequiredChildren(): void
    {
        $schema = $this->schema(array_values($this->fixture('form-questions')['content']));

        $report = $this->validator->validate($schema, ['full_name', 'email', 'message', 'preferred_contact']);

        $this->assertSame(CompatibilityReport::STATUS_INVALID, $report->status());

        $statuses = array_column($report->errors(), 'status');

        $this->assertContains(CompatibilityReport::COMPOSITE_PARENT, $statuses);
        $this->assertContains(CompatibilityReport::MISSING_REQUIRED, $statuses);

        $missingPaths = array_column($report->errors(), 'path');

        $this->assertContains('full_name.first', $missingPaths);
        $this->assertContains('full_name.last', $missingPaths);
    }

    public function testAnUnsupportedOptionalFieldIsAWarning(): void
    {
        $schema = $this->schema(array_values($this->fixture('form-questions')['content']));

        $report = $this->validator->validate(
            $schema,
            ['full_name.first', 'full_name.last', 'email', 'message', 'preferred_contact']
        );

        $warnings = [];

        foreach ($report->warnings() as $row) {
            $warnings[(string) $row['path']] = (string) $row['status'];
        }

        // control_datetime and control_fileupload are not mappable yet.
        $this->assertSame(CompatibilityReport::UNSUPPORTED_OPTIONAL, $warnings['preferred_call']);
        $this->assertSame(CompatibilityReport::UNSUPPORTED_OPTIONAL, $warnings['attachment']);
    }

    public function testAnUnsupportedRequiredFieldBlocksTheTemplate(): void
    {
        $schema = $this->schema(array_values($this->fixture('form-questions-edge')['content']));

        $report = $this->validator->validate($schema, []);

        $unsupported = [];

        foreach ($report->errors() as $row) {
            if ($row['status'] === CompatibilityReport::UNSUPPORTED_REQUIRED) {
                $unsupported[] = (string) $row['path'];
            }
        }

        $this->assertSame(CompatibilityReport::STATUS_INVALID, $report->status());
        $this->assertContains('terms_of', $unsupported);
    }

    public function testDynamicIdentifiersAreReportedInsteadOfGuessed(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['email', 'company'], 2);

        $this->assertSame(CompatibilityReport::STATUS_WARNINGS, $report->status());
        $this->assertSame(2, $report->dynamicCount());
        $this->assertSame(CompatibilityReport::DYNAMIC, $report->warnings()[0]['status']);
    }

    public function testDynamicIdentifiersDoNotSatisfyARequiredField(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['company'], 1);

        $this->assertSame(CompatibilityReport::STATUS_INVALID, $report->status());
    }

    public function testRowsCarryTheDiagnosticsColumns(): void
    {
        $schema = $this->schema($this->simpleQuestions());

        $report = $this->validator->validate($schema, ['email', 'company']);
        $rows   = [];

        foreach ($report->rows() as $row) {
            $rows[(string) $row['path']] = $row;
        }

        $this->assertSame('E-mail', $rows['email']['label']);
        $this->assertSame('4', $rows['email']['qid']);
        $this->assertSame('email', $rows['email']['type']);
        $this->assertTrue($rows['email']['required']);
        $this->assertFalse($rows['company']['required']);
    }

    /**
     * @param array<int, array<string, mixed>> $questions
     */
    private function schema(array $questions): FormSchema
    {
        return (new SchemaBuilder())->build('240000000000001', $questions);
    }

    /**
     * One required and one optional scalar field: the smallest form that can be
     * fully compatible.
     *
     * @return array<int, array<string, mixed>>
     */
    private function simpleQuestions(): array
    {
        return [
            [
                'qid'      => '4',
                'type'     => 'control_email',
                'text'     => 'E-mail',
                'name'     => 'email',
                'order'    => '1',
                'required' => 'Yes',
            ],
            [
                'qid'      => '7',
                'type'     => 'control_textbox',
                'text'     => 'Company',
                'name'     => 'company',
                'order'    => '2',
                'required' => 'No',
            ],
        ];
    }
}
