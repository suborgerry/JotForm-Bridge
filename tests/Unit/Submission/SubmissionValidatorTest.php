<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Submission\SubmissionValidator;
use JotformBridge\Submission\ValidationResult;
use JotformBridge\Tests\TestCase;

/**
 * The server-side gate. Every expectation here is about what the visitor may
 * send, decided exclusively from the schema.
 */
final class SubmissionValidatorTest extends TestCase
{
    private FormSchema $schema;

    private SubmissionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\Functions\when('get_option')->justReturn([]);

        $questions = array_values($this->fixture('form-questions')['content']);

        $this->schema    = (new SchemaBuilder())->build('240000000000001', $questions);
        $this->validator = new SubmissionValidator();
    }

    public function testACompleteSubmissionPasses(): void
    {
        $result = $this->validator->validate($this->schema, $this->valid());

        $this->assertSame([], $result->errors());
        $this->assertTrue($result->isValid());
        $this->assertSame('jane@example.com', $result->values()['email']);
    }

    public function testAMissingRequiredFieldIsReported(): void
    {
        $input = $this->valid();
        unset($input['message']);

        $result = $this->validator->validate($this->schema, $input);

        $this->assertFalse($result->isValid());
        $this->assertArrayHasKey('message', $result->errors());
        $this->assertSame([], $result->values());
    }

    public function testAnEmptyStringDoesNotSatisfyARequiredField(): void
    {
        $input            = $this->valid();
        $input['message'] = '   ';

        $this->assertArrayHasKey('message', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testARequiredCompositeChildIsEnforced(): void
    {
        $input = $this->valid();
        unset($input['full_name.last']);

        $errors = $this->validator->validate($this->schema, $input)->errors();

        $this->assertArrayHasKey('full_name.last', $errors);
        $this->assertArrayNotHasKey('full_name.first', $errors);
    }

    public function testAnOptionalCompositeChildStaysOptional(): void
    {
        // The address itself is optional, so none of its children are required.
        $input = $this->valid();
        unset($input['address.city']);

        $this->assertTrue($this->validator->validate($this->schema, $input)->isValid());
    }

    public function testAnInvalidEmailIsRejected(): void
    {
        $input          = $this->valid();
        $input['email'] = 'not-an-email';

        $this->assertSame(
            ['email'],
            array_keys($this->validator->validate($this->schema, $input)->errors())
        );
    }

    public function testEmailDomainAllowlistUsesExactCaseInsensitiveMatch(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([
            'popular_email_domains_only' => true,
            'allowed_email_domains' => "GMAIL.COM\ngmail.com",
        ]);
        $input = $this->valid();
        $input['email'] = 'jane@GMAIL.COM';
        $this->assertTrue($this->validator->validate($this->schema, $input)->isValid());
        foreach (['jane@example.com', 'jane@sub.gmail.com', 'jane@gmail.com.evil.com'] as $email) {
            $input['email'] = $email;
            $this->assertArrayHasKey('email', $this->validator->validate($this->schema, $input)->errors());
        }
    }

    public function testEnabledEmptyEmailDomainListRefusesEmail(): void
    {
        \Brain\Monkey\Functions\when('get_option')->justReturn([
            'popular_email_domains_only' => true,
            'allowed_email_domains' => '',
        ]);
        $this->assertArrayHasKey('email', $this->validator->validate($this->schema, $this->valid())->errors());
    }

    public function testAnUnknownSemanticFieldIsRejectedRatherThanIgnored(): void
    {
        $input                  = $this->valid();
        $input['secret_switch'] = 'on';

        $this->assertArrayHasKey(
            'secret_switch',
            $this->validator->validate($this->schema, $input)->errors()
        );
    }

    public function testACompositeParentCannotBeAddressedDirectly(): void
    {
        $input              = $this->valid();
        $input['full_name'] = 'Jane Doe';

        $this->assertArrayHasKey('full_name', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testANonNumericNumberIsRejected(): void
    {
        $input              = $this->valid();
        $input['team_size'] = 'twelve';

        $this->assertArrayHasKey('team_size', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testANumberOutsideTheSchemaRangeIsRejected(): void
    {
        $input              = $this->valid();
        $input['team_size'] = '900';

        $this->assertArrayHasKey('team_size', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testANumberInsideTheSchemaRangeIsAccepted(): void
    {
        $input              = $this->valid();
        $input['team_size'] = '12';

        $result = $this->validator->validate($this->schema, $input);

        $this->assertTrue($result->isValid());
        $this->assertSame('12', $result->values()['team_size']);
    }

    public function testAValueOutsideTheDropdownOptionsIsRejected(): void
    {
        $input                 = $this->valid();
        $input['how_did_you']  = 'Carrier pigeon';

        $this->assertArrayHasKey('how_did_you', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testAValueOutsideTheRadioOptionsIsRejected(): void
    {
        $input                       = $this->valid();
        $input['preferred_contact']  = 'Telepathy';

        $this->assertArrayHasKey(
            'preferred_contact',
            $this->validator->validate($this->schema, $input)->errors()
        );
    }

    public function testValidCheckboxValuesAreKeptAsAList(): void
    {
        $input               = $this->valid();
        $input['topics_of']  = ['Pricing', 'Support'];

        $result = $this->validator->validate($this->schema, $input);

        $this->assertTrue($result->isValid());
        $this->assertSame(['Pricing', 'Support'], $result->values()['topics_of']);
    }

    public function testACheckboxFieldThatAllowsOtherAcceptsAFreeTextValue(): void
    {
        // The fixture sets allowOther: Yes on this field.
        $input              = $this->valid();
        $input['topics_of'] = ['Pricing', 'Something else'];

        $this->assertTrue($this->validator->validate($this->schema, $input)->isValid());
    }

    public function testACheckboxFieldWithoutOtherRejectsAnUnknownValue(): void
    {
        $schema = (new SchemaBuilder())->build(
            '240000000000002',
            [
                [
                    'qid'        => '11',
                    'type'       => 'control_checkbox',
                    'text'       => 'Topics',
                    'name'       => 'topics',
                    'order'      => '1',
                    'required'   => 'No',
                    'options'    => 'Pricing|Support',
                    'allowOther' => 'No',
                ],
            ]
        );

        $result = $this->validator->validate($schema, ['topics' => ['Pricing', 'Nonsense']]);

        $this->assertArrayHasKey('topics', $result->errors());
    }

    public function testAListSentForAScalarFieldIsRejected(): void
    {
        $input          = $this->valid();
        $input['email'] = ['a@example.com', 'b@example.com'];

        $this->assertArrayHasKey('email', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testAnUnsupportedSchemaFieldCannotBeSubmitted(): void
    {
        // control_datetime and control_fileupload are normalized as unsupported.
        $input                    = $this->valid();
        $input['preferred_call']  = '2026-01-01';

        $this->assertArrayHasKey(
            'preferred_call',
            $this->validator->validate($this->schema, $input)->errors()
        );
    }

    public function testAnOversizedValueIsRejected(): void
    {
        $input            = $this->valid();
        $input['company'] = str_repeat('a', SubmissionValidator::MAX_TEXT_LENGTH + 1);

        $this->assertArrayHasKey('company', $this->validator->validate($this->schema, $input)->errors());
    }

    /**
     * The limit is stated to the visitor in characters, so it has to be counted
     * in characters. Measured in bytes, a Cyrillic answer was refused at half
     * the length the message named — and the test above, written in ASCII,
     * could never have seen it.
     */
    public function testALimitStatedInCharactersIsCountedInCharacters(): void
    {
        $input            = $this->valid();
        $input['company'] = str_repeat('я', SubmissionValidator::MAX_TEXT_LENGTH);

        $result = $this->validator->validate($this->schema, $input);

        $this->assertSame([], $result->errors());
        $this->assertSame($input['company'], $result->values()['company']);
    }

    public function testTheCharacterLimitStillBitesOneCharacterLater(): void
    {
        $input            = $this->valid();
        $input['company'] = str_repeat('я', SubmissionValidator::MAX_TEXT_LENGTH + 1);

        $this->assertArrayHasKey('company', $this->validator->validate($this->schema, $input)->errors());
    }

    public function testAnOversizedRequestIsRejectedAsAWhole(): void
    {
        $input            = $this->valid();
        $input['message'] = str_repeat('a', SubmissionValidator::MAX_PAYLOAD_BYTES + 1);

        $errors = $this->validator->validate($this->schema, $input)->errors();

        $this->assertSame([ValidationResult::FORM_KEY], array_keys($errors));
    }

    public function testARequestWithTooManyFieldsIsRejected(): void
    {
        $input = [];

        for ($i = 0; $i <= SubmissionValidator::MAX_FIELDS; $i++) {
            $input['field_' . $i] = 'x';
        }

        $this->assertSame(
            [ValidationResult::FORM_KEY],
            array_keys($this->validator->validate($this->schema, $input)->errors())
        );
    }

    public function testValuesAreSanitized(): void
    {
        $input            = $this->valid();
        $input['company'] = '<script>alert(1)</script>ACME';

        $result = $this->validator->validate($this->schema, $input);

        $this->assertTrue($result->isValid());
        $this->assertSame('alert(1)ACME', $result->values()['company']);
    }

    /**
     * A submission that satisfies every required field of the fixture form.
     *
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'full_name.first'      => 'Jane',
            'full_name.last'       => 'Doe',
            'email'                => 'jane@example.com',
            'phone_number'         => '(555) 123-4567',
            'address.addr_line1'   => '1 Example Street',
            'address.city'         => 'Springfield',
            'company'              => 'ACME',
            'message'              => 'Hello there.',
            'how_did_you'          => 'Conference',
            'preferred_contact'    => 'E-mail',
            'topics_of'            => ['Pricing'],
            'team_size'            => '10',
        ];
    }
}
