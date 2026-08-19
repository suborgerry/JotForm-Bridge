<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Forms;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Tests\TestCase;
use WP_Error;

final class FormRepositoryTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->options    = [];
        $this->transients = [];

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

    private function client(): JotformClient
    {
        return new JotformClient('secret-key', 'https://api.jotform.com');
    }

    public function testNothingIsCachedBeforeTheFirstRefresh(): void
    {
        $repository = new FormRepository($this->client());

        $this->assertFalse($repository->isSynced());
        $this->assertSame([], $repository->all());
        $this->assertSame(0, $repository->meta()['fetched_at']);
    }

    public function testRefreshStoresTheListAndMeta(): void
    {
        $response = $this->httpResponse(200, [
            'responseCode' => 200,
            'content'      => [
                ['id' => '1', 'title' => 'Contact', 'status' => 'ENABLED', 'updated_at' => '2026-02-11 08:45:02'],
                ['id' => '2', 'title' => 'Careers', 'status' => 'ENABLED', 'updated_at' => '2026-02-12 08:45:02'],
            ],
        ]);
        Functions\when('wp_remote_get')->justReturn($response);

        $repository = new FormRepository($this->client());
        $result     = $repository->refresh();

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($repository->isSynced());
        $this->assertCount(2, $repository->all());
        $this->assertSame(2, $repository->meta()['count']);
        $this->assertSame('', $repository->meta()['error']);
        $this->assertGreaterThan(0, $repository->meta()['fetched_at']);
    }

    public function testReadingTheCacheDoesNotCallJotformAgain(): void
    {
        $calls    = 0;
        $response = $this->httpResponse(200, [
            'responseCode' => 200,
            'content'      => [['id' => '1', 'title' => 'Contact', 'status' => 'ENABLED']],
        ]);

        Functions\when('wp_remote_get')->alias(
            static function () use (&$calls, $response) {
                $calls++;

                return $response;
            }
        );

        $repository = new FormRepository($this->client());
        $repository->refresh();
        $repository->all();
        $repository->all();

        $this->assertSame(1, $calls);
    }

    public function testFailedRefreshKeepsTheOldCacheAndRecordsTheError(): void
    {
        $ok = $this->httpResponse(200, [
            'responseCode' => 200,
            'content'      => [['id' => '1', 'title' => 'Contact', 'status' => 'ENABLED']],
        ]);
        Functions\when('wp_remote_get')->justReturn($ok);

        $repository = new FormRepository($this->client());
        $repository->refresh();

        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_request_failed', 'timeout'));

        $result = $repository->refresh();

        $this->assertFalse($result->isSuccess());
        $this->assertCount(1, $repository->all(), 'The previous list must survive a failed refresh.');
        $this->assertNotSame('', $repository->meta()['error']);
    }

    public function testFlushClearsCacheAndMeta(): void
    {
        $response = $this->httpResponse(200, [
            'responseCode' => 200,
            'content'      => [['id' => '1', 'title' => 'Contact', 'status' => 'ENABLED']],
        ]);
        Functions\when('wp_remote_get')->justReturn($response);

        $repository = new FormRepository($this->client());
        $repository->refresh();
        $repository->flush();

        $this->assertFalse($repository->isSynced());
        $this->assertSame(0, $repository->meta()['count']);
    }
}
