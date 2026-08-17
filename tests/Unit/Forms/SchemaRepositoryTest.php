<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Forms;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Tests\TestCase;
use WP_Error;

final class SchemaRepositoryTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    private int $httpCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options    = [];
        $this->transients = [];
        $this->httpCalls  = 0;

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('delete_option')->alias(
            function (string $name): bool {
                unset($this->options[$name]);

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
        Functions\when('delete_transient')->alias(
            function (string $name): bool {
                unset($this->transients[$name]);

                return true;
            }
        );
    }

    private function repository(): SchemaRepository
    {
        return new SchemaRepository(new JotformClient('secret-key', 'https://api.jotform.com'));
    }

    /**
     * Serves the questions fixture and counts how often Jotform is contacted.
     *
     * @param array<string, mixed>|null $payload Defaults to the fixture.
     */
    private function serveQuestions(?array $payload = null): void
    {
        $response = $this->httpResponse(200, $payload ?? $this->fixture('form-questions'));

        Functions\when('wp_remote_get')->alias(
            function () use (&$response) {
                $this->httpCalls++;

                return $response;
            }
        );
    }

    public function testNothingIsCachedBeforeTheFirstFetch(): void
    {
        $repository = $this->repository();

        $this->assertFalse($repository->isCached(self::FORM_ID));
        $this->assertNull($repository->cached(self::FORM_ID));
        $this->assertSame(0, $repository->meta(self::FORM_ID)['fetched_at']);
    }

    public function testRefreshNormalizesAndCachesTheSchema(): void
    {
        $this->serveQuestions();

        $result = $this->repository()->refresh(self::FORM_ID);

        $this->assertTrue($result->isSuccess());

        $schema = $result->data()['schema'];

        $this->assertInstanceOf(FormSchema::class, $schema);
        $this->assertSame(self::FORM_ID, $schema->formId());
        $this->assertTrue($schema->has('email'));
        $this->assertFalse($result->data()['changed'], 'The first fetch is not a change.');
    }

    public function testCachedSchemaSurvivesTheTransientRoundTrip(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->refresh(self::FORM_ID);

        $cached = $repository->cached(self::FORM_ID);

        $this->assertInstanceOf(FormSchema::class, $cached);
        $this->assertSame('4', $cached->qidFor('email'));
        $this->assertSame(
            ['first', 'last'],
            array_keys($cached->field('full_name')['children'])
        );
    }

    public function testGetUsesTheCacheInsteadOfCallingJotformAgain(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();

        $repository->get(self::FORM_ID);
        $repository->get(self::FORM_ID);
        $repository->get(self::FORM_ID);

        $this->assertSame(1, $this->httpCalls);
    }

    public function testForceRefreshAlwaysCallsJotform(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();

        $repository->get(self::FORM_ID);
        $repository->refresh(self::FORM_ID);

        $this->assertSame(2, $this->httpCalls);
    }

    public function testRefreshReportsAChangedFingerprint(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->refresh(self::FORM_ID);

        $before = $repository->meta(self::FORM_ID)['fingerprint'];

        $modified = $this->fixture('form-questions');
        $modified['content']['7']['required'] = 'Yes';
        $this->serveQuestions($modified);

        $result = $repository->refresh(self::FORM_ID);

        $this->assertTrue($result->data()['changed']);
        $this->assertNotSame($before, $repository->meta(self::FORM_ID)['fingerprint']);
    }

    public function testUnchangedSchemaDoesNotReportAChange(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->refresh(self::FORM_ID);
        $result = $repository->refresh(self::FORM_ID);

        $this->assertFalse($result->data()['changed']);
    }

    public function testFailedRefreshKeepsTheCacheAndRecordsTheError(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->refresh(self::FORM_ID);

        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_request_failed', 'timeout'));

        $result = $repository->refresh(self::FORM_ID);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($repository->isCached(self::FORM_ID), 'A failed refresh must not drop the cache.');
        $this->assertNotSame('', $repository->meta(self::FORM_ID)['error']);
    }

    public function testForgetInvalidatesOneFormOnly(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->refresh(self::FORM_ID);
        $repository->refresh('240000000000002');

        $repository->forget(self::FORM_ID);

        $this->assertFalse($repository->isCached(self::FORM_ID));
        $this->assertTrue($repository->isCached('240000000000002'));
    }

    public function testFlushAllClearsEveryCachedSchema(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->refresh(self::FORM_ID);
        $repository->refresh('240000000000002');

        SchemaRepository::flushAll();

        $this->assertFalse($repository->isCached(self::FORM_ID));
        $this->assertFalse($repository->isCached('240000000000002'));
        $this->assertSame(0, $repository->meta(self::FORM_ID)['fetched_at']);
    }

    public function testANonNumericFormIdIsRejectedWithoutAnyRequest(): void
    {
        $this->serveQuestions();

        $result = $this->repository()->refresh('../../evil');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(0, $this->httpCalls);
    }

    public function testTheNormalizedSchemaFilterIsApplied(): void
    {
        $this->serveQuestions();

        Filters\expectApplied('jotform_bridge_normalized_schema')
            ->once()
            ->with(\Mockery::type(FormSchema::class), self::FORM_ID);

        $result = $this->repository()->refresh(self::FORM_ID);

        $this->assertTrue($result->isSuccess());
    }

    public function testTheTransientKeyIsNamespacedPerForm(): void
    {
        $this->assertSame(
            'jotform_bridge_schema_' . self::FORM_ID,
            SchemaRepository::transientKey(self::FORM_ID)
        );
    }
}
