<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Templates;

use Brain\Monkey\Functions;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Tests\TestCase;

final class TemplateRegistryTest extends TestCase
{
    private string $root = '';

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->root    = sys_get_temp_dir() . '/jfb-registry-' . uniqid('', true);
        $this->options = [];

        mkdir($this->root . '/theme/jotform-bridge-templates', 0777, true);

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
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);

        parent::tearDown();
    }

    public function testAReadFindsWhatIsOnDiskRightNow(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '<input data-jotform-field="email">');

        $registry = new TemplateRegistry();

        $this->assertSame(['contact'], array_keys($registry->all()));
        $this->assertSame(['contact' => 'Contact Form'], $registry->choices());
        $this->assertSame(['email'], $registry->fields('contact'));
    }

    /**
     * The behaviour this class exists for: drop a file into the theme and it is
     * there. No button, no cache to invalidate, nothing to remember.
     */
    public function testANewFileIsPickedUpWithoutAnyRefresh(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '<input data-jotform-field="email">');

        (new TemplateRegistry())->all();

        $this->write('consultation.php', 'Consultation', 'consultation', '');

        $this->assertSame(
            ['consultation', 'contact'],
            array_keys((new TemplateRegistry())->all())
        );
    }

    /**
     * An edited template must not keep reporting the fields it used to declare
     * — that is what made the compatibility report lie under the old cache.
     */
    public function testAnEditedTemplateReportsItsNewFields(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '<input data-jotform-field="email">');

        $this->assertSame(['email'], (new TemplateRegistry())->fields('contact'));

        $this->write(
            'contact.php',
            'Contact Form',
            'contact',
            '<input data-jotform-field="email"><input data-jotform-field="message">'
        );

        $this->assertSame(['email', 'message'], (new TemplateRegistry())->fields('contact'));
    }

    /**
     * A deleted file disappears from the list, so nothing can be rendered from
     * a path that no longer exists.
     */
    public function testADeletedTemplateDisappears(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '');

        $this->assertTrue((new TemplateRegistry())->has('contact'));

        unlink($this->root . '/theme/jotform-bridge-templates/contact.php');

        $registry = new TemplateRegistry();

        $this->assertFalse($registry->has('contact'));
        $this->assertNull($registry->file('contact'));
    }

    /**
     * Cheap to read repeatedly, but not free: within one request the answer
     * cannot change, so the directory is walked once.
     */
    public function testTheScanIsMemoizedWithinOneRequest(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '<input data-jotform-field="email">');

        $registry = new TemplateRegistry();

        $registry->all();

        $this->write('consultation.php', 'Consultation', 'consultation', '');

        $this->assertSame(['contact'], array_keys($registry->all()));

        $registry->flush();

        $this->assertSame(['consultation', 'contact'], array_keys($registry->all()));
    }

    public function testAnUnknownSlugHasNoFile(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '');

        $registry = new TemplateRegistry();

        $this->assertNull($registry->file('does-not-exist'));
        $this->assertNull($registry->file('../../wp-config'));
        $this->assertFalse($registry->has('does-not-exist'));
    }

    /**
     * The registry is the allowlist: a slug that names a path — relative,
     * absolute or URL-shaped — resolves to nothing, whether or not the file it
     * points at exists and is readable.
     *
     * @dataProvider pathShapedSlugs
     */
    public function testAPathShapedSlugNeverResolvesToAFile(string $slug): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '');

        $registry = new TemplateRegistry();

        $this->assertFalse($registry->has($slug), sprintf('"%s" must not be a template.', $slug));
        $this->assertNull($registry->file($slug), sprintf('"%s" must not resolve to a file.', $slug));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public function pathShapedSlugs(): array
    {
        return [
            'parent traversal'    => ['../../wp-config'],
            'encoded traversal'   => ['%2e%2e%2fwp-config'],
            'absolute path'       => ['/etc/passwd'],
            'absolute php file'   => [__FILE__],
            'the real template'   => ['theme/jotform-bridge-templates/contact.php'],
            'null byte'           => ["contact\0.php"],
            'remote url'          => ['https://evil.test/shell.php'],
            'stream wrapper'      => ['php://input'],
        ];
    }

    public function testOnlyARegisteredTemplateResolvesToAFile(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '');

        $registry = new TemplateRegistry();

        $this->assertSame(realpath($this->root . '/theme/jotform-bridge-templates/contact.php'), $registry->file('contact'));
    }

    public function testAVanishedFileIsNotHandedOutFromTheCache(): void
    {
        $this->write('contact.php', 'Contact Form', 'contact', '');

        $registry = new TemplateRegistry();
        $registry->all();

        unlink($this->root . '/theme/jotform-bridge-templates/contact.php');

        // The entry is still memoized for this request, but a path that is gone
        // must never be handed to the renderer.
        $this->assertTrue($registry->has('contact'));
        $this->assertNull($registry->file('contact'));
    }

    private function write(string $file, string $name, string $slug, string $body): void
    {
        file_put_contents(
            $this->root . '/theme/jotform-bridge-templates/' . $file,
            "<?php\n/*\nJotform Template Name: {$name}\nJotform Template Slug: {$slug}\n*/\n?>\n{$body}\n"
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
