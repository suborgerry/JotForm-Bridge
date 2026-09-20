<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Integration\Templates;

use JotformBridge\Integrations\Integration;
use JotformBridge\Tests\Integration\TestCase;

/**
 * Rendering a real template file, from a real theme directory.
 *
 * The unit suite proves the scanner against files it writes and the renderer
 * against a registry it hands in. Neither proves the thing a site depends on:
 * that a PHP file dropped into a theme is found, is bound to an integration,
 * and comes out of `jotform_bridge_render()` as markup the frontend script can
 * use.
 *
 * The template under test is the one the plugin ships in examples/, so this is
 * also the only check that the documented example still works.
 */
final class TemplateRenderingTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    private string $child = '';

    private string $parent = '';

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir() . '/jfb-theme-' . bin2hex(random_bytes(6));

        $this->child  = $base . '/child/jotform-bridge-templates';
        $this->parent = $base . '/parent/jotform-bridge-templates';

        mkdir($this->child, 0777, true);
        mkdir($this->parent, 0777, true);

        // A child theme with a parent, which is what decides template priority.
        $this->filter('stylesheet_directory', fn(): string => dirname($this->child), 10, 1);
        $this->filter('template_directory', fn(): string => dirname($this->parent), 10, 1);
    }

    protected function tearDown(): void
    {
        foreach ([$this->child, $this->parent] as $directory) {
            if ($directory !== '' && is_dir($directory)) {
                $this->removeDirectory(dirname($directory));
            }
        }

        parent::tearDown();
    }

    public function testTheShippedExampleTemplateRendersThroughThePublicHelper(): void
    {
        $this->installExample($this->child, 'contact.php');
        $this->givenACustomIntegration('contact');

        $html = jotform_bridge_render('contact');

        $this->assertStringContainsString('data-jotform-bridge', $html);
        $this->assertStringContainsString('data-jotform-integration="contact"', $html);
        $this->assertStringContainsString('data-jotform-field="email"', $html);
        $this->assertStringContainsString('data-jotform-field="full_name.first"', $html);
        $this->assertStringContainsString(rest_url('jotform-bridge/v1/submit/contact'), $html);

        $this->assertStringNotContainsString(
            self::FORM_ID,
            $html,
            'A template never knows the Jotform form ID.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/name="q\d+"|data-jotform-field="\d+"/',
            $html,
            'A template never knows a Jotform qid.'
        );
    }

    public function testTheShortcodeAndTheHelperRenderTheSameForm(): void
    {
        $this->installExample($this->child, 'contact.php');
        $this->givenACustomIntegration('contact');

        // The honeypot carries a per-render instance number, so that two of the
        // same form on one page do not share an element ID. Everything else has
        // to match exactly.
        $normalize = static fn(string $html): string => (string) preg_replace('/hp-\d+/', 'hp-N', $html);

        $this->assertSame(
            $normalize(jotform_bridge_render('contact')),
            $normalize(do_shortcode('[jotform_form id="contact"]')),
            'Both go through one rendering service; neither has logic of its own.'
        );
    }

    public function testTheChildThemeWinsWhenBothThemesHaveTheSameSlug(): void
    {
        $this->writeTemplate($this->parent, 'contact.php', 'Contact', 'PARENT-MARKER');
        $this->writeTemplate($this->child, 'contact.php', 'Contact', 'CHILD-MARKER');

        $this->givenACustomIntegration('contact');

        $html = jotform_bridge_render('contact');

        $this->assertStringContainsString('CHILD-MARKER', $html);
        $this->assertStringNotContainsString('PARENT-MARKER', $html);
    }

    public function testAParentThemeTemplateIsUsedWhenTheChildHasNone(): void
    {
        $this->writeTemplate($this->parent, 'contact.php', 'Contact', 'PARENT-MARKER');

        $this->givenACustomIntegration('contact');

        $this->assertStringContainsString('PARENT-MARKER', jotform_bridge_render('contact'));
    }

    public function testATemplateThatBindsItselfToAJotformFormIsRejected(): void
    {
        file_put_contents(
            $this->child . '/contact.php',
            "<?php\n/*\nJotform Template Name: Contact\nJotform Form ID: " . self::FORM_ID . "\n*/\n?>\nMARKER\n"
        );

        $this->givenACustomIntegration('contact');

        $this->assertFalse($this->plugin()->templates()->has('contact'));
        $this->assertSame('', jotform_bridge_render('contact'), 'A visitor is never shown a diagnostic.');
    }

    public function testAFileOutsideTheTemplateRootsIsNeverRenderable(): void
    {
        $this->installExample($this->child, 'contact.php');

        $registry = $this->plugin()->templates();

        foreach (
            [
                '../../../wp-config',
                '../child/jotform-bridge-templates/contact',
                '/etc/passwd',
                'contact.php',
            ] as $slug
        ) {
            $this->assertNull(
                $registry->file($slug),
                sprintf('"%s" resolved to a renderable file.', $slug)
            );
        }

        $this->assertNotNull($registry->file('contact'), 'The discovered template itself still resolves.');
    }

    public function testASymlinkLeavingTheTemplateDirectoryIsIgnored(): void
    {
        $outside = sys_get_temp_dir() . '/jfb-outside-' . bin2hex(random_bytes(6)) . '.php';

        file_put_contents($outside, "<?php\n/*\nJotform Template Name: Outside\n*/\n?>\nOUTSIDE\n");

        try {
            symlink($outside, $this->child . '/outside.php');
        } catch (\Throwable $error) {
            $this->markTestSkipped('This filesystem does not allow symlinks.');
        }

        $this->assertFalse(
            $this->plugin()->templates()->has('outside'),
            'A symlink resolving outside its root is not covered by the trusted directory.'
        );

        unlink($outside);
    }

    public function testAnIntegrationWithNoSyncedSchemaRendersNothingForAVisitor(): void
    {
        $this->installExample($this->child, 'contact.php');

        $this->createIntegration(
            [
                'slug'     => 'contact',
                'form_id'  => self::FORM_ID,
                'mode'     => Integration::MODE_CUSTOM,
                'template' => 'contact',
            ]
        );

        wp_set_current_user(0);

        $this->assertSame('', jotform_bridge_render('contact'));
    }

    public function testAnAdministratorIsToldWhyTheFormDidNotRender(): void
    {
        $this->installExample($this->child, 'contact.php');
        $this->actAsAdministrator();

        $this->createIntegration(
            [
                'slug'     => 'contact',
                'form_id'  => self::FORM_ID,
                'mode'     => Integration::MODE_CUSTOM,
                'template' => 'contact',
            ]
        );

        $this->assertStringContainsString('Sync Schema', jotform_bridge_render('contact'));
    }

    public function testATemplateAddedToTheThemeIsFoundWithoutAnyRescan(): void
    {
        $this->givenACustomIntegration('contact');

        $this->assertFalse($this->plugin()->templates()->has('contact'));

        $this->installExample($this->child, 'contact.php');

        // The registry memoizes within one request, and reads the theme again
        // on the next one. There is no cache to clear and no button to press:
        // see "Amendment: template discovery reads the theme on demand".
        $this->plugin()->templates()->flush();

        $this->assertTrue($this->plugin()->templates()->has('contact'));
    }

    private function givenACustomIntegration(string $template): void
    {
        $this->createIntegration(
            [
                'slug'     => 'contact',
                'form_id'  => self::FORM_ID,
                'mode'     => Integration::MODE_CUSTOM,
                'template' => $template,
            ]
        );

        $this->syncSchema(self::FORM_ID);
    }

    private function installExample(string $directory, string $file): void
    {
        $source = JOTFORM_BRIDGE_DIR . 'examples/contact.php';

        $this->assertFileExists($source, 'The plugin no longer ships the example template.');

        copy($source, $directory . '/' . $file);
    }

    private function writeTemplate(string $directory, string $file, string $name, string $marker): void
    {
        file_put_contents(
            $directory . '/' . $file,
            "<?php\n/*\nJotform Template Name: {$name}\n*/\n?>\n"
            . "<form data-jotform-bridge data-jotform-integration=\"<?php echo esc_attr(\$integration['slug']); ?>\">"
            . "{$marker}</form>\n"
        );
    }

    private function removeDirectory(string $directory): void
    {
        foreach ((array) glob($directory . '/*') as $entry) {
            if (!is_string($entry)) {
                continue;
            }

            is_dir($entry) && !is_link($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }

        rmdir($directory);
    }
}
