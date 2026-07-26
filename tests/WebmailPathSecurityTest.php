<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\WebmailApplicationConfigService;
use MailPanel\Services\WebmailDomainConfigService;
use MailPanel\Services\WebmailHealthService;
use MailPanel\Services\WebmailPluginDeploymentService;
use MailPanel\Services\WebmailUserStorageService;
use PHPUnit\Framework\TestCase;

final class WebmailPathSecurityTest extends TestCase
{
    private ?string $tempRoot = null;

    protected function tearDown(): void
    {
        if ($this->tempRoot !== null && is_dir($this->tempRoot)) {
            $this->deleteDirectory($this->tempRoot);
        }

        parent::tearDown();
    }

    public function test_webmail_services_reject_unsafe_roots(): void
    {
        foreach ([
            fn () => new WebmailUserStorageService(true, 'relative/webmail'),
            fn () => new WebmailDomainConfigService(true, "/var/www/../secret"),
            fn () => new WebmailApplicationConfigService(true, "/var/www/webmail\nowned", '/var/www/webmail/logs/auth.log'),
            fn () => new WebmailPluginDeploymentService(true, 'webmail'),
            fn () => new WebmailHealthService(true, 'webmail', '/var/www/webmail/logs/auth.log'),
        ] as $factory) {
            try {
                $factory();
                $this->fail('Unsafe webmail path was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('webmail', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_webmail_application_rejects_unsafe_auth_log_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('webmail auth log path');

        new WebmailApplicationConfigService(true, '/var/www/webmail', "../auth.log");
    }

    public function test_webmail_plugin_rejects_unsafe_password_change_endpoint(): void
    {
        foreach (['https://panel.example/api/webmail/password-change', '//evil.example/path', '/api/../admin', "/api/webmail\nX: y"] as $endpoint) {
            try {
                new WebmailPluginDeploymentService(true, '/var/www/webmail', $endpoint);
                $this->fail('Unsafe webmail endpoint was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('password-change endpoint', $exception->getMessage());
            }
        }
    }

    public function test_webmail_plugin_deployment_writes_managed_files_atomically(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-webmail-' . bin2hex(random_bytes(4));
        $service = new WebmailPluginDeploymentService(true, $this->tempRoot);

        $result = $service->sync();

        $this->assertTrue($result['updated']);
        foreach ($result['files'] as $path) {
            $this->assertFileExists($path);
        }

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/WebmailPluginDeploymentService.php');
        $this->assertStringContainsString('LOCK_EX', $source);
        $this->assertStringContainsString('Unable to publish webmail plugin file.', $source);
    }

    public function test_webmail_user_storage_paths_stay_under_expected_storage_root(): void
    {
        $service = new WebmailUserStorageService(false, '/var/www/webmail');
        $result = $service->bootstrapMailbox('User.Name+tag@example.test', 'User Name');

        $this->assertFalse($result['updated']);
        $this->assertSame('/var/www/webmail/data/_data_/_default_/storage/example.test/user.name+tag', $result['storage_path']);
    }

    public function test_webmail_user_storage_writes_required_mailbox_files_with_locking(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-webmail-' . bin2hex(random_bytes(4));
        $service = new WebmailUserStorageService(true, $this->tempRoot);

        $result = $service->bootstrapMailbox('User.Name+tag@example.test', 'User Name');

        $this->assertTrue($result['updated']);
        foreach (['settings_path', 'settings_local_path', 'identities_path'] as $key) {
            $path = (string) $result[$key];
            $this->assertFileExists($path);
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
        }

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/WebmailUserStorageService.php');
        $this->assertStringContainsString('LOCK_EX', $source);
        $this->assertStringContainsString('Unable to write webmail mailbox settings.', $source);
        $this->assertStringContainsString('Unable to publish webmail mailbox settings.', $source);
    }

    public function test_webmail_user_storage_purge_removes_symlink_without_following_target(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-webmail-' . bin2hex(random_bytes(4));
        $targetRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-webmail-target-' . bin2hex(random_bytes(4));
        mkdir($targetRoot, 0775, true);
        file_put_contents($targetRoot . '/keep.txt', 'keep');

        $linkParent = $this->tempRoot . '/data/_data_/_default_/storage/example.test';
        mkdir($linkParent, 0775, true);
        $linkPath = $linkParent . '/user';

        if (!@symlink($targetRoot, $linkPath)) {
            $this->deleteDirectory($targetRoot);
            $this->markTestSkipped('Symlink creation is not available in this environment.');
        }

        try {
            $service = new WebmailUserStorageService(true, $this->tempRoot);
            $result = $service->purgeMailbox('user@example.test');

            $this->assertTrue($result['purged']);
            $this->assertFileExists($targetRoot . '/keep.txt');
            $this->assertFileDoesNotExist($linkPath);
        } finally {
            $this->deleteDirectory($targetRoot);
        }
    }

    public function test_webmail_domain_manifest_cannot_delete_paths_outside_domains_root(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-webmail-' . bin2hex(random_bytes(4));
        $domainsRoot = $this->tempRoot . '/data/_data_/_default_/domains';
        mkdir($domainsRoot, 0775, true);

        $outsidePath = dirname($domainsRoot) . '/evil.json';
        $oldManagedPath = $domainsRoot . '/old.example.test.json';
        file_put_contents($outsidePath, 'do-not-delete');
        file_put_contents($oldManagedPath, '{}');
        file_put_contents($domainsRoot . '/.mailpanel-managed.json', json_encode([
            '../evil',
            'old.example.test',
        ], JSON_THROW_ON_ERROR));

        $service = new WebmailDomainConfigService(true, $this->tempRoot);
        $service->syncManagedDomains([
            ['domain' => 'new.example.test'],
        ]);

        $this->assertFileExists($outsidePath);
        $this->assertFileDoesNotExist($oldManagedPath);
        $this->assertFileExists($domainsRoot . '/new.example.test.json');

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/WebmailDomainConfigService.php');
        $this->assertStringContainsString('isManagedDomainName', $source);
        $this->assertStringContainsString('LOCK_EX', $source);
        $this->assertStringContainsString('Unable to publish webmail domain config.', $source);
    }

    public function test_webmail_application_config_rejects_multiline_ini_values_and_writes_atomically(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-webmail-' . bin2hex(random_bytes(4));
        $configRoot = $this->tempRoot . '/data/_data_/_default_/configs';
        mkdir($configRoot, 0775, true);
        file_put_contents($configRoot . '/application.ini', "[webmail]\ntitle = \"Old\"\n");

        $service = new WebmailApplicationConfigService(
            true,
            $this->tempRoot,
            $this->tempRoot . '/logs/auth.log',
            "Safe title\n[security]\nallow_admin_panel = On"
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('webmail application config value');

        try {
            $service->sync();
        } finally {
            $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/WebmailApplicationConfigService.php');
            $this->assertStringContainsString('LOCK_EX', $source);
            $this->assertStringContainsString('Unable to publish webmail application config.', $source);
        }
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $childPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($childPath) && !is_link($childPath)) {
                $this->deleteDirectory($childPath);
                continue;
            }

            @unlink($childPath);
        }

        @rmdir($path);
    }
}
