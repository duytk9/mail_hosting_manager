<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Support\AppSecuritySettingsStore;
use PHPUnit\Framework\TestCase;

final class AppSecuritySettingsStoreTest extends TestCase
{
    public function test_path_rejects_unsafe_application_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('application root');

        AppSecuritySettingsStore::path('../mailpanel');
    }

    public function test_save_and_load_normalize_allowlist_entries(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-settings-' . bin2hex(random_bytes(4));

        try {
            AppSecuritySettingsStore::save($root, [
                'super_admin_ip_allowlist_enabled' => true,
                'super_admin_ip_allowlist' => [' 203.0.113.10 ', '203.0.113.10', '198.51.100.0/24'],
            ]);

            $loaded = AppSecuritySettingsStore::load($root);

            $this->assertTrue($loaded['super_admin_ip_allowlist_enabled']);
            $this->assertSame(['203.0.113.10', '198.51.100.0/24'], $loaded['super_admin_ip_allowlist']);
            $this->assertSame([], glob(AppSecuritySettingsStore::path($root) . '.tmp.*') ?: []);
        } finally {
            $path = AppSecuritySettingsStore::path($root);
            if (is_file($path)) {
                @unlink($path);
            }

            $directory = dirname($path);
            if (is_dir($directory)) {
                @rmdir($directory);
                @rmdir(dirname($directory));
            }
        }
    }

    public function test_save_uses_random_temporary_file_and_restricted_permissions(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Support/AppSecuritySettingsStore.php');

        $this->assertStringContainsString("'.tmp.' . bin2hex(random_bytes(8))", $source);
        $this->assertStringContainsString('@chmod($tempPath, 0640)', $source);
        $this->assertStringContainsString('Unsafe application settings directory.', $source);
    }
}
