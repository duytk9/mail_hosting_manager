<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\SpamPolicyRepository;
use MailPanel\Services\SpamPolicyService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SpamPolicyServiceSecurityTest extends TestCase
{
    public function test_update_policy_normalizes_sender_entries_before_storing(): void
    {
        $spamPolicies = $this->spamPolicies();
        $service = new SpamPolicyService(
            $spamPolicies,
            $this->domains(['id' => 15, 'tenant_id' => 7, 'domain' => 'example.test'])
        );

        $service->updatePolicy(
            7,
            15,
            " User@Example.test \n@Example.test\nuser@example.test",
            " Bad@Example.test \n@blocked.example"
        );

        $this->assertSame("user@example.test\n@example.test", $spamPolicies->storedAllowlist);
        $this->assertSame("bad@example.test\n@blocked.example", $spamPolicies->storedBlocklist);
    }

    public function test_update_policy_rejects_invalid_sender_entries(): void
    {
        $service = new SpamPolicyService(
            $this->spamPolicies(),
            $this->domains(['id' => 15, 'tenant_id' => 7, 'domain' => 'example.test'])
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('accepts only email addresses or @domain entries');

        $service->updatePolicy(7, 15, "valid@example.test\nbad entry; score = 0", '');
    }

    public function test_update_policy_rejects_domain_outside_tenant_scope(): void
    {
        $service = new SpamPolicyService(
            $this->spamPolicies(),
            $this->domains(['id' => 15, 'tenant_id' => 8, 'domain' => 'example.test'])
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permission denied');

        $service->updatePolicy(7, 15, 'valid@example.test', '');
    }

    private function spamPolicies(): SpamPolicyRepository
    {
        return new class extends SpamPolicyRepository {
            public string $storedAllowlist = '';
            public string $storedBlocklist = '';

            public function __construct()
            {
            }

            public function findByDomainId(int $domainId): ?array
            {
                return null;
            }

            public function createForDomain(int $tenantId, int $domainId, string $allowlistSenders = '', string $blocklistSenders = ''): int
            {
                $this->storedAllowlist = $allowlistSenders;
                $this->storedBlocklist = $blocklistSenders;

                return 1;
            }
        };
    }

    private function domains(array $domain): DomainRepository
    {
        return new class ($domain) extends DomainRepository {
            public function __construct(private readonly array $domain)
            {
            }

            public function find(int $id): ?array
            {
                return $id === (int) ($this->domain['id'] ?? 0) ? $this->domain : null;
            }
        };
    }
}
