<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Rendering;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\IntegrationRepository;
use JotformBridge\Rendering\Assets;
use JotformBridge\Rendering\CustomTemplateRenderer;
use JotformBridge\Rendering\FormRenderer;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Tests\TestCase;

/**
 * Rendering seen from a theme: a slug goes in, theme-authored HTML comes out,
 * and no failure is ever fatal.
 */
final class FormRendererTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    private string $root = '';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    private bool $enqueued = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root       = sys_get_temp_dir() . '/jfb-render-' . uniqid('', true);
        $this->options    = [];
        $this->transients = [];
        $this->enqueued   = false;

        mkdir($this->root . '/theme/forms', 0777, true);

        Functions\when('get_stylesheet_directory')->justReturn($this->root . '/theme');
        Functions\when('get_template_directory')->justReturn($this->root . '/theme');

        Functions\when('get_option')->alias(
            fn(string $name, $default = false) => $this->options[$name] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $name, $value): bool {
                $this->options[$name] = $value;

                return true;
            }
        );
        Functions\when('delete_option')->alias(
            function (string $name): bool {
                unset($this->options[$name]);

                return true;
            }
        );
        Functions\when('get_transient')->alias(
            fn(string $name) => $this->transients[$name] ?? false
        );
        Functions\when('set_transient')->alias(
            function (string $name, $value): bool {
                $this->transients[$name] = $value;

                return true;
            }
        );

        Functions\when('rest_url')->alias(
            static fn(string $path = ''): string => 'https://example.test/wp-json/' . ltrim($path, '/')
        );
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('shortcode_atts')->alias(
            static fn(array $pairs, $atts): array => array_merge($pairs, is_array($atts) ? $atts : [])
        );

        Functions\when('wp_script_is')->justReturn(false);
        Functions\when('wp_register_script')->justReturn(true);
        Functions\when('wp_localize_script')->justReturn(true);
        Functions\when('wp_enqueue_script')->alias(
            function (): bool {
                $this->enqueued = true;

                return true;
            }
        );

        $this->storeIntegration(true, 'contact');
        $this->cacheSchema();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testARegisteredTemplateIsRenderedWithItsOwnMarkup(): void
    {
        $this->writeTemplate(
            'contact',
            '<form data-jotform-bridge data-jotform-integration="<?php echo esc_attr($integration[\'slug\']); ?>"'
            . ' action="<?php echo esc_url($endpoint); ?>" class="theme-form">'
            . '<input data-jotform-field="email">'
            . '<div data-jotform-errors aria-live="polite"></div>'
            . '</form>'
        );

        $html = $this->renderer()->render('contact');

        $this->assertStringContainsString('data-jotform-bridge', $html);
        $this->assertStringContainsString('data-jotform-integration="contact"', $html);
        $this->assertStringContainsString(
            'action="https://example.test/wp-json/jotform-bridge/v1/submit/contact"',
            $html
        );
        $this->assertStringContainsString('class="theme-form"', $html);
        $this->assertTrue($this->enqueued, 'The frontend script is enqueued only when a form renders.');
    }

    public function testTheShortcodeAndTheHelperProduceTheSameHtml(): void
    {
        $this->writeTemplate('contact', '<form data-jotform-bridge><input data-jotform-field="email"></form>');

        $renderer = $this->renderer();

        $this->assertSame($renderer->render('contact'), $renderer->shortcode(['id' => 'contact']));
    }

    public function testTheTemplateContextExposesNoJotformIdentifiers(): void
    {
        $this->writeTemplate('contact', '<?php echo wp_json_encode(get_defined_vars()); ?>');

        $html = $this->renderer()->render('contact');

        $this->assertStringNotContainsString(self::FORM_ID, $html);
        $this->assertStringNotContainsString('"qid"', $html);
        $this->assertStringContainsString('"email"', $html);
    }

    public function testTheContextCarriesTheSchemaFieldsATemplateNeeds(): void
    {
        $this->writeTemplate(
            'contact',
            '<?php foreach ($schema[\'fields\'] as $path => $field) {'
            . ' echo $path . ":" . ($field["required"] ? "1" : "0") . ";"; } ?>'
        );

        $html = $this->renderer()->render('contact');

        $this->assertStringContainsString('email:1;', $html);
        $this->assertStringContainsString('full_name.first:1;', $html);
        $this->assertStringContainsString('company:0;', $html);
    }

    public function testAnAutoIntegrationRendersWithoutAnyTemplate(): void
    {
        $this->storeIntegration(true, '', 'auto');

        $html = $this->renderer()->render('contact');

        $this->assertStringContainsString('class="jfb-form"', $html);
        $this->assertStringContainsString('data-jotform-integration="contact"', $html);
        $this->assertStringContainsString('data-jotform-field="email"', $html);
        $this->assertTrue($this->enqueued);
    }

    public function testAutoRenderingIgnoresAnAssignedTemplate(): void
    {
        $this->writeTemplate('contact', '<form data-jotform-bridge class="theme-form"></form>');
        $this->storeIntegration(true, 'contact', 'auto');

        $this->assertStringNotContainsString('theme-form', $this->renderer()->render('contact'));
    }

    public function testAnUnknownIntegrationRendersNothingForVisitors(): void
    {
        $this->assertSame('', $this->renderer()->render('missing'));
        $this->assertFalse($this->enqueued);
    }

    public function testADisabledIntegrationRendersNothing(): void
    {
        $this->writeTemplate('contact', '<form data-jotform-bridge></form>');
        $this->storeIntegration(false, 'contact');

        $this->assertSame('', $this->renderer()->render('contact'));
    }

    public function testAMissingTemplateRendersNothing(): void
    {
        $this->storeIntegration(true, 'gone');

        $this->assertSame('', $this->renderer()->render('contact'));
    }

    public function testAdministratorsSeeWhyNothingRendered(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderer()->render('missing');

        $this->assertStringContainsString('Jotform Bridge:', $html);
        $this->assertStringContainsString('missing', $html);
    }

    /**
     * The rule that keeps a Jotform outage off the critical path of a page
     * view: rendering reads what was synced and never fetches.
     */
    public function testAnUnsyncedSchemaRendersNothingAndFetchesNothing(): void
    {
        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);

        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \RuntimeException('Rendering must never contact Jotform.');
            }
        );

        $this->storeIntegration(true, 'contact');

        $this->assertSame('', $this->renderer()->render('contact'));
    }

    public function testAnAdministratorIsToldToSyncTheSchema(): void
    {
        unset($this->options[SchemaRepository::optionKey(self::FORM_ID)]);

        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_remote_get')->alias(
            static function (): void {
                throw new \RuntimeException('Rendering must never contact Jotform.');
            }
        );

        $this->storeIntegration(true, 'contact');

        $this->assertStringContainsString('Sync Schema', $this->renderer()->render('contact'));
    }

    public function testATemplateThatThrowsDoesNotBreakThePage(): void
    {
        $this->writeTemplate('contact', '<?php throw new \RuntimeException("template blew up"); ?>');

        $before = ob_get_level();
        $html   = $this->renderer()->render('contact');

        $this->assertSame('', $html);
        $this->assertSame($before, ob_get_level(), 'The output buffer must not be left open.');
    }

    private function renderer(): FormRenderer
    {
        $client   = new JotformClient('test-api-key', 'https://api.jotform.com');
        $registry = new TemplateRegistry();
        $registry->rescan();

        return new FormRenderer(
            new IntegrationRepository(),
            new SchemaRepository($client),
            new CustomTemplateRenderer($registry),
            new Assets()
        );
    }

    private function storeIntegration(bool $active, string $template, string $mode = 'custom'): void
    {
        $this->options[IntegrationRepository::OPTION] = [
            'contact' => [
                'slug'     => 'contact',
                'name'     => 'Contact',
                'form_id'  => self::FORM_ID,
                'mode'     => $mode,
                'template' => $template,
                'active'   => $active,
            ],
        ];
    }

    private function cacheSchema(): void
    {
        $schema = (new SchemaBuilder())->build(
            self::FORM_ID,
            array_values($this->fixture('form-questions')['content'])
        );

        $this->options[SchemaRepository::optionKey(self::FORM_ID)] = $schema->toArray();
    }

    private function writeTemplate(string $slug, string $body): void
    {
        file_put_contents(
            $this->root . '/theme/forms/' . $slug . '.php',
            "<?php\n/*\nJotform Template Name: Contact Form\nJotform Template Slug: {$slug}\n*/\n?>\n{$body}\n"
        );
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
