<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\PasswordPolicyService;
use MailPanel\Services\TenantAdminService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TenantAdminServiceTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    public function test_update_can_reset_password_and_force_rotation(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $pdo = $database->connection();

        $pdo->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, max_domains, max_mailboxes, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, admin_user_id, created_at, updated_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 10, 10, 4096, 1024, 0, 0, 10, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO domains (id, tenant_id, domain, status, is_primary, created_at, updated_at)
             VALUES (3, 7, 'tenant-a.test', 'active', 1, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, force_password_change, created_at, updated_at)
             VALUES (10, 7, 'tenant_admin', 'Old Admin', 'old@example.test', 'tenanta-admin', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 0, NOW(), NOW())"
        );

        $passwordHasher = new PasswordHashingService('bcrypt');
        $linuxAccounts = $this->createMock(SuperAdminLinuxAccountManager::class);
        $linuxAccounts->expects($this->once())
            ->method('syncAccount')
            ->with('tenanta-admin', false, false, null, $this->isType('string'));
        $service = new TenantAdminService(
            new TenantRepository($database),
            new DomainRepository($database),
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            new AuditLogService($database),
            new PasswordPolicyService(),
            $passwordHasher,
            $linuxAccounts
        );

        $result = $service->update(10, [
            'name' => 'New Admin',
            'local_part' => 'new',
            'reset_password' => true,
        ]);

        $fresh = (new UserRepository($database))->find(10);
        $history = $pdo->query('SELECT * FROM user_password_history WHERE user_id = 10')->fetchAll(PDO::FETCH_ASSOC);
        $auditLogs = $pdo->query("SELECT * FROM audit_logs WHERE action = 'tenant_admin.updated'")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('New Admin', $result['user']['name']);
        $this->assertSame('new@tenant-a.test', $result['user']['email']);
        $this->assertNotEmpty($result['generated_password']);
        $this->assertSame('New Admin', $fresh['name'] ?? null);
        $this->assertSame('new@tenant-a.test', $fresh['email'] ?? null);
        $this->assertSame(1, (int) ($fresh['force_password_change'] ?? 0));
        $this->assertCount(1, $history);
        $this->assertCount(1, $auditLogs);
        $this->assertTrue($passwordHasher->verify((string) $result['generated_password'], (string) ($fresh['password_hash'] ?? '')));
    }

    public function test_delete_reassigns_tenant_admin_to_replacement_account(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $pdo = $database->connection();

        $pdo->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, max_domains, max_mailboxes, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, admin_user_id, created_at, updated_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 10, 10, 4096, 1024, 0, 0, 10, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, force_password_change, created_at, updated_at)
             VALUES
             (10, 7, 'tenant_admin', 'Admin A', 'admin-a@example.test', 'tenanta-admin-a', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 0, NOW(), NOW()),
             (11, 7, 'tenant_admin', 'Admin B', 'admin-b@example.test', 'tenanta-admin-b', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 0, NOW(), NOW())"
        );

        $linuxAccounts = $this->createMock(SuperAdminLinuxAccountManager::class);
        $linuxAccounts->expects($this->once())
            ->method('purge')
            ->with('tenanta-admin-a');
        $service = new TenantAdminService(
            new TenantRepository($database),
            new DomainRepository($database),
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            new AuditLogService($database),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt'),
            $linuxAccounts
        );

        $service->delete(10);

        $tenant = (new TenantRepository($database))->find(7);
        $deletedCount = $pdo->query('SELECT COUNT(*) FROM users WHERE id = 10')->fetchColumn();
        $auditLogs = $pdo->query("SELECT * FROM audit_logs WHERE action = 'tenant_admin.deleted'")->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame(0, (int) $deletedCount);
        $this->assertSame(11, (int) ($tenant['admin_user_id'] ?? 0));
        $this->assertCount(1, $auditLogs);
    }

    public function test_create_for_primary_domain_provisions_linux_login_username(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $pdo = $database->connection();

        $pdo->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, max_domains, max_mailboxes, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, admin_user_id, created_at, updated_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 10, 10, 4096, 1024, 0, 0, NULL, NOW(), NOW())"
        );
        $pdo->exec(
            "INSERT INTO domains (id, tenant_id, domain, status, is_primary, created_at, updated_at)
             VALUES (3, 7, 'tenant-a.test', 'active', 1, NOW(), NOW())"
        );

        $linuxAccounts = $this->createMock(SuperAdminLinuxAccountManager::class);
        $linuxAccounts->expects($this->once())
            ->method('syncAccount')
            ->with('tenanta-admin', false, false, null, 'TenantTemp123!');

        $service = new TenantAdminService(
            new TenantRepository($database),
            new DomainRepository($database),
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            new AuditLogService($database),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt'),
            $linuxAccounts
        );

        $user = $service->createForPrimaryDomain([
            'tenant_id' => 7,
            'name' => 'Tenant Admin',
            'local_part' => 'admin',
            'linux_username' => 'tenanta-admin',
            'password' => 'TenantTemp123!',
            'force_password_change' => 1,
        ]);

        $this->assertSame('tenanta-admin', $user['linux_username'] ?? null);
        $this->assertSame('admin@tenant-a.test', $user['email'] ?? null);
        $this->assertSame(1, (int) ($user['force_password_change'] ?? 0));
    }

    public function test_update_requires_password_reset_when_linux_username_changes(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $database->connection()->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, max_domains, max_mailboxes, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, admin_user_id, created_at, updated_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 10, 10, 4096, 1024, 0, 0, 10, NOW(), NOW())"
        );
        $database->connection()->exec(
            "INSERT INTO domains (id, tenant_id, domain, status, is_primary, created_at, updated_at)
             VALUES (3, 7, 'tenant-a.test', 'active', 1, NOW(), NOW())"
        );
        $database->connection()->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, force_password_change, created_at, updated_at)
             VALUES (10, 7, 'tenant_admin', 'Old Admin', 'old@example.test', 'tenant-old', '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 0, NOW(), NOW())"
        );

        $service = new TenantAdminService(
            new TenantRepository($database),
            new DomainRepository($database),
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            new AuditLogService($database),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt'),
            $this->createMock(SuperAdminLinuxAccountManager::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->update(10, [
            'name' => 'Old Admin',
            'local_part' => 'old',
            'linux_username' => 'tenant-new',
            'reset_password' => false,
        ]);
    }

    public function test_update_requires_linux_username_for_tenant_admin(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $database->connection()->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, max_domains, max_mailboxes, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, admin_user_id, created_at, updated_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 10, 10, 4096, 1024, 0, 0, 10, NOW(), NOW())"
        );
        $database->connection()->exec(
            "INSERT INTO domains (id, tenant_id, domain, status, is_primary, created_at, updated_at)
             VALUES (3, 7, 'tenant-a.test', 'active', 1, NOW(), NOW())"
        );
        $database->connection()->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, linux_username, password_hash, force_password_change, created_at, updated_at)
             VALUES (10, 7, 'tenant_admin', 'Old Admin', 'old@tenant-a.test', NULL, '{BLF-CRYPT}\$2y\$10\$abcdefghijklmnopqrstuv12345678901234567890123456', 0, NOW(), NOW())"
        );

        $service = new TenantAdminService(
            new TenantRepository($database),
            new DomainRepository($database),
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            new AuditLogService($database),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt'),
            $this->createMock(SuperAdminLinuxAccountManager::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->update(10, [
            'name' => 'Old Admin',
            'local_part' => 'old',
            'linux_username' => '',
            'reset_password' => false,
        ]);
    }

    private function makeDatabase(): Database
    {
        $this->sqlitePath = sys_get_temp_dir() . '/mailpanel-tenant-admin-' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = new Database([
            'driver' => 'sqlite',
            'sqlite_path' => $this->sqlitePath,
        ]);

        $pdo = $database->connection();
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

        $pdo->exec(
            'CREATE TABLE tenants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT "active",
                billing_status TEXT NOT NULL DEFAULT "active",
                starts_at TEXT NULL,
                expires_at TEXT NULL,
                grace_until TEXT NULL,
                suspended_at TEXT NULL,
                terminated_at TEXT NULL,
                package_id INTEGER NOT NULL,
                max_domains INTEGER NOT NULL DEFAULT 0,
                max_mailboxes INTEGER NOT NULL DEFAULT 0,
                max_total_quota_mb INTEGER NOT NULL DEFAULT 0,
                default_mailbox_quota_mb INTEGER NOT NULL DEFAULT 0,
                allow_catchall INTEGER NOT NULL DEFAULT 0,
                allow_external_forwarding INTEGER NOT NULL DEFAULT 0,
                admin_user_id INTEGER NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
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
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "active",
                is_primary INTEGER NOT NULL DEFAULT 0,
                deleted_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
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
