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

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = [];

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
    }

    private function client(): JotformClient
    {
        return new JotformClient('secret-key', 'https://api.jotform.com');
    }

    /**
     * The documented shape of GET /form/{formID}, taken from a live read-only
     * call against the test account rather than invented.
     *
     * @return array<string, mixed>
     */
    private function formResponse(string $id = '240000000000001', string $status = 'ENABLED'): array
    {
        return $this->httpResponse(200, [
            'responseCode' => 200,
            'content'      => [
                'id'              => $id,
                'username'        => 'example',
                'title'           => 'Contact Form',
                'height'          => '539',
                'status'          => $status,
                'created_at'      => '2026-02-10 13:00:13',
                'updated_at'      => '2026-02-11 08:45:02',
                'last_submission' => '2026-02-11 09:00:00',
                'new'             => '1',
                'count'           => '1',
                'type'            => 'LEGACY',
                'favorite'        => '0',
                'archived'        => '0',
                'url'             => 'https://form.jotform.com/' . $id,
            ],
        ]);
    }

    public function testNothingIsStoredBeforeTheFirstConnect(): void
    {
        $repository = new FormRepository($this->client());

        $this->assertSame([], $repository->all());
        $this->assertFalse($repository->has('240000000000001'));
        $this->assertNull($repository->get('240000000000001'));
        $this->assertSame('', $repository->title('240000000000001'));
    }

    public function testConnectStoresTheFormItResolved(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->formResponse());

        $repository = new FormRepository($this->client());
        $result     = $repository->connect('240000000000001');

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($repository->has('240000000000001'));
        $this->assertSame('Contact Form', $repository->title('240000000000001'));

        $stored = $repository->get('240000000000001');

        $this->assertSame('240000000000001', $stored['id']);
        $this->assertSame('ENABLED', $stored['status']);
        $this->assertSame('2026-02-11 08:45:02', $stored['updated']);
        $this->assertGreaterThan(0, $stored['connected_at']);
    }

    /**
     * The whole point of the change: connecting one form must not go looking
     * for any others, and must ask about the form by ID.
     */
    public function testConnectAsksForOneFormByIdAndNotForTheAccountList(): void
    {
        $urls = [];

        Functions\when('wp_remote_get')->alias(
            function (string $url) use (&$urls) {
                $urls[] = $url;

                return $this->formResponse();
            }
        );

        (new FormRepository($this->client()))->connect('240000000000001');

        $this->assertSame(['https://api.jotform.com/form/240000000000001'], $urls);
    }

    public function testReadingTheStoreDoesNotCallJotformAgain(): void
    {
        $calls = 0;

        Functions\when('wp_remote_get')->alias(
            function () use (&$calls) {
                $calls++;

                return $this->formResponse();
            }
        );

        $repository = new FormRepository($this->client());
        $repository->connect('240000000000001');
        $repository->all();
        $repository->title('240000000000001');
        $repository->has('240000000000001');

        $this->assertSame(1, $calls);
    }

    /**
     * A stored record is a label, and losing every integration's label because
     * a network call timed out would be a worse outcome than a stale title.
     */
    public function testFailedConnectKeepsWhatWasAlreadyStored(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->formResponse());

        $repository = new FormRepository($this->client());
        $repository->connect('240000000000001');

        Functions\when('wp_remote_get')->justReturn(new WP_Error('http_request_failed', 'timeout'));

        $result = $repository->connect('240000000000001');

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Contact Form', $repository->title('240000000000001'));
    }

    /**
     * Jotform answers a wrong ID, another account's form and a bad API key with
     * the same 401, so nothing may be stored on any of them.
     */
    public function testAnUnauthorizedFormIsNotStored(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            $this->httpResponse(401, [
                'responseCode' => 401,
                'message'      => "You're not authorized to use (/form-id) ",
                'content'      => '',
                'info'         => 'https://api.jotform.com/docs#form-id',
            ])
        );

        $repository = new FormRepository($this->client());
        $result     = $repository->connect('999999999999999');

        $this->assertFalse($result->isSuccess());
        $this->assertFalse($repository->has('999999999999999'));
        $this->assertSame([], $repository->all());
    }

    public function testANonNumericIdIsRefusedWithoutAnyRequest(): void
    {
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \LogicException('A malformed form ID must not reach the API.');
            }
        );

        $repository = new FormRepository($this->client());

        foreach (['', ' ', 'abc', '12a', '../7'] as $bad) {
            $this->assertFalse($repository->connect($bad)->isSuccess(), $bad);
        }

        $this->assertSame([], $repository->all());
    }

    public function testSeveralFormsAreKeptSideBySide(): void
    {
        $repository = new FormRepository($this->client());

        Functions\when('wp_remote_get')->justReturn($this->formResponse('240000000000001'));
        $repository->connect('240000000000001');

        Functions\when('wp_remote_get')->justReturn($this->formResponse('240000000000002'));
        $repository->connect('240000000000002');

        $this->assertCount(2, $repository->all());
        $this->assertTrue($repository->has('240000000000001'));
        $this->assertTrue($repository->has('240000000000002'));
    }

    /**
     * PHP coerces a numeric string array key into an integer, so a record read
     * back out of the option has an int key. Reading has to survive that.
     */
    public function testAStoredRecordSurvivesTheNumericKeyCoercion(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->formResponse());

        $repository = new FormRepository($this->client());
        $repository->connect('240000000000001');

        // A second instance reads the option rather than the memo, exactly as a
        // later request would.
        $reader = new FormRepository($this->client());

        $this->assertSame('Contact Form', $reader->title('240000000000001'));
        $this->assertSame(['240000000000001'], array_map('strval', array_keys($reader->all())));
    }

    public function testATrashedFormIsStoredAndReportedAsDeleted(): void
    {
        Functions\when('wp_remote_get')->justReturn(
            $this->formResponse('240000000000001', FormRepository::STATUS_DELETED)
        );

        $repository = new FormRepository($this->client());
        $repository->connect('240000000000001');

        $this->assertTrue($repository->has('240000000000001'));
        $this->assertTrue($repository->isDeleted('240000000000001'));
    }

    public function testForgetDropsOneRecordAndLeavesTheRest(): void
    {
        $repository = new FormRepository($this->client());

        Functions\when('wp_remote_get')->justReturn($this->formResponse('240000000000001'));
        $repository->connect('240000000000001');

        Functions\when('wp_remote_get')->justReturn($this->formResponse('240000000000002'));
        $repository->connect('240000000000002');

        $repository->forget('240000000000001');

        $this->assertFalse($repository->has('240000000000001'));
        $this->assertTrue($repository->has('240000000000002'));
    }

    public function testForgettingTheLastRecordRemovesTheOptionEntirely(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->formResponse());

        $repository = new FormRepository($this->client());
        $repository->connect('240000000000001');
        $repository->forget('240000000000001');

        $this->assertArrayNotHasKey(FormRepository::OPTION, $this->options);
    }

    public function testFlushClearsTheStore(): void
    {
        Functions\when('wp_remote_get')->justReturn($this->formResponse());

        $repository = new FormRepository($this->client());
        $repository->connect('240000000000001');
        $repository->flush();

        $this->assertSame([], $repository->all());
        $this->assertArrayNotHasKey(FormRepository::OPTION, $this->options);
    }

    /**
     * An account list left by an older version lives under a different option
     * name on purpose: both shapes are integer-keyed arrays of arrays once PHP
     * has coerced the keys, so no honest check could tell them apart.
     */
    public function testAnOldAccountListIsNotReadAsConnectedForms(): void
    {
        $this->options[FormRepository::LEGACY_OPTION] = [
            ['id' => '240000000000001', 'title' => 'Contact'],
            ['id' => '240000000000002', 'title' => 'Careers'],
        ];

        $repository = new FormRepository($this->client());

        $this->assertSame([], $repository->all());
        $this->assertFalse($repository->has('240000000000001'));
    }

    public function testMalformedStoredRecordsAreIgnoredRatherThanTrusted(): void
    {
        $this->options[FormRepository::OPTION] = [
            '240000000000001' => ['title' => 'Contact', 'status' => 'ENABLED'],
            'not-an-id'       => ['title' => 'Nonsense'],
            '240000000000003' => 'not an array',
        ];

        $repository = new FormRepository($this->client());

        $this->assertSame(['240000000000001'], array_map('strval', array_keys($repository->all())));
        $this->assertSame('Contact', $repository->title('240000000000001'));
    }
}
