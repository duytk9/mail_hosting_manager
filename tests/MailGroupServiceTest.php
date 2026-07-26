<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailGroupMemberRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\MailGroupService;
use PHPUnit\Framework\TestCase;

final class MailGroupServiceTest extends TestCase
{
    public function test_update_normalizes_members_and_rebuilds_email(): void
    {
        $groups = new class extends MailGroupRepository {
            public array $stored = [
                5 => [
                    'id' => 5,
                    'tenant_id' => 1,
                    'domain_id' => 1,
                    'local_part' => 'team',
                    'email' => 'team@example.test',
                    'display_name' => 'Team',
                    'status' => 'active',
                ],
            ];
            public function __construct() {}
            public function find(int $id): ?array { return $this->stored[$id] ?? null; }
            public function findByEmail(string $email): ?array
            {
                foreach ($this->stored as $group) {
                    if (($group['email'] ?? null) === $email) {
                        return $group;
                    }
                }

                return null;
            }
            public function update(int $id, array $data): array
            {
                $this->stored[$id] = $this->stored[$id] + $data;
                $this->stored[$id]['domain_id'] = $data['domain_id'];
                $this->stored[$id]['local_part'] = $data['local_part'];
                $this->stored[$id]['email'] = $data['email'];
                $this->stored[$id]['display_name'] = $data['display_name'];
                $this->stored[$id]['status'] = $data['status'];

                return $this->stored[$id];
            }
        };
        $members = new class extends MailGroupMemberRepository {
            public array $items = [
                ['group_id' => 5, 'recipient_address' => 'first@example.com'],
            ];
            public function __construct() {}
            public function forGroupIds(array $groupIds): array { return $this->items; }
            public function replaceMembers(int $groupId, array $recipients): void
            {
                $this->items = array_map(
                    static fn (string $recipient): array => ['group_id' => $groupId, 'recipient_address' => $recipient],
                    $recipients
                );
            }
        };
        $domains = new class extends DomainRepository {
            public function __construct() {}
            public function find(int $id): ?array
            {
                return [
                    'id' => $id,
                    'tenant_id' => 1,
                    'domain' => $id === 2 ? 'example.net' : 'example.test',
                ];
            }
        };
        $aliases = new class extends AliasRepository {
            public function __construct() {}
            public function findBySource(string $source): ?array { return null; }
        };
        $forwards = new class extends ForwardRepository {
            public function __construct() {}
            public function findBySource(string $source): ?array { return null; }
        };
        $mailboxes = new class extends MailboxRepository {
            public function __construct() {}
            public function findByEmail(string $email): ?array { return null; }
        };

        $service = new MailGroupService(
            $groups,
            $members,
            $domains,
            $aliases,
            $forwards,
            $mailboxes,
            $this->createMock(AuditLogService::class)
        );

        $group = $service->update(5, [
            'domain_id' => 2,
            'local_part' => 'All-Staff',
            'display_name' => 'All Staff',
            'members' => "first@example.com\nsecond@example.com\nFIRST@example.com",
        ]);

        $this->assertSame('all-staff', $group['local_part']);
        $this->assertSame('all-staff@example.net', $group['email']);
        $this->assertSame(['first@example.com', 'second@example.com'], $group['members']);
        $this->assertSame(['first@example.com', 'second@example.com'], array_column($members->items, 'recipient_address'));
    }

    public function test_create_rejects_address_colliding_with_alias(): void
    {
        $service = new MailGroupService(
            new class extends MailGroupRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
            },
            new class extends MailGroupMemberRepository { public function __construct() {} },
            new class extends DomainRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => $id, 'tenant_id' => 1, 'domain' => 'example.test'];
                }
            },
            new class extends AliasRepository {
                public function __construct() {}
                public function findBySource(string $source): ?array
                {
                    return ['id' => 9, 'source_address' => $source];
                }
            },
            new class extends ForwardRepository {
                public function __construct() {}
                public function findBySource(string $source): ?array { return null; }
            },
            new class extends MailboxRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
            },
            $this->createMock(AuditLogService::class)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Alias already uses this source address.');

        $service->create([
            'domain_id' => 1,
            'local_part' => 'team',
            'display_name' => 'Team',
            'members' => 'user@example.net',
        ]);
    }

    public function test_create_rejects_self_membership(): void
    {
        $service = new MailGroupService(
            new class extends MailGroupRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
            },
            new class extends MailGroupMemberRepository { public function __construct() {} },
            new class extends DomainRepository {
                public function __construct() {}
                public function find(int $id): ?array
                {
                    return ['id' => $id, 'tenant_id' => 1, 'domain' => 'example.test'];
                }
            },
            new class extends AliasRepository {
                public function __construct() {}
                public function findBySource(string $source): ?array { return null; }
            },
            new class extends ForwardRepository {
                public function __construct() {}
                public function findBySource(string $source): ?array { return null; }
            },
            new class extends MailboxRepository {
                public function __construct() {}
                public function findByEmail(string $email): ?array { return null; }
            },
            $this->createMock(AuditLogService::class)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mail group cannot contain its own address as a member.');

        $service->create([
            'domain_id' => 1,
            'local_part' => 'team',
            'display_name' => 'Team',
            'members' => 'team@example.test',
        ]);
    }

    public function test_delete_soft_deletes_group(): void
    {
        $groups = new class extends MailGroupRepository {
            public bool $deleted = false;
            public function __construct() {}
            public function find(int $id): ?array
            {
                return ['id' => $id, 'tenant_id' => 1, 'email' => 'team@example.test'];
            }
            public function softDelete(int $id): void
            {
                $this->deleted = true;
            }
        };

        $service = new MailGroupService(
            $groups,
            new class extends MailGroupMemberRepository { public function __construct() {} },
            new class extends DomainRepository { public function __construct() {} },
            new class extends AliasRepository { public function __construct() {} },
            new class extends ForwardRepository { public function __construct() {} },
            new class extends MailboxRepository { public function __construct() {} },
            $this->createMock(AuditLogService::class)
        );

        $service->delete(12);

        $this->assertTrue($groups->deleted);
    }
}
