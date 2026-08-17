<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Forms;

use JotformBridge\Forms\FieldNormalizer;
use JotformBridge\Tests\TestCase;

final class FieldNormalizerTest extends TestCase
{
    private FieldNormalizer $normalizer;

    /** @var array<string, array<string, mixed>> */
    private array $questions;

    /** @var array<string, array<string, mixed>> */
    private array $edgeQuestions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer    = new FieldNormalizer();
        $this->questions     = $this->fixture('form-questions')['content'];
        $this->edgeQuestions = $this->fixture('form-questions-edge')['content'];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(string $qid): array
    {
        $field = $this->normalizer->normalize($this->questions[$qid]);

        $this->assertIsArray($field);

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeEdge(string $qid): array
    {
        $field = $this->normalizer->normalize($this->edgeQuestions[$qid]);

        $this->assertIsArray($field);

        return $field;
    }

    public function testTextboxBecomesAnOptionalTextField(): void
    {
        $field = $this->normalize('7');

        $this->assertSame('company', $field['key']);
        $this->assertSame('7', $field['qid']);
        $this->assertSame('company', $field['name']);
        $this->assertSame('Company', $field['label']);
        $this->assertSame(FieldNormalizer::TYPE_TEXT, $field['type']);
        $this->assertSame('control_textbox', $field['jotform_type']);
        $this->assertFalse($field['required']);
        $this->assertTrue($field['supported']);
        $this->assertSame([], $field['children']);
        $this->assertSame([], $field['options']);
    }

    public function testTextareaKeepsItsOwnType(): void
    {
        $field = $this->normalize('8');

        $this->assertSame('message', $field['key']);
        $this->assertSame(FieldNormalizer::TYPE_TEXTAREA, $field['type']);
        $this->assertTrue($field['required']);
    }

    public function testEmailIsRequiredWhenJotformSaysYes(): void
    {
        $field = $this->normalize('4');

        $this->assertSame('email', $field['key']);
        $this->assertSame(FieldNormalizer::TYPE_EMAIL, $field['type']);
        $this->assertTrue($field['required']);
    }

    public function testPhoneIsScalarAndKeepsItsMaskMetadata(): void
    {
        $field = $this->normalize('5');

        $this->assertSame('phone_number', $field['key']);
        $this->assertSame(FieldNormalizer::TYPE_PHONE, $field['type']);
        $this->assertSame([], $field['children'], 'Phone is documented as a single input.');
        $this->assertFalse($field['meta']['country_code']);
        $this->assertSame('(###) ###-####', $field['meta']['input_mask']);
    }

    public function testNumberKeepsItsBounds(): void
    {
        $field = $this->normalize('12');

        $this->assertSame('team_size', $field['key']);
        $this->assertSame(FieldNormalizer::TYPE_NUMBER, $field['type']);
        $this->assertSame('1', $field['meta']['min']);
        $this->assertSame('500', $field['meta']['max']);
    }

    public function testFullNameExposesOnlyTheEnabledChildren(): void
    {
        $field = $this->normalize('3');

        $this->assertSame('full_name', $field['key']);
        $this->assertSame(FieldNormalizer::TYPE_NAME, $field['type']);
        $this->assertTrue($field['required']);
        $this->assertSame(['first', 'last'], array_keys($field['children']));
        $this->assertSame('full_name.first', $field['children']['first']['key']);
        $this->assertSame('full_name.last', $field['children']['last']['key']);
        $this->assertSame('First Name', $field['children']['first']['label']);
        $this->assertTrue($field['children']['first']['required']);
        $this->assertSame('first', $field['children']['first']['jotform']);
    }

    public function testFullNameAddsPrefixMiddleAndSuffixWhenEnabled(): void
    {
        $field = $this->normalizeEdge('3');

        $this->assertSame(
            ['prefix', 'first', 'middle', 'last', 'suffix'],
            array_keys($field['children'])
        );

        // A required Full Name only enforces first and last.
        $this->assertTrue($field['children']['first']['required']);
        $this->assertTrue($field['children']['last']['required']);
        $this->assertFalse($field['children']['prefix']['required']);
        $this->assertFalse($field['children']['middle']['required']);
        $this->assertFalse($field['children']['suffix']['required']);
    }

    public function testAddressChildrenFollowTheSubfieldsProperty(): void
    {
        $field = $this->normalize('6');

        $this->assertSame(FieldNormalizer::TYPE_ADDRESS, $field['type']);
        $this->assertSame(
            ['addr_line1', 'addr_line2', 'city', 'state', 'postal'],
            array_keys($field['children'])
        );
        $this->assertSame('address.addr_line1', $field['children']['addr_line1']['key']);
        $this->assertSame('Postal / Zip Code', $field['children']['postal']['label']);
        $this->assertFalse($field['children']['city']['required']);
    }

    public function testRequiredAddressLeavesTheSecondStreetLineOptional(): void
    {
        $field = $this->normalizeEdge('4');

        $this->assertSame(
            ['addr_line1', 'city', 'state', 'country', 'postal'],
            array_keys($field['children'])
        );
        $this->assertTrue($field['children']['addr_line1']['required']);
        $this->assertTrue($field['children']['country']['required']);
        $this->assertArrayNotHasKey('addr_line2', $field['children']);
    }

    public function testDropdownOptionsAreSplitOnThePipeCharacter(): void
    {
        $field = $this->normalize('9');

        $this->assertSame(FieldNormalizer::TYPE_SELECT, $field['type']);
        $this->assertFalse($field['multiple']);
        $this->assertSame(
            ['Search engine', 'A colleague', 'Conference', 'Other'],
            array_column($field['options'], 'value')
        );
        $this->assertSame('Search engine', $field['options'][0]['label']);
    }

    public function testRadioKeepsItsOptionsAndRequiredFlag(): void
    {
        $field = $this->normalize('10');

        $this->assertSame(FieldNormalizer::TYPE_RADIO, $field['type']);
        $this->assertTrue($field['required']);
        $this->assertFalse($field['multiple']);
        $this->assertFalse($field['allow_other']);
        $this->assertSame(['E-mail', 'Phone'], array_column($field['options'], 'value'));
    }

    public function testCheckboxIsMultiValueAndCanAllowOther(): void
    {
        $field = $this->normalize('11');

        $this->assertSame(FieldNormalizer::TYPE_CHECKBOX, $field['type']);
        $this->assertTrue($field['multiple']);
        $this->assertTrue($field['allow_other']);
        $this->assertSame(
            ['Pricing', 'Integrations', 'Support', 'Partnership'],
            array_column($field['options'], 'value')
        );
    }

    public function testEmptyOptionStringYieldsNoOptions(): void
    {
        $field = $this->normalizeEdge('8');

        $this->assertSame([], $field['options']);
        $this->assertTrue($field['supported']);
    }

    public function testUnsupportedTypeIsFlaggedInsteadOfDowngradedToText(): void
    {
        $field = $this->normalize('13');

        $this->assertFalse($field['supported']);
        $this->assertSame(FieldNormalizer::TYPE_UNSUPPORTED, $field['type']);
        $this->assertSame('control_datetime', $field['jotform_type']);
        $this->assertSame('13', $field['qid']);
        $this->assertSame('Preferred call date', $field['label']);
        $this->assertNotSame('', $field['reason']);
    }

    public function testUnsupportedFileUploadKeepsItsIdentity(): void
    {
        $field = $this->normalize('14');

        $this->assertFalse($field['supported']);
        $this->assertSame('control_fileupload', $field['jotform_type']);
    }

    public function testLayoutElementsAreNotFields(): void
    {
        $this->assertNull($this->normalizer->normalize($this->questions['1']), 'control_head');
        $this->assertNull($this->normalizer->normalize($this->questions['2']), 'control_button');
    }

    public function testQuestionWithoutTypeIsIgnored(): void
    {
        $this->assertNull($this->normalizer->normalize(['qid' => '99', 'text' => 'Broken']));
    }
}
