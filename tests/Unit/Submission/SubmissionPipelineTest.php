<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Submission;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Settings\Settings;
use JotformBridge\Submission\Guards\Honeypot;
use JotformBridge\Submission\QuotaGuard;
use JotformBridge\Submission\RateLimiter;
use JotformBridge\Submission\SubmissionOutcome;
use JotformBridge\Submission\SubmissionPipeline;
use JotformBridge\Submission\ValidationResult;
use JotformBridge\Support\Logger;
use JotformBridge\Support\Stats;
use JotformBridge\Tests\TestCase;

/**
 * The submission flow end to end, up to the upstream boundary.
 *
 * Everything below the Jotform HTTP call is real: integration lookup, cached
 * schema, validation, mapping and response contract. Only the transport is
 * mocked, so no test ever writes to a real form.
 */
final class SubmissionPipelineTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    /** @var array<int, array<string, mixed>> Captured wp_remote_post() calls. */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options    = [];
        $this->transients = [];
        $this->requests   = [];

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('get_transient')->alias(
            fn(string $name) => $this->transients[$name] ?? false
        );
        Functions\when('set_transient')->alias(
            function (string $name, $value): bool {
                $this->transients[$name] = $value;

                return true;
            }
        );

        $this->storeIntegration(true);
        $this->cacheSchema();
    }

    public function testAnUnknownIntegrationIsNotFound(): void
    {
        $outcome = $this->pipeline()->submit('nope', $this->valid());

        $this->assertSame(404, $outcome->status());
        $this->assertFalse($outcome->body()['success']);
        $this->assertSame([], $this->requests);
    }

    public function testAnInactiveIntegrationIsRefused(): void
    {
        $this->storeIntegration(false);

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(403, $outcome->status());
        $this->assertSame([], $this->requests);
    }

    public function testAMissingRequiredFieldFailsValidation(): void
    {
        $fields = $this->valid();
        unset($fields['email']);

        $outcome = $this->pipeline()->submit('contact', $fields);

        $this->assertSame(422, $outcome->status());
        $this->assertSame('Validation failed.', $outcome->body()['message']);
        $this->assertArrayHasKey('email', $outcome->errors());
        $this->assertSame([], $this->requests);
    }

    public function testAnUnknownFieldFailsValidation(): void
    {
        $fields           = $this->valid();
        $fields['nonsense'] = 'x';

        $outcome = $this->pipeline()->submit('contact', $fields);

        $this->assertSame(422, $outcome->status());
        $this->assertArrayHasKey('nonsense', $outcome->errors());
    }

    public function testABodyWithoutFieldsFailsValidation(): void
    {
        $outcome = $this->pipeline()->submit('contact', null);

        $this->assertSame(422, $outcome->status());
        $this->assertArrayHasKey(ValidationResult::FORM_KEY, $outcome->errors());
    }

    /**
     * A form nobody has synced cannot be submitted — and, just as importantly,
     * the submission does not turn into a Jotform request to find out what the
     * form looks like. The visitor gets a generic message either way.
     */
    public function testAnUnsyncedSchemaIsASafeServerErrorWithoutAnyRequest(): void
    {
        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);

        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \RuntimeException('The submission path must never fetch a schema.');
            }
        );

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(503, $outcome->status());
        $this->assertFalse($outcome->body()['success']);
        $this->assertSame([], $this->requests);
    }

    public function testAnUpstreamFailureIsReportedWithoutUpstreamDetail(): void
    {
        $this->mockPost(403, ['responseCode' => 403, 'message' => 'Forbidden: apiKey abcd1234 has no write access']);

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(502, $outcome->status());
        $this->assertFalse($outcome->body()['success']);
        $this->assertStringNotContainsString('abcd1234', (string) $outcome->body()['message']);
        $this->assertArrayNotHasKey('errors', $outcome->body());
    }

    public function testASuccessfulSubmissionSendsTheMappedPayload(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '600000000000001']]);

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(200, $outcome->status());
        $this->assertTrue($outcome->body()['success']);
        $this->assertSame('Form submitted successfully.', $outcome->body()['message']);

        $this->assertCount(1, $this->requests);
        $request = $this->requests[0];

        $this->assertSame(
            'https://api.jotform.com/form/' . self::FORM_ID . '/submissions',
            $request['url']
        );
        $this->assertSame(
            'application/x-www-form-urlencoded',
            $request['args']['headers']['Content-Type']
        );

        parse_str($request['args']['body'], $sent);

        $this->assertSame(
            [
                '3'  => ['first' => 'Jane', 'last' => 'Doe'],
                '4'  => 'jane@example.com',
                '8'  => 'Hello there.',
                '10' => 'E-mail',
                '11' => ['Pricing', 'Support'],
            ],
            $sent['submission']
        );
    }

    public function testTheSpamExtensionPointCanRejectASubmission(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        Functions\when('apply_filters')->alias(
            static function (string $hook, $value) {
                return $hook === 'jotform_bridge_spam_check' ? 'Please solve the challenge.' : $value;
            }
        );

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(403, $outcome->status());
        $this->assertSame('Please solve the challenge.', $outcome->body()['message']);
        $this->assertSame([], $this->requests, 'A rejected submission must not reach Jotform.');
    }

    /**
     * The wiring the honeypot depends on: a value that arrives in the request's
     * `spam` container has to reach the extension point untouched, and a
     * rejection there has to stop the submission before Jotform is called.
     */
    public function testAFilledHoneypotStopsTheSubmissionBeforeJotform(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
        $this->routeSpamFilterThroughHoneypot();

        $outcome = $this->pipeline()->submit(
            'contact',
            $this->valid(),
            ['ip' => '203.0.113.7', 'spam' => [Honeypot::KEY => 'https://spam.example']]
        );

        $this->assertSame(403, $outcome->status());
        $this->assertSame([], $this->requests, 'A trapped bot must not reach Jotform.');

        // The answer must not name the trap.
        $this->assertStringNotContainsString('honeypot', strtolower((string) $outcome->body()['message']));
        $this->assertStringNotContainsString(Honeypot::FIELD_NAME, (string) json_encode($outcome->body()));
    }

    public function testAnEmptyHoneypotLetsTheSubmissionThrough(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
        $this->routeSpamFilterThroughHoneypot();

        $outcome = $this->pipeline()->submit(
            'contact',
            $this->valid(),
            ['ip' => '203.0.113.7', 'spam' => [Honeypot::KEY => '']]
        );

        $this->assertSame(200, $outcome->status());
        $this->assertCount(1, $this->requests);
    }

    public function testAFloodFromOneAddressIsRefusedWithRetryAfter(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE; $i++) {
            $fields            = $this->valid();
            $fields['message'] = 'Message number ' . $i;

            $this->assertSame(
                200,
                $this->pipeline()->submit('contact', $fields, ['ip' => '203.0.113.7'])->status()
            );
        }

        $outcome = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(429, $outcome->status());
        $this->assertArrayHasKey('Retry-After', $outcome->headers());
        $this->assertGreaterThan(0, (int) $outcome->headers()['Retry-After']);
        $this->assertCount(
            RateLimiter::DEFAULT_PER_MINUTE,
            $this->requests,
            'A throttled attempt must not reach Jotform.'
        );
    }

    /**
     * The rate limit is the first thing checked for a reason: a flood must not
     * cost a schema read or a pass through the validator.
     */
    public function testAThrottledAttemptCostsNoSchemaReadAndNoValidation(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE; $i++) {
            $fields            = $this->valid();
            $fields['message'] = 'Message number ' . $i;

            $this->pipeline()->submit('contact', $fields, ['ip' => '203.0.113.7']);
        }

        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);

        // Nonsense values: reaching the validator at all would produce a 422
        // instead of the 429 the guard owes us.
        $outcome = $this->pipeline()->submit(
            'contact',
            ['not-a-field' => str_repeat('x', 4000)],
            ['ip' => '203.0.113.7']
        );

        $this->assertSame(429, $outcome->status());
        $this->assertArrayNotHasKey('errors', $outcome->body());
    }

    public function testEachAddressHasItsOwnBudget(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        for ($i = 0; $i < RateLimiter::DEFAULT_PER_MINUTE + 2; $i++) {
            $fields            = $this->valid();
            $fields['message'] = 'Message number ' . $i;

            $this->pipeline()->submit('contact', $fields, ['ip' => '203.0.113.7']);
        }

        $this->assertSame(
            200,
            $this->pipeline()->submit('contact', $this->valid(), ['ip' => '198.51.100.9'])->status()
        );
    }

    /**
     * The account-wide breaker: once it has tripped, nothing reaches Jotform,
     * whichever integration or address the submission comes from.
     */
    public function testATrippedQuotaGuardStopsEverySubmission(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->options[Settings::OPTION]   = ['monthly_quota' => 10];
        $this->options[QuotaGuard::OPTION] = [
            'days'              => [],
            'usage_submissions' => 10,
            'usage_checked_at'  => time(),
            'since_check'       => 0,
        ];

        $outcome = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(503, $outcome->status());
        $this->assertSame([], $this->requests);

        // The visitor is told nothing about which ceiling was hit.
        $this->assertSame(
            'The form could not be submitted right now. Please try again later.',
            $outcome->body()['message']
        );
    }

    public function testTheQuotaGuardAnswersBeforeTheSchemaIsRead(): void
    {
        $this->options[Settings::OPTION]   = ['monthly_quota' => 10];
        $this->options[QuotaGuard::OPTION] = [
            'days'              => [],
            'usage_submissions' => 10,
            'usage_checked_at'  => time(),
            'since_check'       => 0,
        ];

        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);

        $outcome = $this->pipeline()->submit('contact', ['not-a-field' => 'x'], ['ip' => '203.0.113.7']);

        $this->assertSame(503, $outcome->status());
        $this->assertArrayNotHasKey('errors', $outcome->body());
    }

    public function testAnAcceptedSubmissionIsChargedToTheAllowance(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(1, (new QuotaGuard(new Settings()))->status()['since_check']);
    }

    /**
     * An upstream failure spent no part of the allowance, and charging it here
     * would make an outage look like a flood.
     */
    public function testAFailedSubmissionIsNotChargedToTheAllowance(): void
    {
        $this->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);

        $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(0, (new QuotaGuard(new Settings()))->status()['since_check']);
    }

    /**
     * An unknown slug is the cheapest answer the endpoint has, which is exactly
     * why probing for one must still be charged.
     */
    public function testProbingForSlugsIsRateLimitedToo(): void
    {
        for ($i = 0; $i < RateLimiter::DEFAULT_GLOBAL_PER_MINUTE; $i++) {
            $this->assertSame(
                404,
                $this->pipeline()->submit('guess-' . $i, $this->valid(), ['ip' => '203.0.113.7'])->status()
            );
        }

        $outcome = $this->pipeline()->submit('guess-again', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(429, $outcome->status());
        $this->assertArrayHasKey('Retry-After', $outcome->headers());
    }

    /**
     * Having spent the site-wide budget on probing, the attacker must not then
     * find the real integration open.
     */
    public function testASpentGlobalBudgetAlsoClosesTheRealIntegration(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        for ($i = 0; $i < RateLimiter::DEFAULT_GLOBAL_PER_MINUTE; $i++) {
            $this->pipeline()->submit('guess-' . $i, $this->valid(), ['ip' => '203.0.113.7']);
        }

        $outcome = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(429, $outcome->status());
        $this->assertSame([], $this->requests);
    }

    /**
     * Every exit from the pipeline is counted, so the admin screens describe
     * what the code did rather than a parallel guess at it.
     *
     * @dataProvider countedOutcomes
     */
    public function testEveryOutcomeIsCounted(string $expected, callable $arrange): void
    {
        $arrange($this);

        $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $counts = (new Stats())->summary('contact', 1)['counts'];

        $this->assertSame(1, $counts[$expected] ?? 0, 'Expected one ' . $expected . ' in ' . json_encode($counts));
    }

    /**
     * @return array<string, array{0:string, 1:callable}>
     */
    public function countedOutcomes(): array
    {
        return [
            'accepted'   => [
                Stats::OK,
                static function (self $test): void {
                    $test->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
                },
            ],
            'upstream'   => [
                Stats::UPSTREAM,
                static function (self $test): void {
                    $test->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);
                },
            ],
            'no schema'  => [
                Stats::NO_SCHEMA,
                static function (self $test): void {
                    $test->dropSchema();
                },
            ],
            'disabled'   => [
                Stats::INACTIVE,
                static function (self $test): void {
                    $test->storeIntegration(false);
                },
            ],
        ];
    }

    public function testAnUnknownSlugIsCountedInTheSharedBucket(): void
    {
        $this->pipeline()->submit('nope', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(
            1,
            (new Stats())->summary(Stats::GLOBAL_SCOPE, 1)['counts'][Stats::UNKNOWN] ?? 0
        );
        $this->assertSame(0, (new Stats())->summary('nope', 1)['attempts']);
    }

    public function testAValidationFailureRecordsWhichFieldsFailed(): void
    {
        $fields = $this->valid();
        unset($fields['email']);

        $this->pipeline()->submit('contact', $fields, ['ip' => '203.0.113.7']);

        $this->assertArrayHasKey('email', (new Stats())->fieldErrors('contact', 1));
    }

    /**
     * Jotform saying the account is out of allowance is the most authoritative
     * signal there is, and neither condition clears within a request's
     * lifetime. Sending the next visitor at the same wall only wastes time.
     *
     * @dataProvider upstreamAllowanceFailures
     */
    public function testAnUpstreamAllowanceFailureTripsTheBreaker(string $message, string $reason): void
    {
        $this->mockPost(200, ['responseCode' => 403, 'message' => $message]);

        $first = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(503, $first->status());
        $this->assertSame($reason, (new QuotaGuard(new Settings()))->status()['reason']);

        // The next one does not even reach the API.
        $second = $this->valid();
        $second['message'] = 'A different message.';

        $this->assertSame(
            503,
            $this->pipeline()->submit('contact', $second, ['ip' => '203.0.113.7'])->status()
        );
        $this->assertCount(1, $this->requests, 'The breaker must stop the next call.');
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public function upstreamAllowanceFailures(): array
    {
        return [
            'monthly allowance' => ['Form Over Quota', QuotaGuard::REASON_UPSTREAM_QUOTA],
            'daily api calls'   => ['API-Limit exceeded', QuotaGuard::REASON_UPSTREAM_API_LIMIT],
        ];
    }

    /**
     * An ordinary upstream failure is transient and must stay retryable.
     */
    public function testAnOrdinaryUpstreamFailureDoesNotTripTheBreaker(): void
    {
        $this->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);

        $outcome = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(502, $outcome->status());
        $this->assertFalse((new QuotaGuard(new Settings()))->isTripped());
    }

    public function testTheRemainingApiAllowanceIsRemembered(): void
    {
        $this->mockPost(
            200,
            ['responseCode' => 200, 'limit-left' => 512, 'content' => ['submissionID' => '1']]
        );

        $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(512, (new QuotaGuard(new Settings()))->status()['limit_left']);
    }

    public function testTheOutcomeNeverLeaksTheApiKey(): void
    {
        $this->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertStringNotContainsString('test-api-key', json_encode($outcome->body()) ?: '');
    }

    public function testAnIdenticalSubmissionIsRefusedRightAfterOneWasAccepted(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $first = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(200, $first->status());

        $second = $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $this->assertSame(429, $second->status());
        $this->assertCount(1, $this->requests, 'The duplicate must not reach Jotform.');
    }

    public function testTheDuplicateGuardOnlyRemembersAcceptedSubmissions(): void
    {
        $this->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);

        $this->assertSame(502, $this->pipeline()->submit('contact', $this->valid())->status());

        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->assertSame(
            200,
            $this->pipeline()->submit('contact', $this->valid())->status(),
            'A failed submission must be retryable immediately.'
        );
    }

    public function testADifferentSubmissionIsNotTreatedAsADuplicate(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $other            = $this->valid();
        $other['message'] = 'A different message.';

        $outcome = $this->pipeline()->submit('contact', $other, ['ip' => '203.0.113.7']);

        $this->assertSame(200, $outcome->status());
        $this->assertCount(2, $this->requests);
    }

    public function testTheDuplicateGuardStoresNoSubmissionValues(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $this->pipeline()->submit('contact', $this->valid(), ['ip' => '203.0.113.7']);

        $stored = json_encode($this->transients) ?: '';

        $this->assertStringNotContainsString('jane@example.com', $stored);
        $this->assertStringNotContainsString('203.0.113.7', $stored);
    }

    /**
     * A key that is not a plausible identifier must not come back in the answer.
     */
    public function testAHostileFieldNameIsNotEchoedBack(): void
    {
        $fields = $this->valid();
        $fields['"><script>alert(1)</script>'] = 'x';

        $outcome = $this->pipeline()->submit('contact', $fields);

        $this->assertSame(422, $outcome->status());
        $this->assertArrayHasKey(ValidationResult::FORM_KEY, $outcome->errors());
        $this->assertStringNotContainsString('<script>', json_encode($outcome->body()) ?: '');
        $this->assertSame([], $this->requests);
    }

    public function testAnOverlongFieldNameIsNotEchoedBack(): void
    {
        $fields                            = $this->valid();
        $fields[str_repeat('a', 5000)] = 'x';

        $outcome = $this->pipeline()->submit('contact', $fields);

        $this->assertSame(422, $outcome->status());
        $this->assertArrayHasKey(ValidationResult::FORM_KEY, $outcome->errors());

        foreach (array_keys($outcome->errors()) as $key) {
            $this->assertLessThanOrEqual(128, strlen((string) $key));
        }
    }

    public function testASuccessWithoutAConfiguredRedirectCarriesNoRedirect(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(200, $outcome->status());
        $this->assertArrayNotHasKey('redirect', $outcome->body());
    }

    public function testAConfiguredRedirectIsResolvedIntoTheSuccessResponse(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
        $this->storeIntegration(true, ['success_action' => 'redirect', 'redirect_page_id' => 42, 'redirect_delay' => 3]);
        $this->mockPage('publish', 'https://example.com/thanks/');

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(200, $outcome->status());
        $this->assertSame(
            ['url' => 'https://example.com/thanks/', 'delay' => 3],
            $outcome->body()['redirect']
        );
    }

    /**
     * @dataProvider brokenTargets
     */
    public function testABrokenRedirectTargetStillLeavesTheSubmissionSuccessful(
        string $status,
        string $permalink
    ): void {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
        $this->storeIntegration(true, ['success_action' => 'redirect', 'redirect_page_id' => 42]);
        $this->mockPage($status, $permalink);

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(200, $outcome->status());
        $this->assertTrue($outcome->body()['success']);
        $this->assertArrayNotHasKey('redirect', $outcome->body());
        $this->assertCount(1, $this->requests, 'The submission itself must still have been sent.');
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public function brokenTargets(): array
    {
        return [
            'deleted'           => ['', 'https://example.com/thanks/'],
            'trashed'           => ['trash', 'https://example.com/thanks/'],
            'draft'             => ['draft', 'https://example.com/thanks/'],
            'external host'     => ['publish', 'https://evil.example/thanks/'],
            'protocol relative' => ['publish', '//evil.example/thanks/'],
        ];
    }

    /**
     * The redirect is a property of the integration, so nothing the visitor
     * sends can introduce, change or suppress one.
     */
    public function testRedirectInputInTheRequestIsIgnored(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
        $this->mockPage('publish', 'https://example.com/thanks/');

        $fields             = $this->valid();
        $fields['redirect'] = 'https://evil.example/';

        $outcome = $this->pipeline()->submit('contact', $fields);

        // An unknown semantic key is a validation failure, which is the strongest
        // possible answer: it never even reaches the success path.
        $this->assertSame(422, $outcome->status());
        $this->assertArrayNotHasKey('redirect', $outcome->body());
        $this->assertStringNotContainsString('evil.example', (string) json_encode($outcome->body()));
    }

    public function testAValidationFailureNeverCarriesARedirect(): void
    {
        $this->storeIntegration(true, ['success_action' => 'redirect', 'redirect_page_id' => 42]);
        $this->mockPage('publish', 'https://example.com/thanks/');

        $fields = $this->valid();
        unset($fields['email']);

        $outcome = $this->pipeline()->submit('contact', $fields);

        $this->assertSame(422, $outcome->status());
        $this->assertArrayNotHasKey('redirect', $outcome->body());
    }

    public function testAnUpstreamErrorNeverCarriesARedirect(): void
    {
        $this->mockPost(500, ['responseCode' => 500, 'message' => 'boom']);
        $this->storeIntegration(true, ['success_action' => 'redirect', 'redirect_page_id' => 42]);
        $this->mockPage('publish', 'https://example.com/thanks/');

        $outcome = $this->pipeline()->submit('contact', $this->valid());

        $this->assertSame(502, $outcome->status());
        $this->assertArrayNotHasKey('redirect', $outcome->body());
    }

    /**
     * The site owner has to be able to find out why the redirect stopped
     * happening, and the debug log is where that goes.
     */
    public function testABrokenRedirectTargetIsWrittenToTheDebugLog(): void
    {
        $this->mockPost(200, ['responseCode' => 200, 'content' => ['submissionID' => '1']]);
        $this->storeIntegration(true, ['success_action' => 'redirect', 'redirect_page_id' => 42]);
        $this->mockPage('draft', 'https://example.com/thanks/');

        $this->options[Settings::OPTION] = ['debug_logging' => true];

        $lines = [];

        Functions\when('JotformBridge\Support\error_log')->alias(
            function (string $line) use (&$lines): bool {
                $lines[] = $line;

                return true;
            }
        );

        $client = new JotformClient('test-api-key', 'https://api.jotform.com');

        $outcome = (new SubmissionPipeline(
            new IntegrationRepository(),
            new SchemaRepository($client),
            $client,
            null,
            null,
            null,
            new Logger(new Settings())
        ))->submit('contact', $this->valid());

        $this->assertSame(200, $outcome->status());
        $this->assertArrayNotHasKey('redirect', $outcome->body());

        $log = implode("\n", $lines);

        $this->assertStringContainsString('redirect target could not be used', $log);
        $this->assertStringContainsString('unpublished', $log);
        $this->assertStringNotContainsString('test-api-key', $log);
    }

    public function testADeeplyNestedPayloadIsRefusedAsTooLarge(): void
    {
        $outcome = $this->pipeline()->submit(
            'contact',
            ['message' => [[[array_fill(0, 100, str_repeat('x', 1000))]]]]
        );

        $this->assertSame(422, $outcome->status());
        $this->assertArrayHasKey(ValidationResult::FORM_KEY, $outcome->errors());
        $this->assertSame([], $this->requests);
    }

    /**
     * Brain Monkey leaves apply_filters() as a pass-through, so the shipped
     * provider is attached by hand — the pipeline is what is under test here,
     * not WordPress's hook system.
     */
    private function routeSpamFilterThroughHoneypot(): void
    {
        $honeypot = new Honeypot();

        Functions\when('apply_filters')->alias(
            static function (string $hook, $value, ...$args) use ($honeypot) {
                if ($hook !== 'jotform_bridge_spam_check') {
                    return $value;
                }

                return $honeypot->check($value, (string) $args[0], $args[1], $args[2]);
            }
        );
    }

    public function dropSchema(): void
    {
        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);
    }

    private function pipeline(): SubmissionPipeline
    {
        $client = new JotformClient('test-api-key', 'https://api.jotform.com');

        return new SubmissionPipeline(
            new IntegrationRepository(),
            new SchemaRepository($client),
            $client
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    public function mockPost(int $status, array $body): void
    {
        $response = $this->httpResponse($status, $body);

        Functions\when('wp_remote_post')->alias(
            function (string $url, array $args) use ($response) {
                $this->requests[] = ['url' => $url, 'args' => $args];

                return $response;
            }
        );
    }

    /**
     * @param array<string, mixed> $extra Redirect settings, when the test needs them.
     */
    public function storeIntegration(bool $active, array $extra = []): void
    {
        $this->options[IntegrationRepository::OPTION] = [
            'contact' => array_merge(
                [
                    'slug'     => 'contact',
                    'name'     => 'Contact',
                    'form_id'  => self::FORM_ID,
                    'mode'     => 'custom',
                    'template' => 'contact',
                    'active'   => $active,
                ],
                $extra
            ),
        ];
    }

    /**
     * The WordPress side of a redirect target: one page, one status, one
     * permalink. An empty status stands for a page that no longer exists.
     */
    private function mockPage(string $status, string $permalink): void
    {
        Functions\when('home_url')->alias(
            static fn(string $path = ''): string => 'https://example.com' . $path
        );
        Functions\when('get_post_status')->justReturn($status === '' ? false : $status);
        Functions\when('get_permalink')->justReturn($permalink);
        Functions\when('wp_validate_redirect')->alias(
            static function (string $location, string $default = ''): string {
                if (str_starts_with($location, '//')) {
                    $location = 'http:' . $location;
                }

                $host = parse_url($location, PHP_URL_HOST);

                return is_string($host) && strcasecmp($host, 'example.com') === 0 ? $location : $default;
            }
        );
    }

    private function cacheSchema(): void
    {
        $schema = (new SchemaBuilder())->build(
            self::FORM_ID,
            array_values($this->fixture('form-questions')['content'])
        );

        $this->options[SchemaRepository::optionKey(self::FORM_ID)] = $schema->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function valid(): array
    {
        return [
            'full_name.first'   => 'Jane',
            'full_name.last'    => 'Doe',
            'email'             => 'jane@example.com',
            'message'           => 'Hello there.',
            'preferred_contact' => 'E-mail',
            'topics_of'         => ['Pricing', 'Support'],
        ];
    }
}
