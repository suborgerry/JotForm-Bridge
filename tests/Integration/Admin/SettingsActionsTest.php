<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Admin;

use JotformBridge\Admin\SettingsPage;
use JotformBridge\Api\ConnectionState;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Settings\Settings;
use JotformBridge\Tests\Integration\TestCase;

/**
 * The two admin-post actions on the Settings screen.
 *
 * Saving the settings is where one of the defects the integration suite was
 * built for actually happened — a stored value reset because the screen posted
 * one field fewer than the handler assumed. That class of mistake is invisible
 * to a test that calls Settings::save() with a hand-built array, and obvious to
 * one that posts what the screen posts.
 */
final class SettingsActionsTest extends TestCase
{
    public function testSavingStoresTheRegionAndReturnsWithANotice(): void
    {
        $this->actAsAdministrator();

        $this->postSettings(['region' => Settings::REGION_EU, 'debug_logging' => '1']);

        $redirect = $this->expectRedirect(fn() => do_action('admin_post_' . SettingsPage::ACTION_SAVE));

        $this->assertSame('jotform-bridge-settings', $redirect->arg('page'));
        $this->assertSame('saved', $redirect->arg('jfb_notice'));

        $settings = new Settings();

        $this->assertSame(Settings::REGION_EU, $settings->region());
        $this->assertSame('https://eu-api.jotform.com', $settings->baseUrl());
        $this->assertTrue($settings->debugEnabled());
    }

    public function testAnApiKeyPostedWithTheSettingsIsNeverStored(): void
    {
        $this->actAsAdministrator();

        $this->postSettings(
            [
                'region'  => Settings::REGION_STANDARD,
                // There is no field for this on the screen. Somebody posting
                // one anyway must not be able to put a key in the database.
                'api_key' => 'a-key-that-must-not-be-stored',
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . SettingsPage::ACTION_SAVE));

        $stored = get_option(Settings::OPTION);

        $this->assertIsArray($stored);
        $this->assertArrayNotHasKey('api_key', $stored);
        $this->assertStringNotContainsString('must-not-be-stored', (string) wp_json_encode($stored));
    }

    public function testSavingClearsTheConnectionStateButNotTheConnectedForms(): void
    {
        $this->actAsAdministrator();

        (new ConnectionState())->recordSuccess('example_account');
        $this->connectForm();

        $this->postSettings(['region' => Settings::REGION_EU]);

        $this->expectRedirect(fn() => do_action('admin_post_' . SettingsPage::ACTION_SAVE));

        $this->assertSame(
            ConnectionState::STATUS_UNKNOWN,
            (new ConnectionState())->get()['status'],
            'A region change can point the plugin at another account.'
        );
        $this->assertTrue(
            (new FormRepository($this->plugin()->client()))->has('240000000000001'),
            'The form records are labels for IDs the integrations already hold; a settings save must not wipe them.'
        );
    }

    public function testSavingWithoutANonceChangesNothing(): void
    {
        $this->actAsAdministrator();

        $_POST    = ['jotform_bridge' => ['region' => Settings::REGION_EU]];
        $_REQUEST = $_POST;

        // wp_create_nonce() is deliberately not called.
        unset($_POST['_wpnonce'], $_REQUEST['_wpnonce']);

        $this->expectWpDie(fn() => do_action('admin_post_' . SettingsPage::ACTION_SAVE));

        $this->assertSame(Settings::REGION_STANDARD, (new Settings())->region());
    }

    public function testSavingWithoutTheCapabilityIsRefused(): void
    {
        $this->actAsSubscriber();

        $this->postSettings(['region' => Settings::REGION_EU]);

        $died = $this->expectWpDie(fn() => do_action('admin_post_' . SettingsPage::ACTION_SAVE));

        $this->assertSame(403, $died->status());
        $this->assertSame(Settings::REGION_STANDARD, (new Settings())->region());
    }

