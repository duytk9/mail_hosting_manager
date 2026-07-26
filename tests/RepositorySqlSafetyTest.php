<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Repositories\Pdo\MailGroupMemberRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class RepositorySqlSafetyTest extends TestCase
{
    public function test_password_history_limits_are_clamped(): void
    {
        $root = dirname(__DIR__);
        $userHistory = file_get_contents($root . '/src/Repositories/Pdo/UserPasswordHistoryRepository.php');
        $mailboxHistory = file_get_contents($root . '/src/Repositories/Pdo/MailboxPasswordHistoryRepository.php');

        $this->assertIsString($userHistory);
        $this->assertIsString($mailboxHistory);
        $this->assertStringContainsString('max(1, min($limit, 50))', $userHistory);
        $this->assertStringContainsString('max(1, min($limit, 50))', $mailboxHistory);
    }

    public function test_mail_group_member_ids_are_cast_unique_and_limited(): void
    {
        $repository = new class extends MailGroupMemberRepository {
            public string $sql = '';
            public array $params = [];

            public function __construct()
            {
            }

            protected function fetchAll(string $sql, array $params = []): array
            {
                $this->sql = $sql;
                $this->params = $params;

                return [];
            }
        };

        $repository->forGroupIds(['1', '2 OR 1=1', -3, 1, 0]);

        $this->assertSame([1, 2], $repository->params);
        $this->assertStringContainsString('IN (?,?)', $repository->sql);
    }

    public function test_mail_group_member_ids_reject_excessive_lists(): void
    {
        $repository = (new ReflectionClass(MailGroupMemberRepository::class))->newInstanceWithoutConstructor();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Too many mail group IDs requested.');

        $repository->forGroupIds(range(1, 1001));
    }

    public function test_tenant_purge_repository_rejects_unsafe_sql_identifiers(): void
    {
        $repository = (new ReflectionClass(TenantPurgeRepository::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(TenantPurgeRepository::class, 'safeIdentifier');

        $this->assertSame('mailboxes', $method->invoke($repository, 'mailboxes'));

        foreach (['mailboxes; DROP TABLE tenants', '../mailboxes', 'Mailboxes'] as $identifier) {
            try {
                $method->invoke($repository, $identifier);
                $this->fail('Unsafe SQL identifier was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Unsafe SQL identifier', $exception->getMessage());
            }
        }
    }
}
