<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Support\SafePath;
use PHPUnit\Framework\TestCase;

final class SafePathTest extends TestCase
{
    public function test_absolute_accepts_unix_and_windows_absolute_paths(): void
    {
        $this->assertSame('/var/www/webmail', SafePath::absolute('/var/www/webmail/', 'webmail root'));
        $this->assertSame('C:/webmail', SafePath::absolute('C:\\webmail\\', 'webmail root'));
    }

    public function test_absolute_rejects_relative_control_and_traversal_paths(): void
    {
        foreach (['var/www/webmail', "/var/www/../secret", "/var/www/webmail\nowned"] as $path) {
            try {
                SafePath::absolute($path, 'webmail root');
                $this->fail('Unsafe path was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('webmail root', $exception->getMessage());
            }
        }
    }

    public function test_absolute_rejects_filesystem_roots(): void
    {
        foreach (['/', 'C:/'] as $path) {
            try {
                SafePath::absolute($path, 'generated config root');
                $this->fail('Filesystem root path was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('filesystem root', $exception->getMessage());
            }
        }
    }
}