    public function testCheckConnectionAsksForTheAccountAndRecordsIt(): void
    {
        $this->actAsAdministrator();

        $asked = [];

        $this->mockHttp(
            function (string $url) use (&$asked) {
                $asked[] = $url;

                return $this->httpResponse(
                    200,
                    [
                        'responseCode' => 200,
                        'message'      => 'success',
                        'content'      => ['username' => 'example_account', 'email' => 'owner@example.test'],
                    ]
                );
            }
        );

        $this->submitForm(['_wpnonce' => wp_create_nonce(SettingsPage::ACTION_CHECK)]);

        $redirect = $this->expectRedirect(fn() => do_action('admin_post_' . SettingsPage::ACTION_CHECK));

        $this->assertSame('connected', $redirect->arg('jfb_notice'));
        $this->assertSame(['https://api.jotform.com/user'], $asked, 'GET /user names no form, which is the point of it.');

        $state = (new ConnectionState())->get();

        $this->assertSame(ConnectionState::STATUS_CONNECTED, $state['status']);
        $this->assertSame('example_account', $state['account']);
    }

    public function testAFailedCheckIsRecordedAsAFailure(): void
    {
        $this->actAsAdministrator();

        $this->mockHttp(
            fn(): array => $this->httpResponse(401, ['responseCode' => 401, 'message' => 'Invalid API key'])
        );

        $this->submitForm(['_wpnonce' => wp_create_nonce(SettingsPage::ACTION_CHECK)]);

        $redirect = $this->expectRedirect(fn() => do_action('admin_post_' . SettingsPage::ACTION_CHECK));

        $this->assertSame('connection_failed', $redirect->arg('jfb_notice'));
        $this->assertSame(ConnectionState::STATUS_FAILED, (new ConnectionState())->get()['status']);
    }

    public function testCheckConnectionWithoutANonceDoesNotContactJotform(): void
    {
        $this->actAsAdministrator();

        // Nothing is mocked, so a request would fail this test rather than
        // quietly succeed.
        $this->expectWpDie(fn() => do_action('admin_post_' . SettingsPage::ACTION_CHECK));

        $this->assertSame(ConnectionState::STATUS_UNKNOWN, (new ConnectionState())->get()['status']);
    }

    public function testCleanLogsClearsEntriesAndKeepsLoggingEnabled(): void
    {
        $this->actAsAdministrator();
        update_option(Settings::OPTION, ['debug_logging' => true]);
        $logger = new \JotformBridge\Support\Logger(new Settings());
        $logger->error('Example diagnostic');

        try {
            $this->submitForm(['_wpnonce' => wp_create_nonce(SettingsPage::ACTION_CLEAN_LOGS)]);
            $redirect = $this->expectRedirect(fn() => do_action('admin_post_' . SettingsPage::ACTION_CLEAN_LOGS));
            $this->assertSame('logs_cleaned', $redirect->arg('jfb_notice'));
            $this->assertSame("<?php exit; ?>\n", file_get_contents($logger->path()));
            $this->assertTrue((new Settings())->debugEnabled());
        } finally {
            @unlink($logger->path());
        }
    }

    public function testCleanLogsWithoutNonceOrCapabilityPreservesEntries(): void
    {
        $this->actAsAdministrator();
        $logger = new \JotformBridge\Support\Logger(new Settings());
        file_put_contents($logger->path(), "<?php exit; ?>\nKeep this entry\n");

        try {
            $before = file_get_contents($logger->path());
            $this->expectWpDie(fn() => do_action('admin_post_' . SettingsPage::ACTION_CLEAN_LOGS));
            $this->assertSame($before, file_get_contents($logger->path()));

            $this->actAsSubscriber();
            $this->submitForm(['_wpnonce' => wp_create_nonce(SettingsPage::ACTION_CLEAN_LOGS)]);
            $died = $this->expectWpDie(fn() => do_action('admin_post_' . SettingsPage::ACTION_CLEAN_LOGS));
            $this->assertSame(403, $died->status());
            $this->assertSame($before, file_get_contents($logger->path()));
        } finally {
            @unlink($logger->path());
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function postSettings(array $values): void
    {
        $this->submitForm(
            [
                '_wpnonce'       => wp_create_nonce(SettingsPage::ACTION_SAVE),
                'jotform_bridge' => $values,
            ]
        );
    }

    private function connectForm(): void
    {
        $form = $this->fixture('form');

        $this->mockHttp(
            fn(string $url) => strpos($url, '/form/240000000000001') === false
                ? null
                : $this->httpResponse(200, $form)
        );

        $this->plugin()->forms()->connect('240000000000001');
    }
}
