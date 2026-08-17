<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Templates;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use JotformBridge\Templates\TemplateScanner;
use JotformBridge\Tests\TestCase;

final class TemplateScannerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/jfb-scanner-' . uniqid('', true);

        mkdir($this->root . '/child/forms', 0777, true);
        mkdir($this->root . '/parent/forms', 0777, true);

        Functions\when('get_stylesheet_directory')->justReturn($this->root . '/child');
        Functions\when('get_template_directory')->justReturn($this->root . '/parent');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testValidHeaderProducesARegistryEntry(): void
    {
        $this->write(
            'child/forms/contact.php',
            $this->template('Contact Form', 'contact', '<input data-jotform-field="email">')
        );

        $result = (new TemplateScanner())->scan();

        $this->assertArrayHasKey('contact', $result['templates']);
        $this->assertSame('Contact Form', $result['templates']['contact']['name']);
        $this->assertSame(TemplateScanner::SOURCE_CHILD_THEME, $result['templates']['contact']['source']);
        $this->assertSame(
            realpath($this->root . '/child/forms/contact.php'),
            $result['templates']['contact']['file']
        );
    }

    public function testFileWithoutAnyJotformHeaderIsSilentlyIgnored(): void
    {
        $this->write('child/forms/helper.php', "<?php\n// Just a theme partial.\n");

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates']);
        $this->assertSame([], $result['diagnostics']);
    }

    public function testMissingNameIsRejected(): void
    {
        $this->write(
            'child/forms/broken.php',
            "<?php\n/*\nJotform Template Slug: broken\n*/\n"
        );

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates']);
        $this->assertSame(TemplateScanner::CODE_MISSING_NAME, $result['diagnostics'][0]['code']);
        $this->assertSame(TemplateScanner::LEVEL_ERROR, $result['diagnostics'][0]['level']);
    }

    public function testMissingSlugIsRejected(): void
    {
        $this->write(
            'child/forms/broken.php',
            "<?php\n/*\nJotform Template Name: Broken\n*/\n"
        );

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates']);
        $this->assertSame(TemplateScanner::CODE_MISSING_SLUG, $result['diagnostics'][0]['code']);
    }

    public function testSlugWithNoUsableCharactersIsRejected(): void
    {
        $this->write(
            'child/forms/broken.php',
            "<?php\n/*\nJotform Template Name: Broken\nJotform Template Slug: ***\n*/\n"
        );

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates']);
        $this->assertSame(TemplateScanner::CODE_INVALID_SLUG, $result['diagnostics'][0]['code']);
    }

    public function testTemplateDeclaringAJotformFormIdIsRejected(): void
    {
        $this->write(
            'child/forms/contact.php',
            "<?php\n/*\nJotform Template Name: Contact\nJotform Template Slug: contact\nJotform Form ID: 240000000000001\n*/\n"
        );

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates'], 'A template must never name a Jotform form.');
        $this->assertSame(TemplateScanner::CODE_FORBIDDEN, $result['diagnostics'][0]['code']);
    }

    public function testDuplicateSlugInTheSameDirectoryIsReported(): void
    {
        $this->write('child/forms/a-contact.php', $this->template('First', 'contact', ''));
        $this->write('child/forms/b-contact.php', $this->template('Second', 'contact', ''));

        $result = (new TemplateScanner())->scan();

        $this->assertCount(1, $result['templates']);
        $this->assertSame('First', $result['templates']['contact']['name']);

        $codes = array_column($result['diagnostics'], 'code');

        $this->assertContains(TemplateScanner::CODE_DUPLICATE, $codes);
    }

    public function testChildThemeOverridesTheParentTheme(): void
    {
        $this->write(
            'parent/forms/contact.php',
            $this->template('Parent Contact', 'contact', '<input data-jotform-field="email">')
        );
        $this->write(
            'child/forms/contact.php',
            $this->template('Child Contact', 'contact', '<input data-jotform-field="phone">')
        );

        $result = (new TemplateScanner())->scan();

        $this->assertCount(1, $result['templates']);
        $this->assertSame('Child Contact', $result['templates']['contact']['name']);
        $this->assertSame(['phone'], $result['templates']['contact']['fields']);

        $codes = array_column($result['diagnostics'], 'code');

        $this->assertContains(TemplateScanner::CODE_OVERRIDDEN, $codes);
        $this->assertNotContains(TemplateScanner::CODE_DUPLICATE, $codes);
    }

    public function testParentTemplateIsUsedWhenTheChildDoesNotOverrideIt(): void
    {
        $this->write('parent/forms/career.php', $this->template('Career', 'career', ''));

        $result = (new TemplateScanner())->scan();

        $this->assertArrayHasKey('career', $result['templates']);
        $this->assertSame(TemplateScanner::SOURCE_PARENT_THEME, $result['templates']['career']['source']);
    }

    public function testFilesOutsideAnAllowedRootAreRejected(): void
    {
        mkdir($this->root . '/untrusted', 0777, true);
        $this->write('untrusted/evil.php', $this->template('Evil', 'evil', ''));

        symlink($this->root . '/untrusted/evil.php', $this->root . '/child/forms/evil.php');

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates']);
        $this->assertSame(TemplateScanner::CODE_OUTSIDE_ROOT, $result['diagnostics'][0]['code']);
    }

    public function testNonExistingAndRelativeFilterPathsAreDropped(): void
    {
        Filters\expectApplied('jotform_bridge_template_paths')
            ->once()
            ->andReturn(
                [
                    $this->root . '/does-not-exist',
                    '../../../etc',
                    '',
                ]
            );

        $result = (new TemplateScanner())->scan();

        $paths = array_column($result['roots'], 'path');

        $this->assertSame(
            [realpath($this->root . '/child/forms'), realpath($this->root . '/parent/forms')],
            $paths
        );
    }

    public function testAFilterCanAddAnAdditionalDirectory(): void
    {
        mkdir($this->root . '/plugin/forms', 0777, true);
        $this->write('plugin/forms/extra.php', $this->template('Extra', 'extra', ''));

        Filters\expectApplied('jotform_bridge_template_paths')
            ->once()
            ->andReturnUsing(
                fn(array $paths): array => array_merge($paths, [$this->root . '/plugin/forms'])
            );

        $result = (new TemplateScanner())->scan();

        $this->assertArrayHasKey('extra', $result['templates']);
        $this->assertSame(TemplateScanner::SOURCE_FILTER, $result['templates']['extra']['source']);
    }

    public function testNestedDirectoriesAreNotScanned(): void
    {
        mkdir($this->root . '/child/forms/partials', 0777, true);
        $this->write('child/forms/partials/field.php', $this->template('Partial', 'partial', ''));

        $result = (new TemplateScanner())->scan();

        $this->assertSame([], $result['templates']);
    }

    public function testLiteralFieldIdentifiersAreExtracted(): void
    {
        $this->write(
            'child/forms/contact.php',
            $this->template(
                'Contact',
                'contact',
                '<input data-jotform-field="email">' .
                "<input data-jotform-field='name.first'>" .
                '<input data-jotform-field="name.last">' .
                '<input data-jotform-field=phone>' .
                '<input data-jotform-field="email">'
            )
        );

        $result = (new TemplateScanner())->scan();

        $this->assertSame(
            ['email', 'name.first', 'name.last', 'phone'],
            $result['templates']['contact']['fields']
        );
        $this->assertSame(0, $result['templates']['contact']['dynamic']);
    }

    public function testDynamicFieldIdentifiersAreCountedButNotGuessed(): void
    {
        $this->write(
            'child/forms/contact.php',
            $this->template(
                'Contact',
                'contact',
                '<input data-jotform-field="email">' .
                '<input data-jotform-field="<?php echo $key; ?>">'
            )
        );

        $result = (new TemplateScanner())->scan();

        $this->assertSame(['email'], $result['templates']['contact']['fields']);
        $this->assertSame(1, $result['templates']['contact']['dynamic']);

        $codes = array_column($result['diagnostics'], 'code');

        $this->assertContains(TemplateScanner::CODE_DYNAMIC_FIELD, $codes);
    }

    public function testTemplateWithoutAnyFieldIsReported(): void
    {
        $this->write('child/forms/empty.php', $this->template('Empty', 'empty', '<p>Nothing</p>'));

        $result = (new TemplateScanner())->scan();

        $this->assertArrayHasKey('empty', $result['templates']);

        $codes = array_column($result['diagnostics'], 'code');

        $this->assertContains(TemplateScanner::CODE_NO_FIELDS, $codes);
    }

    public function testTheScannerNeverIncludesATemplate(): void
    {
        $this->write(
            'child/forms/contact.php',
            "<?php\n/*\nJotform Template Name: Contact\nJotform Template Slug: contact\n*/\n"
            . "define('JFB_TEMPLATE_WAS_EXECUTED', true);\n"
        );

        (new TemplateScanner())->scan();

        $this->assertFalse(defined('JFB_TEMPLATE_WAS_EXECUTED'));
    }

    private function template(string $name, string $slug, string $body): string
    {
        return "<?php\n/*\nJotform Template Name: {$name}\nJotform Template Slug: {$slug}\n*/\n?>\n{$body}\n";
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->root . '/' . $relative, $contents);
    }

    private function removeDirectory(string $path): void
    {
        if ($path === '' || !is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            if (is_link($full) || is_file($full)) {
                unlink($full);

                continue;
            }

            $this->removeDirectory($full);
        }

        rmdir($path);
    }
}
