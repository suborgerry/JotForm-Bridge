<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Rest;

use JotformBridge\Rest\SubmissionController;
use JotformBridge\Submission\Guards\ProofOfWork;
use JotformBridge\Tests\Integration\TestCase;
use WP_REST_Request;

/**
 * The submission endpoint, exercised through the REST server.
 *
 * The unit suite calls the pipeline. This calls the route: the registration,
 * the permission callback, the JSON body, the status codes and the headers a
 * browser actually receives. Everything up to the Jotform boundary is real —
 * only the outbound HTTP request is answered from a fixture, because AGENTS.md
 * does not permit a live write.
 *
 * The form is the sanitized contact form fixture the unit suite uses:
 * full_name (first, last), email and message are required, preferred_contact
 * is a required radio, and topics_of is a checkbox.
 */
final class SubmissionRouteTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    /**
     * @var array<int, array{url:string, body:string}>
     */
    private array $sent = [];

    public function testConditionalHiddenFieldIsExcludedAtTheUpstreamBoundary(): void
    {
        $this->givenAForm(['conditions' => [['action' => 'show', 'target' => 'message', 'source' => 'preferred_contact', 'operator' => 'not_equals', 'value' => 'E-mail']]]);
        $this->acceptUpstream();
        $body = $this->validBody();
        $body['fields']['message'] = ['forged'];
        $response = $this->submitThroughRest('contact', $body);
        $this->assertSame(200, $response->get_status());
        parse_str($this->sent[0]['body'], $params);
        $this->assertArrayNotHasKey('8', $params['submission']);
    }

    public function testConditionalRequiredFieldCannotBeBypassedThroughRest(): void
    {
        $this->givenAForm(['conditions' => [['action' => 'require', 'target' => 'address.city', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'E-mail']]]);
        $response = $this->submitThroughRest('contact', $this->validBody());
        $this->assertSame(422, $response->get_status());
        $this->assertArrayHasKey('address.city', $response->get_data()['errors']);
        $this->assertSame([], $this->sent);
    }

    public function testRulesBrokenBySchemaChangesRefuseWithoutAnUpstreamCall(): void
    {
        $this->givenAForm(['conditions' => [['action' => 'show', 'target' => 'removed_field', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'E-mail']]]);
        $response = $this->submitThroughRest('contact', $this->validBody());
        $this->assertSame(503, $response->get_status());
        $this->assertSame([], $this->sent);
    }

    public function testTheRouteIsRegisteredAndAnonymous(): void
    {
        $route = '/' . SubmissionController::NAMESPACE . SubmissionController::ROUTE;
        $routes = rest_get_server()->get_routes();

        $this->assertArrayHasKey($route, $routes);

        $handler = $routes[$route][0];

        $this->assertSame(['POST' => true], $handler['methods']);
        $this->assertTrue(
            call_user_func($handler['permission_callback']),
            'The endpoint has to serve visitors who are not logged in.'
        );
    }

    public function testAValidSubmissionReachesJotformAndIsAnsweredWithSuccess(): void
    {
        $this->givenAForm();
        $this->acceptUpstream();

        $response = $this->submitThroughRest('contact', $this->validBody());

        $this->assertSame(200, $response->get_status());

        $body = $response->get_data();

        $this->assertTrue($body['success']);
        $this->assertSame('Form submitted successfully.', $body['message']);
        $this->assertArrayNotHasKey('redirect', $body, 'No redirect is configured on this integration.');

        $headers = $response->get_headers();

        $this->assertSame('no-store, private', $headers['Cache-Control'] ?? '');
    }

    public function testTheValuesArriveAtJotformUnderTheirQuestionIds(): void
    {
        $this->givenAForm();
        $this->acceptUpstream();

        $this->submitThroughRest('contact', $this->validBody());

        $this->assertCount(1, $this->sent, 'Exactly one upstream request was expected.');
        $this->assertStringEndsWith('/form/' . self::FORM_ID . '/submissions', $this->sent[0]['url']);

        parse_str($this->sent[0]['body'], $params);

        $this->assertSame(
            [
                '3' => ['first' => 'Ada', 'last' => 'Lovelace'],
                '4' => 'ada@example.test',
                '8' => "Two lines\nof message.",
                '10' => 'E-mail',
            ],
            $params['submission'],
            'The frontend sends semantic keys; only the backend knows the qids.'
        );
    }

    public function testAnInvalidSubmissionIsAnsweredWith422AndPerFieldErrors(): void
    {
        $this->givenAForm();

        $response = $this->submitThroughRest(
            'contact',
            [
                'fields' => [
                    'full_name.first' => 'Ada',
                    'email'      => 'not-an-email',
                ],
                'spam'   => ['pow' => $this->proofOfWork('contact')],
            ]
        );

        $this->assertSame(422, $response->get_status());

        $body = $response->get_data();

        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed.', $body['message']);
        $this->assertArrayHasKey('email', $body['errors']);
        $this->assertArrayHasKey('message', $body['errors'], 'A missing required field is an error.');
        $this->assertSame([], $this->sent, 'Nothing may be sent upstream before validation passes.');
    }

    public function testAnUnknownIntegrationIsAnsweredLikeEveryOtherRefusal(): void
    {
        $this->givenAForm();

        $response = $this->submitThroughRest('no-such-integration', $this->validBody());

        $this->assertSame(503, $response->get_status());
        $this->assertSame(
            'The form could not be submitted right now. Please try again later.',
            $response->get_data()['message'],
            'The endpoint must not confirm which slugs exist.'
        );
        $this->assertSame([], $this->sent);
    }

    public function testAFormWithNoSyncedSchemaRefusesWithoutAskingJotform(): void
    {
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);

        $response = $this->submitThroughRest('contact', $this->validBody());

        $this->assertSame(503, $response->get_status());
        $this->assertSame([], $this->sent, 'A visitor\'s submission never fetches a schema.');
    }

    public function testASubmissionWithoutAProofOfWorkIsRefused(): void
    {
        $this->givenAForm();

        $body = $this->validBody();

        unset($body['spam']);

        $response = $this->submitThroughRest('contact', $body);

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->sent);
    }

    public function testTheSameProofCannotBeUsedTwice(): void
    {
        $this->givenAForm();
        $this->acceptUpstream();

        $body = $this->validBody();

        $this->assertSame(200, $this->submitThroughRest('contact', $body)->get_status());

        // Different values, so the duplicate guard is not what refuses it.
        $body['fields']['message'] = 'A different message.';

        $this->assertSame(403, $this->submitThroughRest('contact', $body)->get_status());
        $this->assertCount(1, $this->sent);
    }

    public function testAnIdenticalSubmissionIsRefusedAsADuplicate(): void
    {
        $this->givenAForm();
        $this->acceptUpstream();

        $this->assertSame(200, $this->submitThroughRest('contact', $this->validBody())->get_status());

        $second = $this->submitThroughRest('contact', $this->validBody());

        $this->assertSame(429, $second->get_status());
        $this->assertCount(1, $this->sent, 'A double submit must not become two Jotform submissions.');
    }

    public function testTooMuchTrafficIsRefusedWithRetryAfter(): void
    {
        $this->givenAForm();

        $this->filter(
            'jotform_bridge_rate_limits',
            static fn(): array => ['per_minute' => 1, 'per_hour' => 1],
            10,
            2
        );

        $body = $this->validBody();

        unset($body['spam']);

        // Refused by the proof of work, but the rate limit ran first and the
        // attempt was charged to this address.
        $this->assertSame(403, $this->submitThroughRest('contact', $body)->get_status());

        $second = $this->submitThroughRest('contact', $body);

        $this->assertSame(429, $second->get_status());
        $this->assertArrayHasKey('Retry-After', $second->get_headers());
        $this->assertGreaterThan(0, (int) $second->get_headers()['Retry-After']);
    }

    public function testAConfiguredRedirectComesBackWithTheAnswer(): void
    {
        $pageId = $this->createPage();

        $this->givenAForm(
            [
                'success_action'   => 'redirect',
                'redirect_page_id' => (string) $pageId,
                'redirect_delay'   => '3',
            ]
        );
        $this->acceptUpstream();

        $response = $this->submitThroughRest('contact', $this->validBody());

        $this->assertSame(200, $response->get_status());

        $redirect = $response->get_data()['redirect'];

        $this->assertSame(get_permalink($pageId), $redirect['url']);
        $this->assertSame(3, $redirect['delay']);
        $this->assertStringStartsWith(home_url(), $redirect['url'], 'The redirect target must be internal.');
    }

    public function testARedirectToATrashedPageDegradesToTheSuccessMessage(): void
    {
        $pageId = $this->createPage();

        $this->givenAForm(
            [
                'success_action'   => 'redirect',
                'redirect_page_id' => (string) $pageId,
            ]
        );
        $this->acceptUpstream();

        wp_trash_post($pageId);

        $response = $this->submitThroughRest('contact', $this->validBody());

        $this->assertSame(200, $response->get_status(), 'A broken redirect never fails the submission.');
        $this->assertArrayNotHasKey('redirect', $response->get_data());
    }

    public function testAnUpstreamFailureIsAnsweredWith502AndTellsTheVisitorNothing(): void
    {
        $this->givenAForm();

        $this->mockHttp(
            function (string $url) {
                return strpos($url, '/submissions') === false
                    ? null
                    : $this->httpResponse(
                        500,
                        ['responseCode' => 500, 'message' => 'Database write failed on shard 7']
                    );
            }
        );

        $response = $this->submitThroughRest('contact', $this->validBody());

        $this->assertSame(502, $response->get_status());
        $this->assertStringNotContainsString('shard', (string) $response->get_data()['message']);
    }

    public function testABodyOverTheCapIsRefusedBeforeItIsDecoded(): void
    {
        $this->givenAForm();

        $request = new WP_REST_Request('POST', '/jotform-bridge/v1/submit/contact');

        $request->set_header('content-type', 'application/json');
        $request->set_body(
            (string) wp_json_encode(
                ['fields' => ['message' => str_repeat('x', SubmissionController::MAX_BODY_BYTES)]]
            )
        );

        $response = rest_get_server()->dispatch($request);

        $this->assertSame(413, $response->get_status());
        $this->assertSame('no-store, private', $response->get_headers()['Cache-Control'] ?? '');
    }

    /**
     * An integration with a synced schema, ready to take a submission.
     *
     * @param array<string, mixed> $values
     */
    private function givenAForm(array $values = []): void
    {
        $this->createIntegration(
            array_merge(['slug' => 'contact', 'form_id' => self::FORM_ID], $values)
        );

        $this->syncSchema(self::FORM_ID);
    }

    /**
     * Answers the submission POST the way Jotform does, and records what was
     * sent so the mapping can be asserted.
     */
    private function acceptUpstream(): void
    {
        $this->mockHttp(
            function (string $url, array $args) {
                if (strpos($url, '/submissions') === false) {
                    return null;
                }

                $this->sent[] = ['url' => $url, 'body' => (string) ($args['body'] ?? '')];

                return $this->httpResponse(
                    200,
                    [
                        'responseCode' => 200,
                        'message'      => 'success',
                        'content'      => ['submissionID' => '6100000000000000001'],
                        'limit-left'   => 900,
                    ]
                );
            }
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'fields' => [
                'full_name.first'   => 'Ada',
                'full_name.last'    => 'Lovelace',
                'email'             => 'ada@example.test',
                'message'           => "Two lines\nof message.",
                'preferred_contact' => 'E-mail',
            ],
            'spam'   => [ProofOfWork::KEY => $this->proofOfWork('contact')],
        ];
    }
}
