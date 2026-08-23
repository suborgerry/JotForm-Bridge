<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Templates;

use JotformBridge\Forms\FormSchema;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Integrations\Integration;
use JotformBridge\Templates\TemplateScaffold;
use JotformBridge\Templates\TemplateScanner;
use JotformBridge\Tests\TestCase;

final class TemplateScaffoldTest extends TestCase
{
    /**
     * The whole point: the file is meant to be saved and run, so it has to be
     * valid PHP before anybody pastes it into a theme.
     */
    public function testTheOutputIsValidPhp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'jfb') . '.php';

        file_put_contents($file, $this->build());

        exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $code);

        unlink($file);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    /**
     * A scaffold the scanner refuses to register would be useless.
     */
    public function testTheHeaderIsWhatTheScannerLooksFor(): void
    {
        $source = $this->build();

        $this->assertStringContainsString(TemplateScanner::HEADER_NAME . ': Contact', $source);
        $this->assertStringContainsString(TemplateScanner::HEADER_SLUG . ': contact', $source);

        // Naming a Jotform form in a template is rejected by the scanner, so
        // the scaffold must not be tempted to add one.
        $this->assertStringNotContainsString(TemplateScanner::HEADER_FORBIDDEN, $source);
        $this->assertStringNotContainsString('240000000000001', $source);
    }

    /**
     * Every identifier a developer would otherwise have retyped by hand.
     */
    public function testEveryMappableFieldIsAddressedByItsSemanticKey(): void
    {
        $schema = $this->schema();
        $source = $this->build();

        foreach ($schema->supportedFields() as $field) {
            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $this->assertStringContainsString(
                        'data-jotform-field="' . $child['key'] . '"',
                        $source
                    );
                }

                continue;
            }

            $this->assertStringContainsString(
                'data-jotform-field="' . $field['key'] . '"',
                $source
            );
        }
    }

    public function testItCarriesTheContractTheScriptNeeds(): void
    {
        $source = $this->build();

        $this->assertStringContainsString('data-jotform-bridge', $source);
        $this->assertStringContainsString('data-jotform-integration="contact"', $source);
        $this->assertStringContainsString('data-jotform-success', $source);
        $this->assertStringContainsString('data-jotform-errors', $source);
        $this->assertStringContainsString('echo $honeypot;', $source);
        $this->assertStringContainsString('echo $turnstile;', $source);
    }

    /**
     * Every addressable path needs somewhere for its message to land, or the
     * script falls back to the shared container and the visitor is told
     * something is wrong without being told what.
     *
     * Exactly one slot each: a choice group shares one across its inputs.
     */
    public function testEveryAddressablePathHasExactlyOneErrorSlot(): void
    {
        $source = $this->build();

        foreach ($this->schema()->supportedFields() as $field) {
            $keys = [];

            if ($field['children'] !== []) {
                foreach ($field['children'] as $child) {
                    $keys[] = (string) $child['key'];
                }
            } else {
                $keys[] = (string) $field['key'];
            }

            foreach ($keys as $key) {
                $this->assertSame(
                    1,
                    substr_count($source, 'data-jotform-field-error="' . $key . '"'),
                    'Expected exactly one error slot for ' . $key
                );
            }
        }
    }

    public function testChoiceOptionsComeFromTheSchema(): void
    {
        $source = $this->build();

        $this->assertStringContainsString('value="Pricing"', $source);
        $this->assertStringContainsString('value="Support"', $source);
    }

    /**
     * Jotform allows quotes and angle brackets in a label. One of those landing
     * unescaped would break the file it is pasted into.
     */
    public function testHostileLabelsCannotBreakTheFile(): void
    {
        $schema = FormSchema::fromArray([
            'form_id' => '240000000000001',
            'fields'  => [
                [
                    'qid'         => '1',
                    'key'         => 'nasty',
                    'jotform'     => '',
                    'type'        => 'text',
                    'label'       => '"><?php echo "boom"; ?><script>alert(1)</script>',
                    'required'    => false,
                    'multiple'    => false,
                    'allow_other' => false,
                    'options'     => [],
                    'children'    => [],
                    'status'      => 'supported',
                    'message'     => '',
                    'meta'        => [],
                ],
            ],
        ]);

        $source = (new TemplateScaffold())->build($this->integration(), $schema);

        $this->assertStringNotContainsString('<script>', $source);
        $this->assertStringNotContainsString('<?php echo "boom"', $source);

        $file = tempnam(sys_get_temp_dir(), 'jfb') . '.php';

        file_put_contents($file, $source);

        exec(sprintf('php -l %s 2>&1', escapeshellarg($file)), $output, $code);

        unlink($file);

        $this->assertSame(0, $code, implode("\n", $output));
    }

    public function testTheFileNameFollowsTheSlug(): void
    {
        $this->assertSame('contact.php', (new TemplateScaffold())->fileName($this->integration()));
    }

    private function build(): string
    {
        return (new TemplateScaffold())->build($this->integration(), $this->schema());
    }

    private function integration(): Integration
    {
        return new Integration('contact', 'Contact', '240000000000001', Integration::MODE_CUSTOM, '', true);
    }

    private function schema(): FormSchema
    {
        return (new SchemaBuilder())->build(
            '240000000000001',
            array_values($this->fixture('form-questions')['content'])
        );
    }
}
