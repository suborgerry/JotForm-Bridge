<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;
use JotformBridge\Settings\Settings;
use JotformBridge\Submission\QuotaGuard;
use JotformBridge\Submission\TestSubmission;
use JotformBridge\Tests\TestCase;

final class TestSubmissionTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, array<string, mixed>> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options  = [];
        $this->requests = [];

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );

        $this->storeSchema();
    }

    public function testItSendsValuesJotformWouldAccept(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '600000000000001']]);

        $response = $this->service()->send($this->integration());

        $this->assertTrue($response->isSuccess());
        $this->assertSame('600000000000001', $response->data()['submission_id']);
        $this->assertCount(1, $this->requests);

        parse_str($this->requests[0]['args']['body'], $sent);

        // Every field the plugin can map is filled in, not just the required
        // ones: the point is to exercise the whole payload, so a field that
        // only breaks when populated breaks here rather than in front of a
        // visitor.
        $schema   = (new SchemaBuilder())->build(
            self::FORM_ID,
            array_values($this->fixture('form-questions')['content'])
        );
        $expected = [];

        foreach ($schema->supportedFields() as $field) {
            $expected[] = (int) $field['qid'];
        }

        sort($expected);

        $actual = array_map('intval', array_keys($sent['submission']));
        sort($actual);

        $this->assertSame($expected, $actual);
        $this->assertGreaterThan(5, count($actual));
    }

    /**
     * Choice fields must take an option Jotform itself reported, not something
     * that merely passes our own validator.
     */
    public function testChoiceFieldsUseRealOptions(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->service()->send($this->integration());

        parse_str($this->requests[0]['args']['body'], $sent);

        $this->assertSame('E-mail', $sent['submission']['10']);
        $this->assertSame(['Pricing'], $sent['submission']['11']);
    }

    public function testTheSubmissionIsRecognisableInTheJotformInbox(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->service()->send($this->integration());

        $this->assertStringContainsString(
            rawurlencode(TestSubmission::MARKER),
            (string) $this->requests[0]['args']['body']
        );
    }

    /**
     * A test address that could reach a real person would be a bug: RFC 2606
     * reserves example.com precisely so it cannot.
     */
    public function testTheEmailAddressCannotReachAnybody(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->service()->send($this->integration());

        parse_str($this->requests[0]['args']['body'], $sent);

        $this->assertStringEndsWith('@example.com', $sent['submission']['4']);
    }

    /**
     * Unlike the visitor-facing path, the administrator is shown exactly what
     * Jotform said — that is the entire point of the button.
     */
    public function testTheUpstreamErrorIsPassedThroughUntouched(): void
    {
        $this->mockPost(200, ['responseCode' => 403, 'message' => 'Account is not authorized to write']);

        $response = $this->service()->send($this->integration());

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('not authorized to write', $response->errorMessage());
    }

    public function testAnUnsyncedFormIsRefusedWithoutSendingAnything(): void
    {
        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);

        Functions\when('wp_remote_post')->alias(
            static function (): void {
                throw new \RuntimeException('Nothing may be sent without a schema.');
            }
        );

        $response = $this->service()->send($this->integration());

        $this->assertFalse($response->isSuccess());
        $this->assertSame(SchemaRepository::ERROR_NOT_SYNCED, $response->errorCode());
    }

    /**
     * It really does spend one of the month's allowance, and the guard has to
     * know, or its arithmetic goes quietly wrong.
     */
    public function testAnAcceptedTestIsChargedToTheAllowance(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $quota = new QuotaGuard(new Settings());

        $this->service($quota)->send($this->integration());

        $this->assertSame(1, $quota->status()['since_check']);
    }

    public function testARejectedTestIsNotCharged(): void
    {
        $this->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);

        $quota = new QuotaGuard(new Settings());

        $this->service($quota)->send($this->integration());

        $this->assertSame(0, $quota->status()['since_check']);
    }

    private function service(?QuotaGuard $quota = null): TestSubmission
    {
        $client = new JotformClient('test-api-key', 'https://api.jotform.com');

        return new TestSubmission(new SchemaRepository($client), $client, null, null, $quota);
    }

    private function integration(): Integration
    {
        return new Integration('contact', 'Contact', self::FORM_ID, Integration::MODE_AUTO, '');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function mockPost(int $status, array $body): void
    {
        $response = $this->httpResponse($status, $body);

        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use ($response) {
                $this->requests[] = ['url' => $url, 'args' => $args];

                return $response;
            }
        );
    }

    private function storeSchema(): void
    {
        $schema = (new SchemaBuilder())->build(
            self::FORM_ID,
            array_values($this->fixture('form-questions')['content'])
        );

        $this->options[SchemaRepository::optionKey(self::FORM_ID)] = $schema->toArray();
    }
}
