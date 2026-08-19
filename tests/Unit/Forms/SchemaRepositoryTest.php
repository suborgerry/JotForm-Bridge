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

    public function testNothingIsStoredBeforeTheFirstSync(): void
    {
        $repository = $this->repository();

        $this->assertFalse($repository->isSynced(self::FORM_ID));
        $this->assertNull($repository->stored(self::FORM_ID));
        $this->assertSame(0, $repository->meta(self::FORM_ID)['synced_at']);
    }

    public function testSyncNormalizesAndStoresTheSchema(): void
    {
        $this->serveQuestions();

        $result = $this->repository()->sync(self::FORM_ID);

        $this->assertTrue($result->isSuccess());

        $schema = $result->data()['schema'];

        $this->assertInstanceOf(FormSchema::class, $schema);
        $this->assertSame(self::FORM_ID, $schema->formId());
        $this->assertTrue($schema->has('email'));
        $this->assertFalse($result->data()['changed'], 'The first fetch is not a change.');
    }

    public function testStoredSchemaSurvivesTheOptionRoundTrip(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);

        $stored = $repository->stored(self::FORM_ID);

        $this->assertInstanceOf(FormSchema::class, $stored);
        $this->assertSame('4', $stored->qidFor('email'));
        $this->assertSame(
            ['first', 'last'],
            array_keys($stored->field('full_name')['children'])
        );
    }

    /**
     * The whole point of the storage rule: reading never becomes a request.
     */
    public function testGetNeverContactsJotform(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();

        $repository->get(self::FORM_ID);
        $repository->get(self::FORM_ID);

        $this->assertSame(0, $this->httpCalls);
    }

    public function testGetFailsWithADistinctCodeUntilTheFormIsSynced(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $result     = $repository->get(self::FORM_ID);

        $this->assertFalse($result->isSuccess());
        $this->assertSame(SchemaRepository::ERROR_NOT_SYNCED, $result->errorCode());

        $repository->sync(self::FORM_ID);

        $this->assertTrue($repository->get(self::FORM_ID)->isSuccess());
        $this->assertSame(1, $this->httpCalls, 'Only the explicit sync may fetch.');
    }

    public function testEverySyncCallsJotform(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();

        $repository->sync(self::FORM_ID);
        $repository->sync(self::FORM_ID);

        $this->assertSame(2, $this->httpCalls);
    }

    public function testStoredSchemaIsWrittenWithoutAnExpiry(): void
    {
        $this->serveQuestions();

        $this->repository()->sync(self::FORM_ID);

        $this->assertArrayHasKey(SchemaRepository::optionKey(self::FORM_ID), $this->options);
        $this->assertSame([], $this->transients, 'A synced schema must not live in a transient.');
    }

    public function testTheSyncingPluginVersionIsRecorded(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);

        $this->assertSame(JOTFORM_BRIDGE_VERSION, $repository->meta(self::FORM_ID)['version']);
        $this->assertFalse($repository->isStale(self::FORM_ID));
    }

    public function testASchemaSyncedByAnotherVersionIsReportedAsStale(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);

        $meta = $this->options[SchemaRepository::META_OPTION];
        $meta[self::FORM_ID]['version'] = '0.0.1';
        $this->options[SchemaRepository::META_OPTION] = $meta;

        $this->assertTrue($repository->isStale(self::FORM_ID));
        $this->assertTrue($repository->isSynced(self::FORM_ID), 'A stale schema is still used.');
    }

    public function testSyncReportsAChangedFingerprint(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);

        $before = $repository->meta(self::FORM_ID)['fingerprint'];

        $modified = $this->fixture('form-questions');
        $modified['content']['7']['required'] = 'Yes';
        $this->serveQuestions($modified);

        $result = $repository->sync(self::FORM_ID);

        $this->assertTrue($result->data()['changed']);
        $this->assertNotSame($before, $repository->meta(self::FORM_ID)['fingerprint']);
    }

    public function testUnchangedSchemaDoesNotReportAChange(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);
        $result = $repository->sync(self::FORM_ID);

        $this->assertFalse($result->data()['changed']);
    }

    public function testFailedSyncKeepsTheStoredSchemaAndRecordsTheError(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);

        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_request_failed', 'timeout'));

        $result = $repository->sync(self::FORM_ID);

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($repository->isSynced(self::FORM_ID), 'A failed sync must not drop the stored schema.');
        $this->assertNotSame('', $repository->meta(self::FORM_ID)['error']);
    }

    public function testForgetInvalidatesOneFormOnly(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);
        $repository->sync('240000000000002');

        $repository->forget(self::FORM_ID);

        $this->assertFalse($repository->isSynced(self::FORM_ID));
        $this->assertTrue($repository->isSynced('240000000000002'));
    }

    public function testFlushAllClearsEveryStoredSchema(): void
    {
        $this->serveQuestions();

        $repository = $this->repository();
        $repository->sync(self::FORM_ID);
        $repository->sync('240000000000002');

        SchemaRepository::flushAll();

        $this->assertFalse($repository->isSynced(self::FORM_ID));
        $this->assertFalse($repository->isSynced('240000000000002'));
        $this->assertSame(0, $repository->meta(self::FORM_ID)['synced_at']);
    }

    public function testANonNumericFormIdIsRejectedWithoutAnyRequest(): void
    {
        $this->serveQuestions();

        $result = $this->repository()->sync('../../evil');

        $this->assertFalse($result->isSuccess());
        $this->assertSame(0, $this->httpCalls);
    }

    public function testTheNormalizedSchemaFilterIsApplied(): void
    {
        $this->serveQuestions();

        Filters\expectApplied('jotform_bridge_normalized_schema')
            ->once()
            ->with(\Mockery::type(FormSchema::class), self::FORM_ID);

        $result = $this->repository()->sync(self::FORM_ID);

        $this->assertTrue($result->isSuccess());
    }

    public function testTheOptionKeyIsNamespacedPerForm(): void
    {
        $this->assertSame(
            'jotform_bridge_schema_' . self::FORM_ID,
            SchemaRepository::optionKey(self::FORM_ID)
        );
    }
}
