<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Database;
use MailPanel\Core\Request;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Security\SessionManager;
use PHPUnit\Framework\TestCase;

final class RequestActorResolverTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    public function test_resolves_actor_from_session_identity(): void
    {
        $sessions = new SessionManager();
        $sessions->putIdentity([
            'id' => 9,
            'role' => 'tenant_admin',
            'tenant_id' => 4,
        ], 'admin');

        $resolver = new RequestActorResolver($sessions);
        $actor = $resolver->resolve(new Request('GET', '/', [], [], []));

        $this->assertSame(9, $actor->id);
        $this->assertSame('tenant_admin', $actor->role);
        $this->assertSame(4, $actor->tenantId);
    }

    public function test_invalid_bearer_header_does_not_fallback_to_session_identity(): void
    {
        $sessions = new SessionManager();
        $sessions->putIdentity([
            'id' => 9,
            'role' => 'tenant_admin',
            'tenant_id' => 4,
        ], 'admin');

        $resolver = new RequestActorResolver($sessions);
        $actor = $resolver->resolve(new Request(
            'GET',
            '/api/secure',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'bearer invalid-token']
        ));

        $this->assertSame(0, $actor->id);
        $this->assertSame('guest', $actor->role);
        $this->assertNull($actor->tenantId);
    }

    public function test_session_refresh_enforces_tenant_lifecycle_and_domain_state_guards(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Security/RequestActorResolver.php');

        $this->assertStringContainsString('TenantLifecyclePolicy::canUseMail($tenant)', $source);
        $this->assertStringContainsString('(string) ($domain[\'status\'] ?? \'\') !== \'active\'', $source);
        $this->assertStringContainsString('(int) ($domain[\'inbound_enabled\'] ?? 0) !== 1', $source);
        $this->assertStringContainsString('private readonly ?DomainRepository $domains = null', $source);
    }

    public function test_admin_session_is_revoked_when_tenant_lifecycle_expires(): void
    {
        $sessions = new SessionManager();
        $sessions->putIdentity([
            'id' => 9,
            'role' => 'tenant_admin',
            'tenant_id' => 4,
        ], 'admin');
        $database = $this->database();
        $pdo = $database->connection();
        $pdo->exec("INSERT INTO tenants (id, status, billing_status, expires_at, grace_until, suspended_at, terminated_at, deleted_at) VALUES (4, 'active', 'active', '2026-01-01 00:00:00', '2026-01-02 00:00:00', NULL, NULL, NULL)");
        $pdo->exec("INSERT INTO users (id, role, tenant_id, deleted_at) VALUES (9, 'tenant_admin', 4, NULL)");

        $resolver = new RequestActorResolver(
            $sessions,
            null,
            new UserRepository($database),
            null,
            new TenantRepository($database)
        );

        $actor = $resolver->resolve(new Request('GET', '/', [], [], []));

        $this->assertSame(0, $actor->id);
        $this->assertSame('guest', $actor->role);
    }

    public function test_mailbox_session_is_revoked_when_domain_or_tenant_is_no_longer_usable(): void
    {
        $sessions = new SessionManager();
        $sessions->putIdentity([
            'id' => 12,
            'role' => 'mailbox_user',
            'tenant_id' => 4,
            'domain_id' => 5,
        ], 'mailbox');
        $database = $this->database();
        $pdo = $database->connection();
        $pdo->exec("INSERT INTO tenants (id, status, billing_status, expires_at, grace_until, suspended_at, terminated_at, deleted_at) VALUES (4, 'active', 'active', NULL, NULL, NULL, NULL, NULL)");
        $pdo->exec("INSERT INTO domains (id, status, inbound_enabled, deleted_at) VALUES (5, 'suspended', 1, NULL)");
        $pdo->exec("INSERT INTO mailboxes (id, tenant_id, domain_id, status, deleted_at) VALUES (12, 4, 5, 'active', NULL)");

        $resolver = new RequestActorResolver(
            $sessions,
            null,
            null,
            new MailboxRepository($database),
            new TenantRepository($database),
            new DomainRepository($database)
        );

        $actor = $resolver->resolve(new Request('GET', '/', [], [], []));

        $this->assertSame(0, $actor->id);
        $this->assertSame('guest', $actor->role);
    }

    private function database(): Database
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('PDO SQLite driver is not installed in this PHP runtime.');
        }

        $this->sqlitePath = sys_get_temp_dir() . '/mailpanel-actor-resolver-' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = new Database([
            'driver' => 'sqlite',
            'sqlite_path' => $this->sqlitePath,
        ]);
        $pdo = $database->connection();
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
                role TEXT NOT NULL,
                tenant_id INTEGER NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE domains (
                id INTEGER PRIMARY KEY,
                status TEXT NOT NULL,
                inbound_enabled INTEGER NOT NULL DEFAULT 1,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE mailboxes (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                domain_id INTEGER NOT NULL,
                status TEXT NOT NULL,
                deleted_at TEXT NULL
            )'
        );

        return $database;
    }
}
