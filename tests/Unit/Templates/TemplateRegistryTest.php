<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Templates;

use Brain\Monkey\Functions;
use JotformBridge\Settings\Settings;
use JotformBridge\Support\Logger;
use JotformBridge\Templates\TemplateRegistry;
use JotformBridge\Tests\TestCase;

final class TemplateRegistryTest extends TestCase
{
    private string $root = '';

    /** @var array<string, mixed> */
    private array $options = [];

    /** @var array<int, string> Lines the Logger wrote during a test. */
    private array $logLines = [];

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
        $this->write('contact.php', 'Contact Form', '<input data-jotform-field="email">');

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
        $this->write('contact.php', 'Contact Form', '<input data-jotform-field="email">');

        (new TemplateRegistry())->all();

        $this->write('consultation.php', 'Consultation', '');

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
        $this->write('contact.php', 'Contact Form', '<input data-jotform-field="email">');

        $this->assertSame(['email'], (new TemplateRegistry())->fields('contact'));

        $this->write(
            'contact.php',
            'Contact Form',
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
        $this->write('contact.php', 'Contact Form', '');

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
        $this->write('contact.php', 'Contact Form', '<input data-jotform-field="email">');

        $registry = new TemplateRegistry();

        $registry->all();

        $this->write('consultation.php', 'Consultation', '');

        $this->assertSame(['contact'], array_keys($registry->all()));

        $registry->flush();

        $this->assertSame(['consultation', 'contact'], array_keys($registry->all()));
    }

    public function testAnUnknownSlugHasNoFile(): void
    {
        $this->write('contact.php', 'Contact Form', '');

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
        $this->write('contact.php', 'Contact Form', '');

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
        $this->write('contact.php', 'Contact Form', '');

        $registry = new TemplateRegistry();

        $this->assertSame(realpath($this->root . '/theme/jotform-bridge-templates/contact.php'), $registry->file('contact'));
    }

    public function testAVanishedFileIsNotHandedOutFromTheCache(): void
    {
        $this->write('contact.php', 'Contact Form', '');

        $registry = new TemplateRegistry();
        $registry->all();

        unlink($this->root . '/theme/jotform-bridge-templates/contact.php');

        // The entry is still memoized for this request, but a path that is gone
        // must never be handed to the renderer.
        $this->assertTrue($registry->has('contact'));
        $this->assertNull($registry->file('contact'));
    }

    /**
     * The integrations screen used to carry a "Template diagnostics" list. It
     * was removed because nobody could act on it; the reason a file was skipped
     * still has to reach somebody, so it goes to the debug log instead.
     */
    public function testASkippedFileIsReportedToTheDebugLog(): void
    {
        file_put_contents(
            $this->root . '/theme/jotform-bridge-templates/broken.php',
            "<?php\n/*\nJotform Template Slug: broken\n*/\n"
        );

        $this->captureLog();

        (new TemplateRegistry(new Logger(new Settings())))->all();

        $written = implode("\n", $this->logLines);

        $this->assertStringContainsString('Template skipped', $written);
        $this->assertStringContainsString('missing_name', $written);
        $this->assertStringContainsString('broken.php', $written);
    }

    /**
     * A child theme overriding a parent template is the mechanism working, not
     * an incident, so the notice is dropped rather than logged.
     */
    public function testAnOverrideNoticeIsNotLogged(): void
    {
        mkdir($this->root . '/parent/jotform-bridge-templates', 0777, true);
        Functions\when('get_template_directory')->justReturn($this->root . '/parent');

        file_put_contents(
            $this->root . '/parent/jotform-bridge-templates/contact.php',
            "<?php\n/*\nJotform Template Name: Parent Contact\n*/\n"
        );
        $this->write('contact.php', 'Child Contact', '');

        $this->captureLog();

        $registry = new TemplateRegistry(new Logger(new Settings()));

        $this->assertSame('Child Contact', $registry->all()['contact']['name']);
        $this->assertSame([], $this->logLines);
    }

    /**
     * Nothing is written when the site owner has not asked for a log.
     */
    public function testNothingIsLoggedWhileDebugLoggingIsOff(): void
    {
        file_put_contents(
            $this->root . '/theme/jotform-bridge-templates/broken.php',
            "<?php\n/*\nJotform Template Slug: broken\n*/\n"
        );

        $this->captureLog(false);

        (new TemplateRegistry(new Logger(new Settings())))->all();

        $this->assertSame([], $this->logLines);
    }

    /**
     * Collects what the Logger writes, so a test can read it back.
     */
    private function captureLog(bool $debug = true): void
    {
        $this->options[Settings::OPTION] = ['debug_logging' => $debug];
        $this->logLines                  = [];

        Functions\when('JotformBridge\Support\error_log')->alias(
            function (string $line): bool {
                $this->logLines[] = $line;

                return true;
            }
        );
    }

    private function write(string $file, string $name, string $body): void
    {
        file_put_contents(
            $this->root . '/theme/jotform-bridge-templates/' . $file,
            "<?php\n/*\nJotform Template Name: {$name}\n*/\n?>\n{$body}\n"
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
