<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Api;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Tests\TestCase;
use WP_Error;

final class JotformClientTest extends TestCase
{
    public function testGetFormsReturnsNormalizedListFromEnvelope(): void
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/Jotform/user-forms.json'),
            true
        );

        $response = $this->httpResponse(200, $fixture);
        Functions\when('wp_remote_get')->justReturn($response);

        $client = new JotformClient('secret-key', 'https://api.jotform.com/');
        $result = $client->getForms();

        $this->assertTrue($result->isSuccess());
        $this->assertCount(3, $result->data());
        $this->assertSame(
            [
                'id'      => '240000000000001',
                'title'   => 'Contact Form',
                'status'  => 'ENABLED',
                'updated' => '2026-02-11 08:45:02',
            ],
            $result->data()[0]
        );
    }

    public function testApiKeyTravelsInHeaderAndNeverInTheUrl(): void
    {
        $captured = [];

        Functions\when('wp_remote_get')->alias(
            function (string $url, array $args) use (&$captured) {
                $captured = ['url' => $url, 'args' => $args];

                return $this->httpResponse(200, ['responseCode' => 200, 'content' => []]);
            }
        );

        (new JotformClient('secret-key', 'https://eu-api.jotform.com'))->getForms();

        $this->assertStringStartsWith('https://eu-api.jotform.com/user/forms', $captured['url']);
        $this->assertStringNotContainsString('secret-key', $captured['url']);
        $this->assertSame('secret-key', $captured['args']['headers']['APIKEY']);
    }

    public function testMissingApiKeyFailsWithoutAnyRequest(): void
    {
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \LogicException('No request must be made without an API key.');
            }
        );

        $result = (new JotformClient('', 'https://api.jotform.com'))->testConnection();

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_NO_API_KEY, $result->errorCode());
    }

    public function testTransportErrorIsReportedWithoutLeakingDetails(): void
    {
        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_request_failed', 'cURL error 28'));

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))->testConnection();

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_TRANSPORT, $result->errorCode());
        $this->assertStringNotContainsString('cURL', $result->errorMessage());
    }

    public function testNonSuccessStatusIsReportedWithUpstreamMessage(): void
    {
        $response = $this->httpResponse(401, [
            'responseCode' => 401,
            'message'      => 'Invalid API Key',
            'content'      => [],
        ]);
        Functions\when('wp_remote_get')->justReturn($response);

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))->testConnection();

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_HTTP_STATUS, $result->errorCode());
        $this->assertSame(401, $result->status());
        $this->assertStringContainsString('Invalid API Key', $result->errorMessage());
    }

    public function testMalformedJsonIsReportedAsInvalidResponse(): void
    {
        $response = $this->httpResponse(200, '<html>maintenance</html>');
        Functions\when('wp_remote_get')->justReturn($response);

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))->testConnection();

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_INVALID_JSON, $result->errorCode());
    }

    public function testEnvelopeErrorCodeOverridesHttpSuccess(): void
    {
        $response = $this->httpResponse(200, [
            'responseCode' => 403,
            'message'      => 'Forbidden',
            'content'      => [],
        ]);
        Functions\when('wp_remote_get')->justReturn($response);

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))->testConnection();

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_API, $result->errorCode());
    }

    public function testUpstreamMessageEchoingTheKeyIsRedacted(): void
    {
        $response = $this->httpResponse(400, [
            'responseCode' => 400,
            'message'      => 'Bad key secret-key supplied',
            'content'      => [],
        ]);
        Functions\when('wp_remote_get')->justReturn($response);

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))->testConnection();

        $this->assertStringNotContainsString('secret-key', $result->errorMessage());
        $this->assertStringContainsString('[redacted]', $result->errorMessage());
    }

    public function testTestConnectionExposesAccountIdentity(): void
    {
        $response = $this->httpResponse(200, [
            'responseCode' => 200,
            'message'      => 'success',
            'content'      => [
                'username' => 'example_account',
                'email'    => 'owner@example.com',
                'status'   => 'ACTIVE',
            ],
        ]);
        Functions\when('wp_remote_get')->justReturn($response);

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))->testConnection();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('example_account', $result->data()['username']);
    }

    public function testGetFormQuestionsFlattensTheEnvelopeAndSortsByOrder(): void
    {
        $response = $this->httpResponse(200, $this->fixture('form-questions'));
        Functions\when('wp_remote_get')->justReturn($response);

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))
            ->getFormQuestions('240000000000001');

        $this->assertTrue($result->isSuccess());

        $questions = $result->data();

        $this->assertSame([0, 1, 2], array_slice(array_keys($questions), 0, 3), 'The list must be re-indexed.');
        $this->assertSame('1', $questions[0]['qid']);
        $this->assertSame('control_head', $questions[0]['type']);

        // The submit button has order 14 and qid 2: order wins over the key.
        $this->assertSame('2', $questions[count($questions) - 1]['qid']);
    }

    public function testGetFormQuestionsRequestsTheDocumentedEndpoint(): void
    {
        $captured = '';

        Functions\when('wp_remote_get')->alias(
            function (string $url) use (&$captured) {
                $captured = $url;

                return $this->httpResponse(200, ['responseCode' => 200, 'content' => []]);
            }
        );

        (new JotformClient('secret-key', 'https://api.jotform.com'))->getFormQuestions('240000000000001');

        $this->assertSame('https://api.jotform.com/form/240000000000001/questions', $captured);
    }

    public function testGetFormQuestionsRejectsANonNumericFormId(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            $this->httpResponse(200, ['responseCode' => 200, 'content' => []])
        );

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))
            ->getFormQuestions('../user/forms');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_UNEXPECTED, $result->errorCode());
    }

    public function testGetFormQuestionsPropagatesUpstreamFailures(): void
    {
        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_request_failed', 'timeout'));

        $result = (new JotformClient('secret-key', 'https://api.jotform.com'))
            ->getFormQuestions('240000000000001');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(JotformClient::ERROR_TRANSPORT, $result->errorCode());
    }
}
