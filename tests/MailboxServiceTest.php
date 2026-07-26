<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Contracts\MailStoragePurger;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\MailboxService;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\PasswordPolicyService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailboxServiceTest extends TestCase
{
    public function test_rejects_reserved_local_part(): void
    {
        $service = $this->makeService(
            new class extends MailboxRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
                public function totalQuotaForTenant(int $tenantId): int { return 0; }
                public function countByTenant(int $tenantId): int { return 0; }
            },
            new class extends DomainRepository {
                public function __construct() {}
                public function find(int $id): ?array { return ['id' => 1, 'tenant_id' => 1, 'domain' => 'example.test']; }
            },
            new class extends TenantRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'name' => 'Tenant A', 'status' => 'active', 'package_id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_total_quota_mb' => 4096, 'max_mailboxes' => 10];
                }
            },
            new class extends PackageRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_mailbox_quota_mb' => 2048, 'enable_pop3' => 1, 'enable_managesieve' => 1];
                }
            }
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('local_part is reserved.');

        $service->create([
            'tenant_id' => 1,
            'domain_id' => 1,
            'local_part' => 'root',
            'password' => 'StrongPass123!',
            'display_name' => 'Root User',
        ]);
    }

    public function test_rejects_mailbox_quota_above_package_per_account_limit(): void
    {
        $service = $this->makeService(
            new class extends MailboxRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
                public function totalQuotaForTenant(int $tenantId): int { return 1024; }
                public function countByTenant(int $tenantId): int { return 1; }
            },
            new class extends DomainRepository {
                public function __construct() {}
                public function find(int $id): ?array { return ['id' => 1, 'tenant_id' => 1, 'domain' => 'example.test']; }
            },
            new class extends TenantRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'name' => 'Tenant A', 'status' => 'active', 'package_id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_total_quota_mb' => 8192, 'max_mailboxes' => 10];
                }
            },
            new class extends PackageRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_mailbox_quota_mb' => 2048, 'enable_pop3' => 1, 'enable_managesieve' => 1];
                }
            }
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('package per-account limit');

        $service->create([
            'domain_id' => 1,
            'local_part' => 'sales',
            'password' => 'StrongPass123!',
            'display_name' => 'Sales Team',
            'quota_mb' => 4096,
        ]);
    }

    public function test_allows_assigned_quota_above_remaining_tenant_quota(): void
    {
        $service = $this->makeService(
            new class extends MailboxRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
                public function totalQuotaForTenant(int $tenantId): int { return 7500; }
                public function countByTenant(int $tenantId): int { return 2; }
                public function create(array $data): array
                {
                    throw new RuntimeException('create-called');
                }
            },
            new class extends DomainRepository {
                public function __construct() {}
                public function find(int $id): ?array { return ['id' => 1, 'tenant_id' => 1, 'domain' => 'example.test']; }
            },
            new class extends TenantRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'name' => 'Tenant A', 'status' => 'active', 'package_id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_total_quota_mb' => 8192, 'max_mailboxes' => 10];
                }
            },
            new class extends PackageRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_mailbox_quota_mb' => 2048, 'enable_pop3' => 1, 'enable_managesieve' => 1];
                }
            }
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('create-called');

        $service->create([
            'domain_id' => 1,
            'local_part' => 'billing',
            'password' => 'StrongPass123!',
            'display_name' => 'Billing Team',
            'quota_mb' => 1024,
        ]);
    }

    public function test_tenant_quota_profile_reports_remaining_capacity(): void
    {
        $service = $this->makeService(
            new class extends MailboxRepository {
                public function __construct() {}
                public function totalQuotaForTenant(int $tenantId): int { return 3072; }
                public function countByTenant(int $tenantId): int { return 3; }
            },
            new class extends DomainRepository {
                public function __construct() {}
            },
            new class extends TenantRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return [
                        'id' => 1,
                        'name' => 'Tenant A',
                        'status' => 'active',
                        'package_id' => 1,
                        'default_mailbox_quota_mb' => 1024,
                        'max_total_quota_mb' => 8192,
                        'max_mailboxes' => 10,
                        'package_base_max_mailbox_quota_mb' => 2048,
                    ];
                }
            },
            new class extends PackageRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => 1, 'default_mailbox_quota_mb' => 1024, 'max_mailbox_quota_mb' => 2048];
                }
            }
        );

        $profile = $service->tenantQuotaProfile(1);

        $this->assertSame(8192, $profile['allocated_quota_mb']);
        $this->assertSame(3072, $profile['assigned_quota_mb']);
        $this->assertSame(0, $profile['used_quota_mb']);
        $this->assertSame(8192, $profile['remaining_quota_mb']);
        $this->assertSame(7, $profile['remaining_mailbox_slots']);
        $this->assertSame(2048, $profile['max_single_mailbox_quota_mb']);
    }

    public function test_change_password_with_current_rejects_invalid_current_password(): void
    {
        $hasher = new PasswordHashingService('bcrypt');
        $service = $this->makeService(
            new class($hasher->hash('OldStrong123!')) extends MailboxRepository {
                public function __construct(private readonly string $hash) {}
                public function find(int $id): ?array
                {
                    return ['id' => $id, 'tenant_id' => 7, 'email' => 'user@example.test', 'password_hash' => $this->hash];
                }
            },
            new class extends DomainRepository { public function __construct() {} },
            new class extends TenantRepository { public function __construct() {} },
            new class extends PackageRepository { public function __construct() {} },
            null,
            $this->memoryPasswordHistoryRepository()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Current password is invalid.');

        $service->changePasswordWithCurrent(21, 'WrongStrong123!', 'NewStrong123!');
    }

    public function test_change_password_with_current_updates_password_after_verification(): void
    {
        $hasher = new PasswordHashingService('bcrypt');
        $mailboxes = new class($hasher->hash('OldStrong123!')) extends MailboxRepository {
            public ?string $updatedHash = null;

            public function __construct(private readonly string $hash) {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'tenant_id' => 7,
                    'email' => 'user@example.test',
                    'password_hash' => $this->updatedHash ?? $this->hash,
                ];
            }

            public function updatePassword(int $id, string $hash): void
            {
                $this->updatedHash = $hash;
            }
        };
        $history = $this->memoryPasswordHistoryRepository();
        $service = $this->makeService(
            $mailboxes,
            new class extends DomainRepository { public function __construct() {} },
            new class extends TenantRepository { public function __construct() {} },
            new class extends PackageRepository { public function __construct() {} },
            null,
            $history
        );

        $service->changePasswordWithCurrent(21, 'OldStrong123!', 'NewStrong123!');

        $this->assertNotNull($mailboxes->updatedHash);
        $this->assertTrue($hasher->verify('NewStrong123!', (string) $mailboxes->updatedHash));
        $this->assertCount(1, $history->stored);
    }

    public function test_change_password_with_current_rejects_same_password(): void
    {
        $hasher = new PasswordHashingService('bcrypt');
        $service = $this->makeService(
            new class($hasher->hash('OldStrong123!')) extends MailboxRepository {
                public function __construct(private readonly string $hash) {}
                public function find(int $id): ?array
                {
                    return ['id' => $id, 'tenant_id' => 7, 'email' => 'user@example.test', 'password_hash' => $this->hash];
                }
            },
            new class extends DomainRepository { public function __construct() {} },
            new class extends TenantRepository { public function __construct() {} },
            new class extends PackageRepository { public function __construct() {} },
            null,
            $this->memoryPasswordHistoryRepository()
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('New password must be different from the current password.');

        $service->changePasswordWithCurrent(21, 'OldStrong123!', 'OldStrong123!');
    }

    public function test_delete_purges_mailbox_storage(): void
    {
        $purgedMailboxes = [];
        $mailStorage = new class($purgedMailboxes) implements MailStoragePurger {
            private array $purgedMailboxes;

            public function __construct(array &$purgedMailboxes)
            {
                $this->purgedMailboxes = &$purgedMailboxes;
            }

            public function purgeMailbox(string $email): array
            {
                $this->purgedMailboxes[] = $email;

                return ['result' => ['returncode' => 0]];
            }

            public function purgeDomain(string $domain): array
            {
                return ['result' => ['returncode' => 0]];
            }

            public function purgeMailboxes(array $emails): array
            {
                return [];
            }
        };
        $mailboxes = new class extends MailboxRepository {
            public array $softDeleted = [];

            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'tenant_id' => 7,
                    'domain_id' => 11,
                    'email' => 'admin@example.test',
                ];
            }

            public function softDelete(int $id): void
            {
                $this->softDeleted[] = $id;
            }
        };

        $service = $this->makeService(
            $mailboxes,
            new class extends DomainRepository { public function __construct() {} },
            new class extends TenantRepository { public function __construct() {} },
            new class extends PackageRepository { public function __construct() {} },
            $mailStorage
        );

        $service->delete(21);

        $this->assertSame([21], $mailboxes->softDeleted);
        $this->assertSame(['admin@example.test'], $purgedMailboxes);
    }

    public function test_mailbox_purge_statements_clear_email_based_relationships(): void
    {
        $repository = (new \ReflectionClass(TenantPurgeRepository::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(TenantPurgeRepository::class, 'mailboxDeleteStatements');
        $statements = $method->invoke($repository);

        $this->assertArrayHasKey('domains', $statements);
        $this->assertArrayHasKey('mail_group_members', $statements);
        $this->assertArrayHasKey('aliases', $statements);
        $this->assertArrayHasKey('forwards', $statements);
        $this->assertStringContainsString('catchall_mailbox_id = NULL', $statements['domains']);
        $this->assertStringContainsString('recipient_address', $statements['mail_group_members']);
        $this->assertStringContainsString('source_address', $statements['aliases']);
        $this->assertStringContainsString('destination_address', $statements['forwards']);
    }

    private function makeService(
        MailboxRepository $mailboxes,
        DomainRepository $domains,
        TenantRepository $tenants,
        PackageRepository $packages,
        ?MailStoragePurger $mailStorage = null,
        ?MailboxPasswordHistoryRepository $passwordHistory = null
    ): MailboxService {
        return new MailboxService(
            $mailboxes,
            $domains,
            $tenants,
            $packages,
            $this->createMock(AuditLogService::class),
            new PasswordPolicyService(),
            new PasswordHashingService('bcrypt'),
            $passwordHistory ?? $this->dummyPasswordHistoryRepository(),
            null,
            $mailStorage
        );
    }

    private function dummyPasswordHistoryRepository(): MailboxPasswordHistoryRepository
    {
        return (new \ReflectionClass(MailboxPasswordHistoryRepository::class))->newInstanceWithoutConstructor();
    }

    private function memoryPasswordHistoryRepository(): MailboxPasswordHistoryRepository
    {
        return new class extends MailboxPasswordHistoryRepository {
            public array $stored = [];

            public function __construct() {}
            public function recentHashesForMailbox(int $mailboxId, int $limit): array
            {
                return [];
            }

            public function store(int $mailboxId, int $tenantId, string $passwordHash): void
            {
                $this->stored[] = compact('mailboxId', 'tenantId', 'passwordHash');
            }
        };
    }
}
