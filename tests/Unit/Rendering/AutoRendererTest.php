<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Rendering;

use Brain\Monkey\Filters;
use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Integrations\Integration;
use JotformBridge\Rendering\AutoRenderer;
use JotformBridge\Tests\TestCase;

/**
 * The fallback renderer: a form built from the schema alone, without any theme
 * file, that still speaks the same contract as a hand-written template.
 */
final class AutoRendererTest extends TestCase
{
    private const FORM_ID  = '240000000000001';
    private const ENDPOINT = 'https://example.test/wp-json/jotform-bridge/v1/submit/contact';

    public function testTheFormCarriesTheMarkersTheFrontendScriptNeeds(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<form class="jfb-form"', $html);
        $this->assertStringContainsString('data-jotform-bridge', $html);
        $this->assertStringContainsString('data-jotform-integration="contact"', $html);
        $this->assertStringContainsString('action="' . self::ENDPOINT . '"', $html);
        $this->assertStringContainsString('data-jotform-errors', $html);
        $this->assertStringContainsString('data-jotform-success', $html);
        $this->assertStringContainsString('<button type="submit" class="jfb-submit"', $html);
    }

    public function testEverySupportedSemanticPathBecomesAnInput(): void
    {
        $schema = $this->schema();
        $html   = $this->render($schema);

        foreach ($schema->semanticPaths() as $path) {
            $this->assertStringContainsString(
                'data-jotform-field="' . $path . '"',
                $html,
                sprintf('The schema path "%s" is not rendered.', $path)
            );
        }
    }

    public function testUnsupportedFieldsAreLeftOutRatherThanHalfRendered(): void
    {
        $html = $this->render();

        // control_datetime and control_fileupload cannot be mapped, so offering
        // an input for them would collect data that never reaches Jotform.
        $this->assertStringNotContainsString('data-jotform-field="preferred_call"', $html);
        $this->assertStringNotContainsString('data-jotform-field="attachment"', $html);
        $this->assertStringNotContainsString('type="file"', $html);
    }

    public function testNoJotformIdentifierReachesTheMarkup(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString(self::FORM_ID, $html);
        $this->assertStringNotContainsString('qid', $html);
    }

    public function testEachTypeGetsTheMatchingControl(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('type="email" id="jfb-contact-1-email"', $html);
        $this->assertStringContainsString('type="tel"', $html);
        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringContainsString('<textarea class="jfb-input"', $html);
        $this->assertStringContainsString('<select class="jfb-input"', $html);
        $this->assertStringContainsString('<option value="Search engine">', $html);
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function testRequiredIsExpressedForBothTheBrowserAndAssistiveTechnology(): void
    {
        $html = $this->render();

        $this->assertStringContainsString(
            'data-jotform-field="email" aria-describedby="jfb-contact-1-email-error" required aria-required="true"',
            $html
        );
        $this->assertStringContainsString('class="jfb-required"', $html);
    }

    public function testARequiredCheckboxGroupDoesNotDemandEveryBox(): void
    {
        $schema = $this->schemaWith(
            [
                'qid'      => '11',
                'type'     => 'control_checkbox',
                'name'     => 'topics',
                'text'     => 'Topics',
                'required' => 'Yes',
                'options'  => 'Pricing|Support',
            ]
        );

        $html = $this->render($schema);

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString(' required>', $html);
        $this->assertStringContainsString('aria-required="true"', $html, 'The group itself is still required.');
    }

    public function testARequiredRadioGroupMarksEveryOption(): void
    {
        $html = $this->render();

        $this->assertSame(
            2,
            substr_count($html, 'data-jotform-field="preferred_contact" required'),
            'Each radio of a required group carries the attribute, so the browser enforces one choice.'
        );
    }

    public function testACompositeFieldBecomesAFieldsetOfItsChildren(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<fieldset class="jfb-field jfb-field--name">', $html);
        $this->assertStringContainsString('<legend class="jfb-legend">Full Name', $html);
        $this->assertStringContainsString('data-jotform-field="full_name.first"', $html);
        $this->assertStringContainsString('data-jotform-field="full_name.last"', $html);
        $this->assertStringNotContainsString('data-jotform-field="full_name"', $html);
        $this->assertStringContainsString('autocomplete="given-name"', $html);
    }

    public function testEveryInputHasALabelAndAnErrorSlotBoundToIt(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<label class="jfb-label" for="jfb-contact-1-email">', $html);
        $this->assertStringContainsString(
            '<span class="jfb-error" id="jfb-contact-1-email-error" data-jotform-field-error="email"></span>',
            $html
        );
    }

    public function testTwoFormsOnOnePageDoNotShareElementIds(): void
    {
        $renderer = new AutoRenderer();
        $schema   = $this->schema();

        $first  = $renderer->render($this->integration(), $schema, self::ENDPOINT);
        $second = $renderer->render($this->integration(), $schema, self::ENDPOINT);

        preg_match('/id="([^"]+-email)"/', $first, $firstId);
        preg_match('/id="([^"]+-email)"/', $second, $secondId);

        $this->assertNotSame($firstId[1], $secondId[1]);
    }

    public function testAFormWithNothingRenderableProducesNoMarkup(): void
    {
        $schema = $this->schemaWith(
            [
                'qid'  => '13',
                'type' => 'control_datetime',
                'name' => 'when',
                'text' => 'When',
            ]
        );

        $this->assertSame('', $this->render($schema));
    }

    public function testAFieldCanBeRewrittenThroughTheFilter(): void
    {
        Filters\expectApplied(AutoRenderer::FILTER_FIELD_HTML)
            ->atLeast()
            ->once()
            ->andReturnUsing(
                static fn(string $html, array $field): string => (string) $field['key'] === 'email'
                    ? '<div class="theme-email">' . $html . '</div>'
                    : $html
            );

        $html = $this->render();

        $this->assertStringContainsString('<div class="theme-email">', $html);
        $this->assertStringContainsString('data-jotform-field="email"', $html);
    }

    private function render(?FormSchema $schema = null): string
    {
        // A fresh renderer per call keeps the instance counter predictable.
        return (new AutoRenderer())->render(
            $this->integration(),
            $schema ?? $this->schema(),
            self::ENDPOINT
        );
    }

    private function integration(): Integration
    {
        return new Integration('contact', 'Contact', self::FORM_ID, Integration::MODE_AUTO, '', true);
    }

    private function schema(): FormSchema
    {
        return (new SchemaBuilder())->build(
            self::FORM_ID,
            array_values($this->fixture('form-questions')['content'])
        );
    }

    /**
     * @param array<string, mixed> $question
     */
    private function schemaWith(array $question): FormSchema
    {
        return (new SchemaBuilder())->build(self::FORM_ID, [$question]);
    }
}
