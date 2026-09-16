<?php

declare(strict_types=1);

namespace JotformBridge\Tests\Unit\Support;

use Brain\Monkey\Functions;
use JotformBridge\Settings\Settings;
use JotformBridge\Support\Logger;
use JotformBridge\Tests\TestCase;

final class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        @unlink(WP_CONTENT_DIR . '/jotform-bridge-logs.php');
        Functions\when('JotformBridge\Support\fwrite')->alias(
            static fn($file, string $data) => \fwrite($file, $data)
        );
    }

    protected function tearDown(): void
    {
        @unlink(WP_CONTENT_DIR . '/jotform-bridge-logs.php');
        parent::tearDown();
    }

    public function testDisabledLoggingDoesNotCreateAFile(): void
    {
        Functions\when('get_option')->justReturn(['debug_logging' => false]);
        $logger = new Logger(new Settings());
        $logger->error('Ignored');
        $this->assertFileDoesNotExist($logger->path());
        $this->assertTrue($logger->clear());
        $this->assertFileDoesNotExist($logger->path());
    }

    public function testAppendAndClearPreserveTheProtectiveHeaderAndRedactSecrets(): void
    {
        Functions\when('get_option')->justReturn(['debug_logging' => true]);
        $logger = new Logger(new Settings());
        $logger->error('First entry', ['password' => 'private-password', 'status' => 500]);
        $logger->debug('Second entry');
        $data = file_get_contents($logger->path());
        $this->assertStringStartsWith("<?php exit; ?>\n", $data);
        $this->assertSame(1, substr_count($data, '<?php exit; ?>'));
        $this->assertStringContainsString('UTC][jotform-bridge][ERROR]', $data);
        $this->assertStringContainsString('Second entry', $data);
        $this->assertStringContainsString('[redacted]', $data);
        $this->assertStringNotContainsString('private-password', $data);
        $this->assertTrue($logger->clear());
        $this->assertSame("<?php exit; ?>\n", file_get_contents($logger->path()));
        $logger->debug('After cleanup');
        $this->assertStringContainsString('After cleanup', file_get_contents($logger->path()));
    }
}
