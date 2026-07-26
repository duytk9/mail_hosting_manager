<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\AuthRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthRepositorySecurityTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    public function test_find_admin_by_login_is_scoped_to_active_tenant_lifecycle_in_sql(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Repositories/Pdo/AuthRepository.php');

        $this->assertStringContainsString('SELECT u.* FROM users u', $source);
        $this->assertStringContainsString('LEFT JOIN tenants user_tenant', $source);
        $this->assertStringContainsString('u.tenant_id IS NULL OR ', $source);
        $this->assertStringContainsString("TenantLifecyclePolicy::sqlMailAccessCondition('user_tenant')", $source);
    }

    public function test_find_admin_by_login_allows_super_admin_without_tenant(): void
    {
        $repository = $this->repository();
        $this->insertUser(1, null, 'super_admin', 'root-admin');

        $user = $repository->findAdminByLogin('root-admin');

        $this->assertSame(1, (int) ($user['id'] ?? 0));
    }

    public function test_find_admin_by_login_allows_active_tenant_admin(): void
    {
        $repository = $this->repository();
        $this->insertTenant(7, 'active', 'active', null, null);
        $this->insertUser(2, 7, 'tenant_admin', 'tenant-admin');

        $user = $repository->findAdminByLogin('tenant-admin');

        $this->assertSame(2, (int) ($user['id'] ?? 0));
    }

    public function test_find_admin_by_login_blocks_suspended_or_expired_tenant_admins(): void
    {
        $repository = $this->repository();
        $this->insertTenant(8, 'suspended', 'active', null, null);
        $this->insertTenant(9, 'active', 'expired', '2026-01-01 00:00:00', '2026-01-03 00:00:00');
        $this->insertUser(3, 8, 'tenant_admin', 'suspended-admin');
        $this->insertUser(4, 9, 'tenant_admin', 'expired-admin');

        $this->assertNull($repository->findAdminByLogin('suspended-admin'));
        $this->assertNull($repository->findAdminByLogin('expired-admin'));
    }

    private function repository(): AuthRepository
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite driver is not installed in this PHP runtime.');
        }

        $this->sqlitePath = sys_get_temp_dir() . '/mailpanel-auth-repo-' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = new Database([
            'driver' => 'sqlite',
            'sqlite_path' => $this->sqlitePath,
        ]);
        $pdo = $database->connection();
        $pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-07-06 00:00:00');
        $pdo->exec(
            'CREATE TABLE tenants (
                id INTEGER PRIMARY KEY,
                status TEXT NOT NULL,
                billing_status TEXT NULL,
                expires_at TEXT NULL,
                grace_until TEXT NULL,
                suspended_at TEXT NULL,
                terminated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NULL,
                role TEXT NOT NULL,
                linux_username TEXT NOT NULL,
                deleted_at TEXT NULL
            )'
        );

        return new AuthRepository($database);
    }

    private function insertTenant(int $id, string $status, string $billingStatus, ?string $expiresAt, ?string $graceUntil): void
    {
        $pdo = $this->repositoryPdo();
        $statement = $pdo->prepare(
            'INSERT INTO tenants (id, status, billing_status, expires_at, grace_until, suspended_at, terminated_at, deleted_at)
             VALUES (:id, :status, :billing_status, :expires_at, :grace_until, NULL, NULL, NULL)'
        );
        $statement->execute([
            'id' => $id,
            'status' => $status,
            'billing_status' => $billingStatus,
            'expires_at' => $expiresAt,
            'grace_until' => $graceUntil,
        ]);
    }

    private function insertUser(int $id, ?int $tenantId, string $role, string $linuxUsername): void
    {
        $pdo = $this->repositoryPdo();
        $statement = $pdo->prepare(
            'INSERT INTO users (id, tenant_id, role, linux_username, deleted_at)
             VALUES (:id, :tenant_id, :role, :linux_username, NULL)'
        );
        $statement->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'role' => $role,
            'linux_username' => $linuxUsername,
        ]);
    }

    private function repositoryPdo(): PDO
    {
        $this->assertNotNull($this->sqlitePath);

        return new PDO('sqlite:' . $this->sqlitePath);
    }
}
