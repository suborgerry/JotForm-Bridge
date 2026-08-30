<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Admin;

use JotformBridge\Admin\IntegrationsPage;
use JotformBridge\Admin\SettingsPage;
use JotformBridge\Settings\Settings;
use JotformBridge\Tests\Integration\TestCase;

/**
 * The admin screens, rendered.
 *
 * Three of the defects that got through were stale instructions in this markup:
 * a directory that had been renamed, a button that had been removed, and a
 * "stored form list" the plugin had stopped keeping. All three were found by
 * opening the page in a browser, and the unit suite stayed green through every
 * one of them, because a unit test never renders a view.
 *
 * These tests render the real templates with the real data and assert both
 * halves: that what should be on the screen is, and that what was removed from
 * the product is not still being described to the administrator.
 */
final class AdminScreensTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    /**
     * Features that were removed. Naming any of them on a screen is the exact
     * defect this file exists for.
     */
    private const REMOVED = [
        'Sync with Jotform',
        'Rescan',
        'stored form list',
        'Remove from list',
        'Monthly Submission Allowance',
        'Last 7 days',
    ];

    public function testTheIntegrationsListRendersAndDescribesNothingThatWasRemoved(): void
    {
        $this->createIntegration(['slug' => 'contact', 'name' => 'Contact form', 'form_id' => self::FORM_ID]);

        $html = $this->renderIntegrations();

        $this->assertStringContainsString('Contact form', $html);
        $this->assertStringContainsString(self::FORM_ID, $html, 'An unconnected form shows the ID that does the work.');
        $this->assertStringContainsString('Not connected', $html);

        $this->assertNoRemovedFeature($html);
    }

    public function testTheEditorOffersATypedFormIdAndConnectFormRatherThanAList(): void
    {
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);

        $html = $this->renderIntegrations(['view' => 'edit', 'integration' => 'contact']);

        $this->assertStringContainsString('name="jotform_integration[form_id]"', $html);
        $this->assertStringContainsString('Connect form', $html);
        $this->assertStringContainsString('data-jfb-connect-action="' . IntegrationsPage::ACTION_CONNECT . '"', $html);
        $this->assertMatchesRegularExpression(
            '/<input[^>]+name="jotform_integration\[form_id\]"/',
            $html,
            'The form ID is typed, not picked from a list of the account.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]+name="jotform_integration\[form_id\]"/',
            $html
        );

        $this->assertNoRemovedFeature($html);
    }

    public function testTheEditorShowsTheSyncedSchemaAsATable(): void
    {
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);
        $this->syncSchema(self::FORM_ID);

        $html = $this->renderIntegrations(['view' => 'edit', 'integration' => 'contact']);

        // Semantic key, qid and the unsupported fields, so a developer never
        // has to open raw JSON to find out what the template may use.
        $this->assertStringContainsString('full_name.first', $html);
        $this->assertStringContainsString('preferred_contact', $html);
        $this->assertStringContainsString('Attachment', $html, 'An unsupported field has to stay visible.');
    }

    public function testTheSettingsScreenNeverPrintsTheApiKey(): void
    {
        $html = $this->renderSettings();

        $this->assertStringNotContainsString(
            (string) constant(Settings::KEY_CONSTANT),
            $html,
            'The key is never printed back in full.'
        );
        $this->assertStringContainsString((new Settings())->maskedApiKey(), $html);
        $this->assertStringContainsString('Check Connection', $html);

        $this->assertNoRemovedFeature($html);
    }

    public function testNoScreenCarriesInlineScriptOrStyle(): void
    {
        $this->createIntegration(['slug' => 'contact', 'form_id' => self::FORM_ID]);

        $screens = [
            'list'     => $this->renderIntegrations(),
            'editor'   => $this->renderIntegrations(['view' => 'edit', 'integration' => 'contact']),
            'settings' => $this->renderSettings(),
        ];

        foreach ($screens as $name => $html) {
            // A Content Security Policy refuses an inline block outright, and
            // the admin then breaks silently and half way.
            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)[^>]*>/i', $html, $name);
            $this->assertDoesNotMatchRegularExpression('/<style[^>]*>/i', $html, $name);
            $this->assertStringNotContainsString('onclick=', $html, $name);
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function renderIntegrations(array $query = []): string
    {
        $this->actAsAdministrator();

        // submit_button() lives in the admin, which is not loaded by
        // wp-settings.php. admin.php would load it before calling the screen.
        require_once ABSPATH . 'wp-admin/includes/template.php';

        $_GET = $query;

        $plugin = $this->plugin();

        $page = new IntegrationsPage(
            $plugin->integrations(),
            $plugin->forms(),
            $plugin->schemas(),
            $plugin->templates(),
            $plugin->compatibility()
        );

        return $this->capture(fn() => $page->render());
    }

    private function renderSettings(): string
    {
        $this->actAsAdministrator();

        require_once ABSPATH . 'wp-admin/includes/template.php';

        $plugin = $this->plugin();

        $page = new SettingsPage($plugin->settings(), $plugin->client(), $plugin->connection());

        return $this->capture(fn() => $page->render());
    }

    private function capture(callable $render): string
    {
        ob_start();

        try {
            $render();
        } finally {
            $html = (string) ob_get_clean();
        }

        $this->assertNotSame('', $html, 'The screen rendered nothing at all.');

        return $html;
    }

    private function assertNoRemovedFeature(string $html): void
    {
        foreach (self::REMOVED as $phrase) {
            $this->assertStringNotContainsString(
                $phrase,
                $html,
                sprintf('The screen still describes "%s", which the plugin no longer has.', $phrase)
            );
        }
    }
}
