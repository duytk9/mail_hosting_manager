<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\PasswordPolicyService;
use MailPanel\Services\SuperAdminService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SuperAdminServiceTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    public function test_reset_password_syncs_linux_and_forces_rotation(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $database->connection()->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, ssh_enabled, ssh_sudo_enabled, force_password_change, created_at, updated_at)
             VALUES (7, NULL, 'super_admin', 'Ops', 'ops@example.test', 'mp-admin-ops', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 1, 1, 0, NOW(), NOW())"
        );

        $linuxAccounts = $this->createMock(SuperAdminLinuxAccountManager::class);
        $newPassword = 'ResetStrong123!';
        $linuxAccounts->expects($this->once())
            ->method('syncAccount')
            ->with('mp-admin-ops', true, true, null, $newPassword);

        $passwordHasher = new PasswordHashingService('bcrypt');
        $service = new SuperAdminService(
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            $linuxAccounts,
            new AuditLogService($database),
            new PasswordPolicyService(),
            $passwordHasher
        );

        $result = $service->resetPassword(7, $newPassword);
        $fresh = (new UserRepository($database))->find(7);
        $history = $database->connection()->query('SELECT * FROM user_password_history WHERE user_id = 7')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertTrue((bool) ($result['password_reset'] ?? false));
        $this->assertArrayNotHasKey('generated_password', $result);
        $this->assertTrue($passwordHasher->verify($newPassword, (string) ($fresh['password_hash'] ?? '')));
        $this->assertSame(1, (int) ($fresh['force_password_change'] ?? 0));
        $this->assertCount(1, $history);
    }

    public function test_security_lock_blocks_self_lock_and_can_be_cleared(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $database->connection()->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, ssh_enabled, ssh_sudo_enabled, force_password_change, created_at, updated_at)
             VALUES
             (7, NULL, 'super_admin', 'Ops', 'ops@example.test', 'mp-admin-ops', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 1, 1, 0, NOW(), NOW()),
             (8, NULL, 'super_admin', 'Peer', 'peer@example.test', 'mp-admin-peer', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 1, 0, 0, NOW(), NOW())"
        );

        $service = new SuperAdminService(
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            $this->createMock(SuperAdminLinuxAccountManager::class),
            new AuditLogService($database),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt')
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->setSecurityLock(7, true, 7);
    }

    public function test_security_lock_toggle_updates_user_and_audit_log(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $database->connection()->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, ssh_enabled, ssh_sudo_enabled, force_password_change, created_at, updated_at)
             VALUES
             (7, NULL, 'super_admin', 'Ops', 'ops@example.test', 'mp-admin-ops', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 1, 1, 0, NOW(), NOW()),
             (8, NULL, 'super_admin', 'Peer', 'peer@example.test', 'mp-admin-peer', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 1, 0, 0, NOW(), NOW())"
        );

        $service = new SuperAdminService(
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            $this->createMock(SuperAdminLinuxAccountManager::class),
            new AuditLogService($database),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt')
        );

        $locked = $service->setSecurityLock(8, true, 7);
        $this->assertNotEmpty($locked['security_locked_at']);
        $this->assertSame('manual_super_admin_lock', $locked['security_lock_reason']);

        $unlocked = $service->setSecurityLock(8, false, 7);
        $this->assertEmpty($unlocked['security_locked_at']);
        $this->assertEmpty($unlocked['security_lock_reason']);

        $actions = $database->connection()->query('SELECT action FROM audit_logs ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('super_admin.security_locked', $actions);
        $this->assertContains('super_admin.security_unlocked', $actions);
    }

    private function makeDatabase(): Database
    {
        $this->sqlitePath = sys_get_temp_dir() . '/mailpanel-super-admin-' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = new Database([
            'driver' => 'sqlite',
            'sqlite_path' => $this->sqlitePath,
        ]);

        $pdo = $database->connection();
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                role TEXT NOT NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                linux_username TEXT NULL,
                password_hash TEXT NOT NULL,
                ssh_enabled INTEGER NOT NULL DEFAULT 0,
                ssh_sudo_enabled INTEGER NOT NULL DEFAULT 0,
                ssh_public_key TEXT NULL,
                force_password_change INTEGER NOT NULL DEFAULT 0,
                password_changed_at TEXT NULL,
                last_login_at TEXT NULL,
                totp_secret TEXT NULL,
                totp_pending_secret TEXT NULL,
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                totp_confirmed_at TEXT NULL,
                totp_grace_login_count INTEGER NOT NULL DEFAULT 0,
                security_locked_at TEXT NULL,
                security_lock_reason TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE user_password_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                user_id INTEGER NOT NULL,
                password_hash TEXT NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER NULL,
                actor_role TEXT NOT NULL,
                tenant_id INTEGER NULL,
                action TEXT NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NULL,
                old_values TEXT NULL,
                new_values TEXT NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );

        return $database;
    }

    private function requireSqliteDriver(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite driver is not installed in this PHP runtime.');
        }
    }
}
