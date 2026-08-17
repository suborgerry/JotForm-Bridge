<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Integrations;

use JotformBridge\Integrations\Integration;
use JotformBridge\Tests\TestCase;

/**
 * The redirect settings as data: defaults, sanitizing and round-tripping.
 *
 * The interesting property here is that the fields are optional in every
 * direction — an integration stored before they existed must keep working
 * without a migration step.
 */
final class IntegrationRedirectTest extends TestCase
{
    public function testAnIntegrationWithoutRedirectFieldsUsesTheDefaults(): void
    {
        $integration = Integration::fromArray(
            [
                'slug'    => 'contact',
                'name'    => 'Contact',
                'form_id' => '240000000000001',
                'mode'    => 'custom',
                'active'  => true,
            ]
        );

        $this->assertSame(Integration::SUCCESS_MESSAGE, $integration->successAction());
        $this->assertFalse($integration->redirectsOnSuccess());
        $this->assertSame(0, $integration->redirectPageId());
        $this->assertSame(0, $integration->redirectDelay());
    }

    public function testTheRedirectSettingsRoundTripThroughStorage(): void
    {
        $original = new Integration(
            'contact',
            'Contact',
            '240000000000001',
            Integration::MODE_CUSTOM,
            'contact',
            true,
            0,
            0,
            Integration::SUCCESS_REDIRECT,
            42,
            5
        );

        $restored = Integration::fromArray($original->toArray());

        $this->assertTrue($restored->redirectsOnSuccess());
        $this->assertSame(42, $restored->redirectPageId());
        $this->assertSame(5, $restored->redirectDelay());
    }

    public function testAnUnknownSuccessActionFallsBackToTheMessage(): void
    {
        $integration = Integration::fromInput(
            [
                'name'           => 'Contact',
                'slug'           => 'contact',
                'success_action' => 'javascript:alert(1)',
            ]
        );

        $this->assertSame(Integration::SUCCESS_MESSAGE, $integration->successAction());
        $this->assertFalse($integration->redirectsOnSuccess());
    }

    public function testInputIsCoercedIntoAPageIdAndABoundedDelay(): void
    {
        $integration = Integration::fromInput(
            [
                'name'             => 'Contact',
                'slug'             => 'contact',
                'success_action'   => 'redirect',
                'redirect_page_id' => ' 42abc ',
                'redirect_delay'   => '9000',
            ]
        );

        $this->assertTrue($integration->redirectsOnSuccess());
        $this->assertSame(42, $integration->redirectPageId());
        $this->assertSame(Integration::MAX_REDIRECT_DELAY, $integration->redirectDelay());
    }

    public function testNegativeAndNonScalarInputCollapseToZero(): void
    {
        $integration = Integration::fromInput(
            [
                'name'             => 'Contact',
                'slug'             => 'contact',
                'success_action'   => 'redirect',
                'redirect_page_id' => '-5',
                'redirect_delay'   => ['30'],
            ]
        );

        $this->assertSame(0, $integration->redirectPageId());
        $this->assertSame(0, $integration->redirectDelay());
    }

    /**
     * A URL must not be storable anywhere in the integration: the page ID is the
     * only thing a redirect can be built from.
     */
    public function testNoUrlCanBeStoredOnTheIntegration(): void
    {
        $integration = Integration::fromInput(
            [
                'name'             => 'Contact',
                'slug'             => 'contact',
                'success_action'   => 'redirect',
                'redirect_page_id' => 'https://evil.example/',
                'redirect_url'     => 'https://evil.example/',
            ]
        );

        $this->assertSame(0, $integration->redirectPageId());
        $this->assertStringNotContainsString('evil.example', (string) json_encode($integration->toArray()));
    }
}
