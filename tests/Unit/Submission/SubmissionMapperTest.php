<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Submission\SubmissionMapper;
use JotformBridge\Tests\TestCase;

/**
 * The payload contract with Jotform.
 *
 * The expected parameter names are the ones Jotform documents for
 * `POST /form/{formID}/submissions` — `submission[qid]`,
 * `submission[qid][subfield]` and repeated `submission[qid][]` — not a shape
 * invented here. The qids and subfield names come from the fixture schema.
 */
final class SubmissionMapperTest extends TestCase
{
    private FormSchema $schema;

    private SubmissionMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = (new SchemaBuilder())->build(
            '240000000000001',
            array_values($this->fixture('form-questions')['content'])
        );

        $this->mapper = new SubmissionMapper();
    }

    public function testATextFieldMapsToTheBareQuestionId(): void
    {
        $this->assertSame(
            ['submission[7]' => 'ACME'],
            $this->mapper->map($this->schema, ['company' => 'ACME'])
        );
    }

    public function testATextareaMapsToTheBareQuestionId(): void
    {
        $this->assertSame(
            ['submission[8]' => 'Hello there.'],
            $this->mapper->map($this->schema, ['message' => 'Hello there.'])
        );
    }

    public function testAnEmailMapsToTheBareQuestionId(): void
    {
        $this->assertSame(
            ['submission[4]' => 'jane@example.com'],
            $this->mapper->map($this->schema, ['email' => 'jane@example.com'])
        );
    }

    public function testAFullNameMapsToOneSubfieldParameterPerChild(): void
    {
        $this->assertSame(
            [
                'submission[3][first]' => 'Jane',
                'submission[3][last]'  => 'Doe',
            ],
            $this->mapper->map(
                $this->schema,
                [
                    'full_name.first' => 'Jane',
                    'full_name.last'  => 'Doe',
                ]
            )
        );
    }

    public function testAnAddressMapsToTheJotformAnswerKeys(): void
    {
        $this->assertSame(
            [
                'submission[6][addr_line1]' => '1 Example Street',
                'submission[6][addr_line2]' => 'Apt 2',
                'submission[6][city]'       => 'Springfield',
                'submission[6][state]'      => 'IL',
                'submission[6][postal]'     => '62704',
            ],
            $this->mapper->map(
                $this->schema,
                [
                    'address.addr_line1' => '1 Example Street',
                    'address.addr_line2' => 'Apt 2',
                    'address.city'       => 'Springfield',
                    'address.state'      => 'IL',
                    'address.postal'     => '62704',
                ]
            )
        );
    }

    public function testAnAddressChildTheFormDoesNotHaveIsNotMapped(): void
    {
        // The fixture enables st1|st2|city|state|zip, so there is no country.
        $params = $this->mapper->map(
            $this->schema,
            [
                'address.city'    => 'Springfield',
                'address.country' => 'Atlantis',
            ]
        );

        $this->assertSame(['submission[6][city]' => 'Springfield'], $params);
    }

    public function testAMultiValueFieldMapsToARepeatedParameter(): void
    {
        $this->assertSame(
            ['submission[11][]' => ['Pricing', 'Support']],
            $this->mapper->map($this->schema, ['topics_of' => ['Pricing', 'Support']])
        );
    }

    public function testChoiceFieldsMapToTheBareQuestionId(): void
    {
        $this->assertSame(
            [
                'submission[9]'  => 'Conference',
                'submission[10]' => 'E-mail',
            ],
            $this->mapper->map(
                $this->schema,
                [
                    'how_did_you'       => 'Conference',
                    'preferred_contact' => 'E-mail',
                ]
            )
        );
    }

    public function testAPhoneMapsToTheBareQuestionId(): void
    {
        $this->assertSame(
            ['submission[5]' => '(555) 123-4567'],
            $this->mapper->map($this->schema, ['phone_number' => '(555) 123-4567'])
        );
    }

    public function testUnknownAndEmptyValuesAreNotMapped(): void
    {
        $params = $this->mapper->map(
            $this->schema,
            [
                'company'        => '',
                'not_a_field'    => 'x',
                'preferred_call' => '2026-01-01',
                'topics_of'      => [],
            ]
        );

        $this->assertSame([], $params);
    }

    public function testTheParameterOrderFollowsTheSchemaNotTheRequest(): void
    {
        $params = $this->mapper->map(
            $this->schema,
            [
                'message'         => 'Hello there.',
                'email'           => 'jane@example.com',
                'full_name.first' => 'Jane',
            ]
        );

        $this->assertSame(
            ['submission[3][first]', 'submission[4]', 'submission[8]'],
            array_keys($params)
        );
    }

    public function testTheEncodedBodyRepeatsMultiValueParameters(): void
    {
        $body = JotformClient::encodeBody(
            $this->mapper->map(
                $this->schema,
                [
                    'email'     => 'jane@example.com',
                    'topics_of' => ['Pricing', 'Support'],
                ]
            )
        );

        $this->assertSame(
            'submission%5B4%5D=jane%40example.com'
            . '&submission%5B11%5D%5B%5D=Pricing'
            . '&submission%5B11%5D%5B%5D=Support',
            $body
        );
    }
}
