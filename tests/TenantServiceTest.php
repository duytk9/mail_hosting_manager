<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\TenantService;
use PDO;
use PHPUnit\Framework\TestCase;

final class TenantServiceTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    public function test_package_assignment_can_disable_previous_custom_limits(): void
    {
        $service = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(TenantService::class, 'buildTenantPayload');

        $package = [
            'max_domains' => 5,
            'max_mailboxes' => 100,
            'max_aliases' => 50,
            'max_forwarders' => 20,
            'max_total_quota_mb' => 20480,
            'default_mailbox_quota_mb' => 20480,
            'enable_catchall' => 1,
            'enable_external_forwarding' => 0,
        ];
        $current = [
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
            'package_id' => 1,
            'is_custom_limits' => 1,
            'max_domains' => 1,
            'max_mailboxes' => 1,
            'max_aliases' => 1,
            'max_forwarders' => 1,
            'max_total_quota_mb' => 1,
            'extra_domains' => 0,
            'extra_mailboxes' => 0,
            'extra_aliases' => 0,
            'extra_forwarders' => 0,
            'extra_total_quota_mb' => 0,
        ];

        $payload = $method->invoke($service, $package, [
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
            'package_id' => 1,
            'is_custom_limits' => '0',
        ], $current);

        $this->assertSame(0, $payload['is_custom_limits']);
        $this->assertSame(5, $payload['max_domains']);
        $this->assertSame(100, $payload['max_mailboxes']);
        $this->assertSame(20480, $payload['max_total_quota_mb']);
        $this->assertSame(20480, $payload['default_mailbox_quota_mb']);
    }

    public function test_package_assignment_accepts_checked_custom_limits_value(): void
    {
        $service = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(TenantService::class, 'buildTenantPayload');

        $payload = $method->invoke($service, [
            'max_domains' => 5,
            'max_mailboxes' => 100,
            'max_aliases' => 50,
            'max_forwarders' => 20,
            'max_total_quota_mb' => 20480,
            'default_mailbox_quota_mb' => 20480,
        ], [
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
            'package_id' => 1,
            'is_custom_limits' => 'on',
            'max_domains' => 2,
            'max_mailboxes' => 10,
            'max_aliases' => 4,
            'max_forwarders' => 3,
            'max_total_quota_mb' => 512,
        ]);

        $this->assertSame(1, $payload['is_custom_limits']);
        $this->assertSame(2, $payload['max_domains']);
        $this->assertSame(10, $payload['max_mailboxes']);
        $this->assertSame(512, $payload['max_total_quota_mb']);
    }

    public function test_tenant_payload_rejects_grace_before_minimum_window(): void
    {
        $service = (new \ReflectionClass(TenantService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(TenantService::class, 'buildTenantPayload');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Grace date must be at least 1 day after the expiry date.');

        $method->invoke($service, [
            'max_domains' => 5,
            'max_mailboxes' => 100,
            'max_aliases' => 50,
            'max_forwarders' => 20,
            'max_total_quota_mb' => 20480,
            'default_mailbox_quota_mb' => 20480,
        ], [
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'status' => 'active',
            'package_id' => 1,
            'expires_at' => '2026-01-01',
            'grace_until' => '2026-01-01',
        ]);
    }

    public function test_create_uses_assigned_package_plus_extra_allocations(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $pdo = $database->connection();

        $pdo->exec(
            "INSERT INTO packages (id, name, description, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, max_mailbox_quota_mb, max_message_size_mb, outbound_per_hour, outbound_per_day, enable_pop3, enable_imap, enable_managesieve, enable_catchall, enable_external_forwarding, spam_level_default, quarantine_enabled, antivirus_enabled, dkim_enabled, custom_smtp_banner_allowed, retention_days, created_at, updated_at, deleted_at)
             VALUES (1, 'Starter', 'Entry package', 5, 50, 20, 10, 20480, 1024, 4096, 25, 500, 5000, 1, 1, 1, 1, 0, 'normal', 1, 1, 1, 0, 30, NOW(), NOW(), NULL)"
        );

        $service = $this->makeService($database);
        $tenant = $service->create([
            'name' => 'VIP Tenant',
            'slug' => 'vip-tenant',
            'package_id' => 1,
            'status' => 'active',
            'extra_domains' => 2,
            'extra_mailboxes' => 5,
            'extra_aliases' => 7,
            'extra_forwarders' => 3,
            'extra_total_quota_mb' => 2048,
            'note' => 'Priority customer',
        ]);

        $this->assertSame('Starter', $tenant['package_name'] ?? null);
        $this->assertSame('Starter', $tenant['package_label'] ?? null);
        $this->assertSame(7, (int) ($tenant['max_domains'] ?? 0));
        $this->assertSame(55, (int) ($tenant['max_mailboxes'] ?? 0));
        $this->assertSame(27, (int) ($tenant['max_aliases'] ?? 0));
        $this->assertSame(13, (int) ($tenant['max_forwarders'] ?? 0));
        $this->assertSame(22528, (int) ($tenant['max_total_quota_mb'] ?? 0));
        $this->assertSame(2048, (int) ($tenant['extra_total_quota_mb'] ?? 0));
        $this->assertSame('Priority customer', $tenant['note'] ?? null);
        $this->assertSame('package_plus_extra', $tenant['package_assignment_mode'] ?? null);
        $this->assertStringContainsString('+2048 MB quota', (string) ($tenant['extra_allocations_summary'] ?? ''));
    }

    public function test_update_recomputes_effective_limits_when_assigned_package_changes(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $pdo = $database->connection();

        $pdo->exec(
            "INSERT INTO packages (id, name, description, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, max_mailbox_quota_mb, max_message_size_mb, outbound_per_hour, outbound_per_day, enable_pop3, enable_imap, enable_managesieve, enable_catchall, enable_external_forwarding, spam_level_default, quarantine_enabled, antivirus_enabled, dkim_enabled, custom_smtp_banner_allowed, retention_days, created_at, updated_at, deleted_at)
             VALUES
             (1, 'Starter', 'Entry package', 5, 50, 20, 10, 20480, 1024, 4096, 25, 500, 5000, 1, 1, 1, 1, 0, 'normal', 1, 1, 1, 0, 30, NOW(), NOW(), NULL),
             (2, 'Business', 'Higher quota package', 10, 100, 30, 20, 40960, 2048, 8192, 50, 1000, 10000, 1, 1, 1, 1, 1, 'normal', 1, 1, 1, 0, 30, NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, is_custom_limits, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, note, created_at, updated_at, deleted_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 0, 2, 5, 7, 3, 2048, 7, 55, 27, 13, 22528, 1024, 1, 0, 'Old note', NOW(), NOW(), NULL)"
        );

        $service = $this->makeService($database);
        $service->update(7, [
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'package_id' => 2,
            'status' => 'active',
            'extra_domains' => 2,
            'extra_mailboxes' => 5,
            'extra_aliases' => 7,
            'extra_forwarders' => 3,
            'extra_total_quota_mb' => 2048,
            'note' => 'Moved to business',
        ]);

        $tenant = $service->find(7);

        $this->assertSame('Business', $tenant['package_name'] ?? null);
        $this->assertSame(12, (int) ($tenant['max_domains'] ?? 0));
        $this->assertSame(105, (int) ($tenant['max_mailboxes'] ?? 0));
        $this->assertSame(37, (int) ($tenant['max_aliases'] ?? 0));
        $this->assertSame(23, (int) ($tenant['max_forwarders'] ?? 0));
        $this->assertSame(43008, (int) ($tenant['max_total_quota_mb'] ?? 0));
        $this->assertSame('Moved to business', $tenant['note'] ?? null);
    }

    public function test_delete_purges_tenant_data_so_same_identity_can_be_created_again(): void
    {
        $this->requireSqliteDriver();
        $database = $this->makeDatabase();
        $pdo = $database->connection();

        $pdo->exec(
            "INSERT INTO packages (id, name, description, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, max_mailbox_quota_mb, max_message_size_mb, outbound_per_hour, outbound_per_day, enable_pop3, enable_imap, enable_managesieve, enable_catchall, enable_external_forwarding, spam_level_default, quarantine_enabled, antivirus_enabled, dkim_enabled, custom_smtp_banner_allowed, retention_days, created_at, updated_at, deleted_at)
             VALUES (1, 'Starter', 'Entry package', 5, 50, 20, 10, 20480, 1024, 4096, 25, 500, 5000, 1, 1, 1, 1, 0, 'normal', 1, 1, 1, 0, 30, NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO tenants (id, name, slug, status, package_id, is_custom_limits, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, note, created_at, updated_at, deleted_at)
             VALUES (7, 'Tenant A', 'tenant-a', 'active', 1, 0, 0, 0, 0, 0, 0, 5, 50, 20, 10, 20480, 1024, 1, 0, NULL, NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO domains (id, tenant_id, domain, status, is_primary, catchall_mailbox_id, created_at, updated_at, deleted_at)
             VALUES (11, 7, 'example.test', 'active', 1, NULL, NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO mailboxes (id, tenant_id, domain_id, local_part, email, password_hash, display_name, quota_mb, status, created_at, updated_at, deleted_at)
             VALUES (21, 7, 11, 'admin', 'admin@example.test', 'hash', 'Admin', 1024, 'active', NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO users (id, tenant_id, role, name, email, password_hash, created_at, updated_at, deleted_at)
             VALUES (31, 7, 'tenant_admin', 'Owner', 'owner@example.test', 'hash', NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO aliases (id, tenant_id, domain_id, source_address, destination_mailbox_id, keep_copy, created_at, updated_at, deleted_at)
             VALUES (41, 7, 11, 'alias@example.test', 21, 0, NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO forwards (id, tenant_id, domain_id, source_address, destination_address, keep_copy, created_at, updated_at, deleted_at)
             VALUES (51, 7, 11, 'forward@example.test', 'outside@example.test', 0, NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO mail_groups (id, tenant_id, domain_id, local_part, email, display_name, status, created_at, updated_at, deleted_at)
             VALUES (61, 7, 11, 'team', 'team@example.test', 'Team', 'active', NOW(), NOW(), NULL)"
        );
        $pdo->exec("INSERT INTO mail_group_members (id, group_id, recipient_address, created_at, updated_at) VALUES (71, 61, 'admin@example.test', NOW(), NOW())");
        $pdo->exec("INSERT INTO quota_usage (id, tenant_id, mailbox_id, used_mb, calculated_at, created_at, updated_at) VALUES (81, 7, 21, 1, NOW(), NOW(), NOW())");
        $pdo->exec("INSERT INTO mailbox_password_history (id, tenant_id, mailbox_id, password_hash, created_at, updated_at) VALUES (91, 7, 21, 'hash', NOW(), NOW())");
        $pdo->exec("INSERT INTO user_password_history (id, tenant_id, user_id, password_hash, created_at, updated_at) VALUES (101, 7, 31, 'hash', NOW(), NOW())");
        $pdo->exec("INSERT INTO api_tokens (id, tenant_id, user_id, mailbox_id, actor_role, name, token_hash, scopes, created_at, updated_at) VALUES (111, 7, 31, 21, 'tenant_admin', 'Token', 'token-hash', '[]', NOW(), NOW())");
        $pdo->exec("INSERT INTO spam_policies (id, tenant_id, domain_id, mailbox_id, created_at, updated_at) VALUES (121, 7, 11, 21, NOW(), NOW())");
        $pdo->exec("INSERT INTO dns_checks (id, tenant_id, domain_id, record_type, created_at, updated_at) VALUES (131, 7, 11, 'MX', NOW(), NOW())");
        $pdo->exec("INSERT INTO dkim_keys (id, tenant_id, domain_id, private_key_path, public_key_txt, created_at, updated_at) VALUES (141, 7, 11, '/tmp/key', 'txt', NOW(), NOW())");

        $service = $this->makeService($database);
        $service->delete(7);

        foreach ([
            'tenants',
            'domains',
            'mailboxes',
            'aliases',
            'forwards',
            'mail_groups',
            'mail_group_members',
            'quota_usage',
            'mailbox_password_history',
            'user_password_history',
            'api_tokens',
            'spam_policies',
            'dns_checks',
            'dkim_keys',
            'users',
        ] as $table) {
            $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn(), $table);
        }

        $newTenant = $service->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'package_id' => 1,
            'status' => 'active',
        ]);

        $pdo->exec(
            "INSERT INTO domains (tenant_id, domain, status, is_primary, catchall_mailbox_id, created_at, updated_at, deleted_at)
             VALUES (" . (int) $newTenant['id'] . ", 'example.test', 'active', 1, NULL, NOW(), NOW(), NULL)"
        );
        $newDomainId = (int) $pdo->lastInsertId();
        $pdo->exec(
            "INSERT INTO mailboxes (tenant_id, domain_id, local_part, email, password_hash, display_name, quota_mb, status, created_at, updated_at, deleted_at)
             VALUES (" . (int) $newTenant['id'] . ", {$newDomainId}, 'admin', 'admin@example.test', 'hash', 'Admin', 1024, 'active', NOW(), NOW(), NULL)"
        );
        $pdo->exec(
            "INSERT INTO users (tenant_id, role, name, email, password_hash, created_at, updated_at, deleted_at)
             VALUES (" . (int) $newTenant['id'] . ", 'tenant_admin', 'Owner', 'owner@example.test', 'hash', NOW(), NOW(), NULL)"
        );

        $this->assertSame('tenant-a', $newTenant['slug'] ?? null);
    }

    private function makeService(Database $database): TenantService
    {
        return new TenantService(
            new TenantRepository($database),
            new PackageRepository($database),
            new DomainRepository($database),
            new MailboxRepository($database),
            new AliasRepository($database),
            new ForwardRepository($database),
            new AuditLogService($database),
            new TenantPurgeRepository($database),
            null,
            new QuotaUsageRepository($database)
        );
    }

    private function makeDatabase(): Database
    {
        $this->sqlitePath = sys_get_temp_dir() . '/mailpanel-tenant-service-' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = new Database([
            'driver' => 'sqlite',
            'sqlite_path' => $this->sqlitePath,
        ]);

        $pdo = $database->connection();
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

        $pdo->exec(
            'CREATE TABLE packages (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT NULL,
                max_domains INTEGER NOT NULL,
                max_mailboxes INTEGER NOT NULL,
                max_aliases INTEGER NOT NULL DEFAULT 0,
                max_forwarders INTEGER NOT NULL DEFAULT 0,
                max_total_quota_mb INTEGER NOT NULL,
                default_mailbox_quota_mb INTEGER NOT NULL,
                max_mailbox_quota_mb INTEGER NOT NULL,
                max_message_size_mb INTEGER NOT NULL,
                outbound_per_hour INTEGER NOT NULL,
                outbound_per_day INTEGER NOT NULL,
                enable_pop3 INTEGER NOT NULL DEFAULT 1,
                enable_imap INTEGER NOT NULL DEFAULT 1,
                enable_managesieve INTEGER NOT NULL DEFAULT 1,
                enable_catchall INTEGER NOT NULL DEFAULT 0,
                enable_external_forwarding INTEGER NOT NULL DEFAULT 0,
                spam_level_default TEXT NOT NULL DEFAULT "normal",
                quarantine_enabled INTEGER NOT NULL DEFAULT 1,
                antivirus_enabled INTEGER NOT NULL DEFAULT 1,
                dkim_enabled INTEGER NOT NULL DEFAULT 1,
                custom_smtp_banner_allowed INTEGER NOT NULL DEFAULT 0,
                retention_days INTEGER NOT NULL DEFAULT 30,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
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
                is_custom_limits INTEGER NOT NULL DEFAULT 0,
                extra_domains INTEGER NOT NULL DEFAULT 0,
                extra_mailboxes INTEGER NOT NULL DEFAULT 0,
                extra_aliases INTEGER NOT NULL DEFAULT 0,
                extra_forwarders INTEGER NOT NULL DEFAULT 0,
                extra_total_quota_mb INTEGER NOT NULL DEFAULT 0,
                max_domains INTEGER NOT NULL DEFAULT 0,
                max_mailboxes INTEGER NOT NULL DEFAULT 0,
                max_aliases INTEGER NOT NULL DEFAULT 0,
                max_forwarders INTEGER NOT NULL DEFAULT 0,
                max_total_quota_mb INTEGER NOT NULL DEFAULT 0,
                default_mailbox_quota_mb INTEGER NOT NULL DEFAULT 0,
                allow_catchall INTEGER NOT NULL DEFAULT 0,
                allow_external_forwarding INTEGER NOT NULL DEFAULT 0,
                admin_user_id INTEGER NULL,
                note TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE domains (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain TEXT NOT NULL UNIQUE,
                status TEXT NOT NULL DEFAULT "active",
                is_primary INTEGER NOT NULL DEFAULT 0,
                catchall_mailbox_id INTEGER NULL,
                inbound_enabled INTEGER NOT NULL DEFAULT 1,
                outbound_enabled INTEGER NOT NULL DEFAULT 1,
                dkim_enabled INTEGER NOT NULL DEFAULT 1,
                dmarc_policy_expected TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE mailboxes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain_id INTEGER NOT NULL,
                local_part TEXT NULL,
                email TEXT NULL UNIQUE,
                password_hash TEXT NULL,
                display_name TEXT NULL,
                quota_mb INTEGER NOT NULL DEFAULT 0,
                status TEXT NULL,
                force_password_change INTEGER NOT NULL DEFAULT 0,
                imap_enabled INTEGER NOT NULL DEFAULT 1,
                pop3_enabled INTEGER NOT NULL DEFAULT 1,
                smtp_enabled INTEGER NOT NULL DEFAULT 1,
                managesieve_enabled INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL,
                UNIQUE (domain_id, local_part)
            )'
        );
        $pdo->exec(
            'CREATE TABLE aliases (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain_id INTEGER NULL,
                source_address TEXT NULL UNIQUE,
                destination_mailbox_id INTEGER NULL,
                keep_copy INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE forwards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain_id INTEGER NULL,
                source_address TEXT NULL UNIQUE,
                destination_address TEXT NULL,
                keep_copy INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE tenant_subscriptions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                package_id INTEGER NOT NULL,
                billing_status TEXT NOT NULL DEFAULT "active",
                starts_at TEXT NULL,
                expires_at TEXT NULL,
                grace_until TEXT NULL,
                note TEXT NULL,
                created_by INTEGER NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE tenant_lifecycle_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                event_type TEXT NOT NULL,
                old_status TEXT NULL,
                new_status TEXT NULL,
                starts_at TEXT NULL,
                expires_at TEXT NULL,
                grace_until TEXT NULL,
                note TEXT NULL,
                actor_id INTEGER NULL,
                created_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                role TEXT NOT NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE mail_groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain_id INTEGER NOT NULL,
                local_part TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                display_name TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT "active",
                created_at TEXT NULL,
                updated_at TEXT NULL,
                deleted_at TEXT NULL,
                UNIQUE (domain_id, local_part)
            )'
        );
        $pdo->exec(
            'CREATE TABLE mail_group_members (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                group_id INTEGER NOT NULL,
                recipient_address TEXT NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL,
                UNIQUE (group_id, recipient_address)
            )'
        );
        $pdo->exec(
            'CREATE TABLE quota_usage (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                mailbox_id INTEGER NOT NULL UNIQUE,
                used_mb INTEGER NOT NULL DEFAULT 0,
                calculated_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE mailbox_password_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                mailbox_id INTEGER NOT NULL,
                password_hash TEXT NOT NULL,
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
            'CREATE TABLE api_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                user_id INTEGER NULL,
                mailbox_id INTEGER NULL,
                actor_role TEXT NOT NULL DEFAULT "super_admin",
                name TEXT NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                scopes TEXT NOT NULL,
                last_used_at TEXT NULL,
                expires_at TEXT NULL,
                revoked_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE spam_policies (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                domain_id INTEGER NULL,
                mailbox_id INTEGER NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE dns_checks (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NULL,
                domain_id INTEGER NOT NULL,
                record_type TEXT NOT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE dkim_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                domain_id INTEGER NOT NULL,
                private_key_path TEXT NOT NULL,
                public_key_txt TEXT NOT NULL,
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
