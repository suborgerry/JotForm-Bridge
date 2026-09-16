<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Admin;

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Forms\FormRepository;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\Integration;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Tests\Integration\TestCase;

/**
 * The admin-post actions behind the Integrations screen.
 *
 * Fired through `do_action( 'admin_post_...' )`, which is how WordPress
 * reaches them, so the capability check, the nonce check and the redirect are
 * all part of what is being tested. A unit test that called handleSave()
 * directly would prove the body of the method and nothing about the guard in
 * front of it — and the guard is the part a mistake would be expensive in.
 */
final class IntegrationActionsTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    /**
     * Where IntegrationsPage keeps its one-shot admin notice.
     */
    private const FLASH_PREFIX = 'jotform_bridge_notice_';

    public function testSavingPreservesLockedRulesAndIgnoresForgedChanges(): void
    {
        $this->actAsAdministrator();
        $this->syncSchema(self::FORM_ID);
        $rule = ['action' => 'show', 'target' => 'message', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'E-mail'];
        $this->createIntegration(['conditions' => [$rule]]);
        $input = ['name' => 'Updated contact', 'slug' => 'contact', 'form_id' => self::FORM_ID, 'mode' => 'auto'];
        foreach ([null, [], [array_merge($rule, ['value' => 'Phone'])], 'forged'] as $forged) {
            if ($forged !== null) { $input['conditions'] = $forged; }
            $this->submitForm(['_wpnonce' => wp_create_nonce(IntegrationsPage::ACTION_SAVE), 'original_slug' => 'contact', 'jotform_integration' => $input]);
            $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));
            $stored = (new IntegrationRepository())->get('contact');
            $this->assertSame([$rule], $stored->conditions());
            $this->assertSame('Updated contact', $stored->name());
        }
        $input['slug'] = 'renamed';
        $this->submitForm(['_wpnonce' => wp_create_nonce(IntegrationsPage::ACTION_SAVE), 'original_slug' => 'contact', 'jotform_integration' => $input]);
        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));
        $this->assertSame([$rule], (new IntegrationRepository())->get('renamed')->conditions());
        $this->assertNull((new IntegrationRepository())->get('contact'));
    }

    public function testNewIntegrationsIgnoreRulesSuppliedThroughTheAdmin(): void
    {
        $this->actAsAdministrator();
        $input = ['name' => 'Contact', 'slug' => 'contact', 'form_id' => self::FORM_ID, 'mode' => 'auto', 'conditions' => [
            ['action' => 'show', 'target' => 'message', 'source' => 'email', 'operator' => 'not_empty', 'value' => ''],
        ]];
        $this->submitForm(['_wpnonce' => wp_create_nonce(IntegrationsPage::ACTION_SAVE), 'jotform_integration' => $input]);
        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));
        $this->assertSame([], (new IntegrationRepository())->get('contact')->conditions());
    }

    public function testSavingStoresTheIntegrationAndReturnsToItsEditor(): void
    {
        $this->actAsAdministrator();

        $this->submitForm(
            [
                '_wpnonce'            => wp_create_nonce(IntegrationsPage::ACTION_SAVE),
                'jotform_integration' => [
                    'name'    => 'Contact form',
                    'slug'    => 'contact',
                    'form_id' => self::FORM_ID,
                    'mode'    => Integration::MODE_AUTO,
                ],
            ]
        );

        $redirect = $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));

        $this->assertSame('jotform-bridge', $redirect->arg('page'));
        $this->assertSame('edit', $redirect->arg('view'));
        $this->assertSame('contact', $redirect->arg('integration'));

        $stored = (new IntegrationRepository())->get('contact');

        $this->assertNotNull($stored, 'The integration did not reach the options table.');
        $this->assertSame('Contact form', $stored->name());
        $this->assertSame(self::FORM_ID, $stored->formId());
    }

    public function testSavingWarnsAboutAFormThatWasNeverConnectedOrSynced(): void
    {
        $userId = $this->actAsAdministrator();

        $this->submitForm(
            [
                '_wpnonce'            => wp_create_nonce(IntegrationsPage::ACTION_SAVE),
                'jotform_integration' => [
                    'name'    => 'Contact form',
                    'slug'    => 'contact',
                    'form_id' => self::FORM_ID,
                    'mode'    => Integration::MODE_AUTO,
                ],
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));

        $flash = get_transient(self::FLASH_PREFIX . $userId);

        $this->assertIsArray($flash);
        $this->assertSame('warning', $flash['type'], 'An unconnected, unsynced form is a warning, not a refusal.');
        $this->assertCount(2, $flash['messages']);

        $this->assertNotNull(
            (new IntegrationRepository())->get('contact'),
            'The warning must not stop the integration being stored.'
        );
    }

    public function testSavingWithoutANonceChangesNothing(): void
    {
        $this->actAsAdministrator();

        $this->submitForm(
            [
                'jotform_integration' => [
                    'name'    => 'Forged',
                    'slug'    => 'forged',
                    'form_id' => self::FORM_ID,
                ],
            ]
        );

        $this->expectWpDie(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));

        $this->assertNull((new IntegrationRepository())->get('forged'));
    }

    public function testSavingWithSomebodyElsesNonceChangesNothing(): void
    {
        $this->actAsAdministrator();

        $this->submitForm(
            [
                // A nonce this administrator really does hold — for a
                // different action.
                '_wpnonce'            => wp_create_nonce(IntegrationsPage::ACTION_DELETE),
                'jotform_integration' => ['name' => 'Forged', 'slug' => 'forged'],
            ]
        );

        $this->expectWpDie(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));

        $this->assertNull((new IntegrationRepository())->get('forged'));
    }

    public function testAUserWithoutTheCapabilityIsRefusedBeforeTheNonceIsEvenChecked(): void
    {
        $this->actAsSubscriber();

        $this->submitForm(
            [
                '_wpnonce'            => wp_create_nonce(IntegrationsPage::ACTION_SAVE),
                'jotform_integration' => ['name' => 'Contact', 'slug' => 'contact'],
            ]
        );

        $died = $this->expectWpDie(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));

        $this->assertSame(403, $died->status());
        $this->assertNull((new IntegrationRepository())->get('contact'));
    }

    public function testATemplateThatIsNotInTheRegistryIsRefused(): void
    {
        $userId = $this->actAsAdministrator();

        $this->submitForm(
            [
                '_wpnonce'            => wp_create_nonce(IntegrationsPage::ACTION_SAVE),
                'jotform_integration' => [
                    'name'     => 'Contact',
                    'slug'     => 'contact',
                    'form_id'  => self::FORM_ID,
                    'mode'     => Integration::MODE_CUSTOM,
                    'template' => 'does-not-exist',
                ],
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SAVE));

        $flash = get_transient(self::FLASH_PREFIX . $userId);

        $this->assertSame('error', $flash['type']);
        $this->assertNull((new IntegrationRepository())->get('contact'));
    }

    public function testDeletingRemovesTheIntegrationAndTheFormRecordNothingElseUses(): void
    {
        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);
        $this->connectForm();

        $this->assertTrue($this->plugin()->forms()->has(self::FORM_ID));

        $this->submitForm(
            [
                '_wpnonce'    => wp_create_nonce(IntegrationsPage::ACTION_DELETE),
                'integration' => 'contact',
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_DELETE));

        $this->assertNull((new IntegrationRepository())->get('contact'));
        $this->assertFalse(
            (new FormRepository($this->plugin()->client()))->has(self::FORM_ID),
            'Nothing names this form any more, so its record has nothing left to label.'
        );
    }

    public function testDeletingOneOfTwoIntegrationsKeepsTheSharedFormRecord(): void
    {
        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);
        $this->createIntegration(['slug' => 'contact-popup', 'name' => 'Popup', 'form_id' => self::FORM_ID]);
        $this->connectForm();

        $this->submitForm(
            [
                '_wpnonce'    => wp_create_nonce(IntegrationsPage::ACTION_DELETE),
                'integration' => 'contact',
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_DELETE));

        $this->assertTrue(
            (new FormRepository($this->plugin()->client()))->has(self::FORM_ID),
            'One Jotform form may back several integrations; deleting one must not blank the others.'
        );
    }

    public function testSyncSchemaStoresTheNormalizedSchemaAndComesBackWithACompatibilityReport(): void
    {
        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);

        $questions = $this->fixture('form-questions');

        $this->mockHttp(
            fn(string $url) => strpos($url, '/questions') === false ? null : $this->httpResponse(200, $questions)
        );

        $this->submitForm(
            [
                '_wpnonce'    => wp_create_nonce(IntegrationsPage::ACTION_SYNC),
                'integration' => 'contact',
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SYNC));

        $schemas = new SchemaRepository($this->plugin()->client());
        $schema  = $schemas->stored(self::FORM_ID);

        $this->assertNotNull($schema, 'Sync Schema is the one action that writes a schema.');
        $this->assertArrayHasKey('email', $schema->fields());
        $this->assertNotSame('', $schemas->meta(self::FORM_ID)['fingerprint']);

        $option = get_option(SchemaRepository::optionKey(self::FORM_ID), null);

        $this->assertIsArray($option, 'The schema is an option with no TTL, not a transient.');
        $this->assertFalse(get_transient(SchemaRepository::LEGACY_TRANSIENT_PREFIX . self::FORM_ID));
    }

    public function testAFailedSyncLeavesThePreviousSchemaInPlace(): void
    {
        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);
        $this->syncSchema(self::FORM_ID);

        $before = get_option(SchemaRepository::optionKey(self::FORM_ID));

        // The next call answers the way Jotform answers a form ID it will not
        // talk about: 401, for any of three quite different reasons.
        $this->mockHttp(
            fn(string $url) => strpos($url, '/questions') === false
                ? null
                : $this->httpResponse(401, ['responseCode' => 401, 'message' => "You're not authorized to use (/form-id)"])
        );

        $this->submitForm(
            [
                '_wpnonce'    => wp_create_nonce(IntegrationsPage::ACTION_SYNC),
                'integration' => 'contact',
            ]
        );

        $this->expectRedirect(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SYNC));

        $this->assertSame(
            $before,
            get_option(SchemaRepository::optionKey(self::FORM_ID)),
            'A failed sync degrades to the previous definition rather than to a form that stops working.'
        );
        $this->assertNotSame(
            '',
            (new SchemaRepository($this->plugin()->client()))->meta(self::FORM_ID)['error'],
            'The failure has to be visible on the screen afterwards.'
        );
    }

    public function testSyncSchemaWithoutANonceDoesNotContactJotform(): void
    {
        $this->actAsAdministrator();
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);

        // Nothing mocks the network, so a request would fail the test rather
        // than pass unnoticed.
        $this->submitForm(['integration' => 'contact']);

        $this->expectWpDie(fn() => do_action('admin_post_' . IntegrationsPage::ACTION_SYNC));

        $this->assertFalse($this->plugin()->schemas()->isSynced(self::FORM_ID));
    }

    /**
     * Stores the connected-form record the way the Connect form button does.
     */
    private function connectForm(): void
    {
        $form = $this->fixture('form');

        $this->mockHttp(
            fn(string $url) => strpos($url, '/form/' . self::FORM_ID) === false
                ? null
                : $this->httpResponse(200, $form)
        );

        $response = $this->plugin()->forms()->connect(self::FORM_ID);

        $this->assertTrue($response->isSuccess(), $response->errorMessage());
    }
}
