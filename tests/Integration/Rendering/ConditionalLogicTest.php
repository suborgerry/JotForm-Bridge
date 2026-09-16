<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Rendering;

use JotformBridge\Rendering\Assets;
use JotformBridge\Tests\Integration\TestCase;

final class ConditionalLogicTest extends TestCase
{
    public function testRulesAreLocalizedPerIntegrationWithoutUpstreamIdentifiers(): void
    {
        $rule = ['action' => 'show', 'target' => 'message', 'source' => 'preferred_contact', 'operator' => 'equals', 'value' => 'E-mail'];
        $this->createIntegration(['conditions' => [$rule]]);
        $this->createIntegration(['slug' => 'footer', 'conditions' => [array_merge($rule, ['value' => 'Phone'])]]);
        $this->syncSchema('240000000000001');
        $this->assertNotSame('', jotform_bridge_render('contact'));
        $this->assertNotSame('', jotform_bridge_render('footer'));

        $script = wp_scripts()->get_data(Assets::HANDLE, 'data');
        $this->assertSame(1, preg_match('/var jotformBridgeSettings = (\{.*\});/s', $script, $matches));
        $settings = json_decode($matches[1], true);
        $this->assertSame([$rule], $settings['conditions']['contact']['rules']);
        $this->assertSame('Phone', $settings['conditions']['footer']['rules'][0]['value']);
        $this->assertContains('message', $settings['conditions']['contact']['required']);
        $this->assertStringNotContainsString('240000000000001', $script);
        $this->assertStringNotContainsString('qid', $script);
        $this->assertStringNotContainsString(JOTFORM_API_KEY, $script);
    }

    public function testAReferencedFieldRemovedFromTheSchemaStopsRendering(): void
    {
        $this->createIntegration(['conditions' => [
            ['action' => 'show', 'target' => 'removed', 'source' => 'email', 'operator' => 'not_empty', 'value' => ''],
        ]]);
        $this->syncSchema('240000000000001');
        $this->assertSame('', jotform_bridge_render('contact'));
        $this->actAsAdministrator();
        $this->assertStringContainsString('conditional rules', jotform_bridge_render('contact'));
    }
}
