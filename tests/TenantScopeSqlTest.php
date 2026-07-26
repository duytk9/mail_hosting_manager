<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Tenant isolation must be enforced by the SQL WHERE clause, not by filtering an
 * array after loading the whole table.
 *
 * Replaces AdminWebControllerScopeTest, which tested the old array-side helpers
 * on a controller class (AdminWebController) that no longer exists.
 */
final class TenantScopeSqlTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $tables = [
            'domains' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, domain TEXT, deleted_at TEXT',
            'mailboxes' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, domain_id INTEGER, email TEXT, deleted_at TEXT',
            'aliases' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, domain_id INTEGER, source_address TEXT, deleted_at TEXT',
            'forwards' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, domain_id INTEGER, source_address TEXT, deleted_at TEXT',
            'mail_groups' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, domain_id INTEGER, email TEXT, deleted_at TEXT',
            'users' => 'id INTEGER PRIMARY KEY, tenant_id INTEGER, role TEXT, email TEXT, deleted_at TEXT',
        ];

        foreach ($tables as $table => $columns) {
            $this->pdo->exec("CREATE TABLE {$table} ({$columns})");
        }

        // Tenant 11 and tenant 22 each own one row per table.
        foreach (['domains', 'mailboxes', 'aliases', 'forwards', 'mail_groups'] as $table) {
            $this->pdo->exec("INSERT INTO {$table} (id, tenant_id, deleted_at) VALUES (1, 11, NULL), (2, 22, NULL)");
        }

        $this->pdo->exec(
            "INSERT INTO users (id, tenant_id, role, email, deleted_at) VALUES
             (1, 11, 'tenant_admin', 'a@x.test', NULL),
             (2, 22, 'tenant_admin', 'b@x.test', NULL),
             (3, 22, 'super_admin', 'root@x.test', NULL)"
        );
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function repositoryProvider(): array
    {
        return [
            'domains' => [DomainRepository::class, 'domains'],
            'mailboxes' => [MailboxRepository::class, 'mailboxes'],
            'aliases' => [AliasRepository::class, 'aliases'],
            'forwards' => [ForwardRepository::class, 'forwards'],
            'mail_groups' => [MailGroupRepository::class, 'mail_groups'],
        ];
    }

    /**
     * @dataProvider repositoryProvider
     * @param class-string $repositoryClass
     */
    public function test_all_with_tenant_id_returns_only_that_tenant(string $repositoryClass, string $table): void
    {
        $repository = $this->makeRepository($repositoryClass);

        $rows = $repository->all(11);

        $this->assertCount(1, $rows, "{$table} leaked rows from another tenant");
        $this->assertSame(11, (int) $rows[0]['tenant_id']);
    }

    /**
     * @dataProvider repositoryProvider
     * @param class-string $repositoryClass
     */
    public function test_all_without_tenant_id_returns_every_tenant(string $repositoryClass, string $table): void
    {
        $repository = $this->makeRepository($repositoryClass);

        $this->assertCount(2, $repository->all(), "{$table} did not return all tenants for an unscoped call");
        $this->assertCount(2, $repository->all(null), "{$table} did not treat explicit null as unscoped");
    }

    /**
     * @dataProvider repositoryProvider
     * @param class-string $repositoryClass
     */
    public function test_unknown_tenant_returns_nothing(string $repositoryClass, string $table): void
    {
        $repository = $this->makeRepository($repositoryClass);

        $this->assertSame([], $repository->all(9999), "{$table} returned rows for a tenant that owns none");
    }

    /**
     * @dataProvider repositoryProvider
     * @param class-string $repositoryClass
     */
    public function test_soft_deleted_rows_stay_hidden_when_scoped(string $repositoryClass, string $table): void
    {
        $this->pdo->exec("UPDATE {$table} SET deleted_at = '2026-01-01 00:00:00' WHERE tenant_id = 11");
        $repository = $this->makeRepository($repositoryClass);

        $this->assertSame([], $repository->all(11), "{$table} returned a soft-deleted row");
    }

    public function test_tenant_admin_listing_is_scoped_by_tenant(): void
    {
        $users = $this->makeRepository(UserRepository::class);

        $scoped = $users->allTenantAdmins(22);
        $this->assertCount(1, $scoped);
        $this->assertSame('b@x.test', $scoped[0]['email']);
        $this->assertSame('tenant_admin', $scoped[0]['role']);

        // The super_admin row shares tenant 22 but must not appear in a tenant_admin listing.
        $this->assertCount(2, $users->allTenantAdmins());
    }

    /**
     * The controllers must not reintroduce PHP-side filtering. If these helpers
     * come back, tenant isolation silently depends on call sites again.
     */
    public function test_array_side_scope_helpers_are_not_reintroduced(): void
    {
        $trait = file_get_contents(__DIR__ . '/../src/Http/Controllers/Traits/AdminWebLayoutTrait.php');

        $this->assertIsString($trait);
        $this->assertStringNotContainsString('function scopeByTenant', $trait);
        $this->assertStringNotContainsString('function scopeTenantRows', $trait);

        foreach (glob(__DIR__ . '/../src/Http/Controllers/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString(
                'function scopeByTenant',
                $source,
                basename($file) . ' reintroduced array-side tenant scoping'
            );
        }
    }

    private function makeRepository(string $repositoryClass): object
    {
        $database = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();

        $connection = new \ReflectionProperty(Database::class, 'connection');
        $connection->setAccessible(true);
        $connection->setValue($database, $this->pdo);

        return new $repositoryClass($database);
    }
}
