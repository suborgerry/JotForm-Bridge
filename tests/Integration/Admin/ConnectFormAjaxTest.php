<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Admin;

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Tests\Integration\TestCase;

/**
 * "Connect form", the plugin's one asynchronous admin action.
 *
 * It was added with a capability check, a nonce check and an upstream call,
 * and verified once by hand. Nothing automated touched it until this file: it
 * is the gap the integration suite was built to close.
 *
 * Fired through `do_action( 'wp_ajax_...' )`, which is how admin-ajax.php
 * reaches it.
 */
final class ConnectFormAjaxTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    public function testConnectingResolvesTheTitleAndStoresTheRecord(): void
    {
        $this->actAsAdministrator();
        $this->answerWith(200, $this->fixture('form'));

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertTrue($answer['success']);
        $this->assertSame('Contact Form', $answer['data']['title']);
        $this->assertSame('ENABLED', $answer['data']['status']);
        $this->assertStringContainsString('Contact Form', $answer['data']['message']);

        $record = (new FormRepository($this->plugin()->client()))->get(self::FORM_ID);

        $this->assertNotNull($record, 'The connected form record is what labels the ID in the admin.');
        $this->assertSame('Contact Form', $record['title']);
    }

    public function testAForgedNonceIsRefusedAndNothingIsAsked(): void
    {
        $this->actAsAdministrator();
        $this->doingAjax();

        // No HTTP is mocked: a request would fail this test loudly.
        $this->submitForm(['form_id' => self::FORM_ID, '_wpnonce' => 'not-a-nonce']);

        $died = $this->expectWpDie(fn() => do_action('wp_ajax_' . IntegrationsPage::ACTION_CONNECT));

        $this->assertSame(403, $died->status());
        $this->assertFalse((new FormRepository($this->plugin()->client()))->has(self::FORM_ID));
    }

    public function testNoNonceAtAllIsRefused(): void
    {
        $this->actAsAdministrator();
        $this->doingAjax();

        $this->submitForm(['form_id' => self::FORM_ID]);

        $died = $this->expectWpDie(fn() => do_action('wp_ajax_' . IntegrationsPage::ACTION_CONNECT));

        $this->assertSame(403, $died->status());
    }

    public function testAUserWithoutTheCapabilityIsRefusedEvenWithAValidNonce(): void
    {
        // The nonce is minted by the administrator, then used by somebody else.
        $this->actAsAdministrator();
        $nonce = wp_create_nonce(IntegrationsPage::ACTION_CONNECT);

        $this->actAsSubscriber();

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, $nonce));

        $this->assertFalse($answer['success']);
        $this->assertStringContainsString('not allowed', $answer['data']['message']);
        $this->assertFalse((new FormRepository($this->plugin()->client()))->has(self::FORM_ID));
    }

    public function testAFormIdThatIsNotDigitsIsRefusedBeforeJotformIsAsked(): void
    {
        $this->actAsAdministrator();

        $answer = $this->ajax(
            fn() => $this->connect('form.jotform.com/2622', wp_create_nonce(IntegrationsPage::ACTION_CONNECT))
        );

        $this->assertFalse($answer['success']);
        $this->assertStringContainsString('digits only', $answer['data']['message']);
    }

    public function testJotformsAmbiguous401IsPassedOnWithoutBeingExplainedAway(): void
    {
        $this->actAsAdministrator();
        $this->answerWith(
            401,
            ['responseCode' => 401, 'message' => "You're not authorized to use (/form-id)"]
        );

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertFalse($answer['success']);
        $this->assertStringContainsString('another account', $answer['data']['hint']);
        $this->assertStringContainsString('Check Connection', $answer['data']['hint']);
    }

    public function testAFailedLookupLeavesAPreviouslyStoredRecordAlone(): void
    {
        $this->actAsAdministrator();

        $this->answerWith(200, $this->fixture('form'));
        $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $stored = get_option(FormRepository::OPTION);

        // A second attempt, this time refused upstream.
        $this->answerWith(500, ['responseCode' => 500, 'message' => 'Internal error']);
        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertFalse($answer['success']);
        $this->assertSame(
            $stored,
            get_option(FormRepository::OPTION),
            'A failed Connect form must not blank the label an integration already had.'
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function answerWith(int $status, array $body): void
    {
        $this->mockHttp(
            fn(string $url) => strpos($url, '/form/' . self::FORM_ID) === false
                ? null
                : $this->httpResponse($status, $body)
        );
    }

    private function connect(string $formId, string $nonce): void
    {
        // Through $_REQUEST as well as $_POST: check_ajax_referer() reads the
        // nonce out of $_REQUEST, and a test that only set $_POST would be
        // passing because the nonce was missing rather than because the
        // handler did its job.
        $this->submitForm(['form_id' => $formId, '_wpnonce' => $nonce]);

        do_action('wp_ajax_' . IntegrationsPage::ACTION_CONNECT);
    }
}
