<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Admin;

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
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
        $this->answerWith(200, $this->fixture('form'), $this->fixture('form-questions'));

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertTrue($answer['success']);
        $this->assertSame('Contact Form', $answer['data']['title']);
        $this->assertSame('ENABLED', $answer['data']['status']);
        $this->assertStringContainsString('Contact Form', $answer['data']['message']);

        $record = (new FormRepository($this->plugin()->client()))->get(self::FORM_ID);

        $this->assertNotNull($record, 'The connected form record is what labels the ID in the admin.');
        $this->assertSame('Contact Form', $record['title']);
    }

    /**
     * The dead end this button was changed to remove: the editor of an
     * integration that does not exist yet has no Sync Schema button, so a form
     * connected there could not be given a definition at all.
     */
    public function testConnectingAlsoLoadsTheSchemaOfAFormThatHasNone(): void
    {
        $this->actAsAdministrator();
        $this->answerWith(200, $this->fixture('form'), $this->fixture('form-questions'));

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertTrue($answer['success']);
        $this->assertSame('ok', $answer['data']['state']);
        $this->assertStringContainsString('definition was loaded', $answer['data']['hint']);
        $this->assertStringNotContainsString(
            'Sync Schema',
            $answer['data']['hint'],
            'The reply must not send the administrator to a button the Add Integration screen does not render.'
        );

        $this->assertTrue(
            $this->plugin()->schemas()->isSynced(self::FORM_ID),
            'A connected form has to be renderable without a second action.'
        );
    }

    /**
     * The other half of the rule: connecting refreshes a title, never a stored
     * schema. Replacing one is Sync Schema's job, and a definition a live site
     * renders from must not move because somebody re-checked a form ID.
     */
    public function testConnectingLeavesAStoredSchemaAlone(): void
    {
        $this->actAsAdministrator();
        $this->syncSchema(self::FORM_ID);

        $stored = get_option(SchemaRepository::optionKey(self::FORM_ID));

        // The questions endpoint is left unmocked on purpose: asking it again
        // would throw rather than quietly overwrite what is stored.
        $this->answerWith(200, $this->fixture('form'));

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertTrue($answer['success']);
        $this->assertSame('ok', $answer['data']['state']);
        $this->assertStringContainsString('Sync Schema', $answer['data']['hint']);
        $this->assertSame($stored, get_option(SchemaRepository::optionKey(self::FORM_ID)));
    }

    /**
     * A definition that will not load is a warning, not a refusal: the form was
     * found, which is what the button was pressed to establish.
     */
    public function testAFormThatIsFoundButWhoseSchemaFailsIsStillConnected(): void
    {
        $this->actAsAdministrator();
        $this->mockHttp(
            function (string $url) {
                if (strpos($url, '/form/' . self::FORM_ID . '/questions') !== false) {
                    return $this->httpResponse(500, ['responseCode' => 500, 'message' => 'Internal error']);
                }

                return strpos($url, '/form/' . self::FORM_ID) === false
                    ? null
                    : $this->httpResponse(200, $this->fixture('form'));
            }
        );

        $answer = $this->ajax(fn() => $this->connect(self::FORM_ID, wp_create_nonce(IntegrationsPage::ACTION_CONNECT)));

        $this->assertTrue($answer['success'], 'The lookup succeeded; only the schema did not.');
        $this->assertSame('warning', $answer['data']['state']);
        $this->assertStringContainsString('could not be loaded', $answer['data']['hint']);

        $this->assertTrue(
            (new FormRepository($this->plugin()->client()))->has(self::FORM_ID),
            'The form record is what the successful half of the action produced.'
        );
        $this->assertFalse($this->plugin()->schemas()->isSynced(self::FORM_ID));
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

        $this->answerWith(200, $this->fixture('form'), $this->fixture('form-questions'));
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
     * Answers the two calls Connect form now makes, separately.
     *
     * `/form/{id}` and `/form/{id}/questions` share a prefix, so a responder
     * matching on the prefix alone would hand the form fixture back as the
     * question list — a green test built on an answer Jotform never gives.
     *
     * @param array<string, mixed>      $body      What `/form/{id}` answers.
     * @param array<string, mixed>|null $questions What `/form/{id}/questions`
     *                                             answers, or null to leave
     *                                             the schema call unmocked so
     *                                             that making it fails loudly.
     */
    private function answerWith(int $status, array $body, ?array $questions = null): void
    {
        $this->mockHttp(
            function (string $url) use ($status, $body, $questions) {
                if (strpos($url, '/form/' . self::FORM_ID . '/questions') !== false) {
                    return $questions === null ? null : $this->httpResponse(200, $questions);
                }

                return strpos($url, '/form/' . self::FORM_ID) === false
                    ? null
                    : $this->httpResponse($status, $body);
            }
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
