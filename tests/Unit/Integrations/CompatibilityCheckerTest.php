<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Integrations;

use Brain\Monkey\Functions;
use JotformBridge\Api\JotformClient;
use JotformBridge\Forms\SchemaBuilder;
use JotformBridge\Forms\SchemaRepository;
use JotformBridge\Integrations\CompatibilityChecker;
use JotformBridge\Integrations\Integration;
use JotformBridge\Templates\CompatibilityReport;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Tests\TestCase;

/**
 * The checker is the seam the admin screens use: cached schema plus cached
 * registry, never a network or filesystem round trip of its own.
 */
final class CompatibilityCheckerTest extends TestCase
{
    private const FORM_ID = '240000000000001';

    private string $root = '';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<string, mixed> */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root       = sys_get_temp_dir() . '/jfb-compat-' . uniqid('', true);
        $this->options    = [];
        $this->transients = [];

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
        Functions\when('delete_transient')->alias(
            function (string $name): bool {
                unset($this->transients[$name]);

                return true;
            }
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testAnIntegrationWithoutACachedSchemaReportsThat(): void
    {
        $this->writeTemplate('contact', '<input data-jotform-field="email">');

        $result = $this->checker()->check($this->integration());

        $this->assertSame(CompatibilityChecker::STATE_NO_SCHEMA, $result['state']);
        $this->assertNull($result['report']);
    }

    public function testAnIntegrationPointingAtAnUnregisteredTemplateReportsThat(): void
    {
        $this->cacheSchema();

        $result = $this->checker()->check($this->integration());

        $this->assertSame(CompatibilityChecker::STATE_NO_TEMPLATE, $result['state']);
    }

    public function testAutoModeReportsWhatTheSchemaItselfWillProduce(): void
    {
        $this->cacheSchema();

        $integration = new Integration('contact', 'Contact', self::FORM_ID, Integration::MODE_AUTO, '', true);

        $result = $this->checker()->check($integration);

        $this->assertSame(CompatibilityChecker::STATE_AUTO, $result['state']);
        $this->assertInstanceOf(CompatibilityReport::class, $result['report']);

        // Auto rendering covers every supported path by construction, so a
        // schema the plugin fully understands can never come back incompatible.
        $this->assertSame(CompatibilityReport::STATUS_COMPATIBLE, $result['report']->status());
        $this->assertStringContainsString('2', $result['message'], 'Both inputs are counted.');
    }

    public function testAutoModeReportsTheFieldsItHasToLeaveOut(): void
    {
        $this->cacheSchemaWithUnsupportedField();

        $integration = new Integration('contact', 'Contact', self::FORM_ID, Integration::MODE_AUTO, '', true);

        $result = $this->checker()->check($integration);

        $this->assertSame(CompatibilityReport::STATUS_WARNINGS, $result['report']->status());
        $this->assertSame(
            CompatibilityReport::UNSUPPORTED_OPTIONAL,
            $result['report']->warnings()[0]['status']
        );
        $this->assertStringContainsString('not supported', $result['message']);
    }

    public function testAMatchingTemplateIsCompatible(): void
    {
        $this->cacheSchema();
        $this->writeTemplate(
            'contact',
            '<input data-jotform-field="email"><input data-jotform-field="company">'
        );

        $result = $this->checker()->check($this->integration());

        $this->assertSame(CompatibilityChecker::STATE_OK, $result['state']);
        $this->assertInstanceOf(CompatibilityReport::class, $result['report']);
        $this->assertSame(CompatibilityReport::STATUS_COMPATIBLE, $result['report']->status());
    }

    public function testRemovingARequiredFieldTurnsTheIntegrationInvalid(): void
    {
        $this->cacheSchema();
        $this->writeTemplate(
            'contact',
            '<input data-jotform-field="email"><input data-jotform-field="company">'
        );

        $this->assertSame(
            CompatibilityReport::STATUS_COMPATIBLE,
            $this->checker()->check($this->integration())['report']->status()
        );

        // The developer drops the required identifier and rescans.
        $this->writeTemplate('contact', '<input data-jotform-field="company">');

        $registry = new TemplateRegistry();
        $registry->rescan();

        $result = $this->checker($registry)->check($this->integration());

        $this->assertSame(CompatibilityReport::STATUS_INVALID, $result['report']->status());
        $this->assertSame(
            CompatibilityReport::MISSING_REQUIRED,
            $result['report']->errors()[0]['status']
        );
    }

    private function checker(?TemplateRegistry $registry = null): CompatibilityChecker
    {
        return new CompatibilityChecker(
            new SchemaRepository(new JotformClient('', 'https://api.jotform.com')),
            $registry ?? new TemplateRegistry()
        );
    }

    private function integration(): Integration
    {
        return new Integration('contact', 'Contact', self::FORM_ID, Integration::MODE_CUSTOM, 'contact', true);
    }

    private function cacheSchema(): void
    {
        $schema = (new SchemaBuilder())->build(
            self::FORM_ID,
            [
                [
                    'qid'      => '4',
                    'type'     => 'control_email',
                    'text'     => 'E-mail',
                    'name'     => 'email',
                    'order'    => '1',
                    'required' => 'Yes',
                ],
                [
                    'qid'      => '7',
                    'type'     => 'control_textbox',
                    'text'     => 'Company',
                    'name'     => 'company',
                    'order'    => '2',
                    'required' => 'No',
                ],
            ]
        );

        $this->options[SchemaRepository::optionKey(self::FORM_ID)] = $schema->toArray();
    }

    private function cacheSchemaWithUnsupportedField(): void
    {
        $schema = (new SchemaBuilder())->build(
            self::FORM_ID,
            [
                [
                    'qid'      => '4',
                    'type'     => 'control_email',
                    'text'     => 'E-mail',
                    'name'     => 'email',
                    'order'    => '1',
                    'required' => 'Yes',
                ],
                [
                    'qid'      => '14',
                    'type'     => 'control_fileupload',
                    'text'     => 'Attachment',
                    'name'     => 'attachment',
                    'order'    => '2',
                    'required' => 'No',
                ],
            ]
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
