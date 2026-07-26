<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Support\Validator;

final class AliasService
{
    public function __construct(
        private readonly AliasRepository $aliases,
        private readonly MailboxRepository $mailboxes,
        private readonly ForwardRepository $forwards,
        private readonly DomainRepository $domains,
        private readonly TenantRepository $tenants,
        private readonly PackageRepository $packages,
        private readonly AuditLogService $auditLog
    ) {
    }

    /**
     * @param int|null $tenantId Restricts the query to one tenant. Callers acting
     *                           for a tenant_admin MUST pass their tenant id so the
     *                           filter runs in SQL rather than in PHP.
     */
    public function list(?int $tenantId = null): array
    {
        return $this->aliases->all($tenantId);
    }

    public function find(int $aliasId): ?array
    {
        return $this->aliases->find($aliasId);
    }

    public function create(array $data): array
    {
        Validator::required($data, ['tenant_id', 'domain_id', 'source_address', 'destination_mailbox_id']);

        $tenantId = (int) $data['tenant_id'];
        $domainId = (int) $data['domain_id'];
        $sourceAddress = strtolower(trim((string) $data['source_address']));
        $destinationMailboxId = (int) $data['destination_mailbox_id'];

        $domain = $this->domains->find($domainId);
        if ($domain === null || (int) ($domain['tenant_id'] ?? 0) !== $tenantId) {
            throw new InvalidArgumentException('Selected domain does not belong to the chosen tenant.');
        }

        $this->assertAddressBelongsToDomain($sourceAddress, (string) $domain['domain']);

        if ($this->aliases->findBySource($sourceAddress) !== null) {
            throw new InvalidArgumentException('Alias already exists.');
        }

        if ($this->forwards->findBySource($sourceAddress) !== null) {
            throw new InvalidArgumentException('Forward rule already uses this source address.');
        }
        
        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }
        TenantLifecyclePolicy::assertCanProvision($tenant);

        if ($this->aliases->countByTenant($tenantId) >= (int) ($tenant['max_aliases'] ?? 0)) {
            throw new InvalidArgumentException('Tenant has reached the maximum number of aliases.');
        }

        $mailbox = $this->mailboxes->find($destinationMailboxId);
        if ($mailbox === null) {
            throw new InvalidArgumentException('Destination mailbox does not exist.');
        }

        if ((int) ($mailbox['tenant_id'] ?? 0) !== $tenantId) {
            throw new InvalidArgumentException('Destination mailbox belongs to another tenant.');
        }

        $alias = $this->aliases->create([
            'tenant_id' => $tenantId,
            'domain_id' => $domainId,
            'source_address' => $sourceAddress,
            'destination_mailbox_id' => $destinationMailboxId,
            'keep_copy' => (int) ($data['keep_copy'] ?? 0),
        ]);

        $this->auditLog->log([
            'action' => 'alias.created',
            'target_type' => 'alias',
            'target_id' => $alias['id'] ?? null,
            'tenant_id' => $alias['tenant_id'] ?? null,
            'new_values' => $alias,
        ]);

        return $alias;
    }

    public function delete(int $aliasId): void
    {
        $alias = $this->aliases->find($aliasId);
        if ($alias === null) {
            throw new InvalidArgumentException('Alias not found.');
        }

        $this->aliases->softDelete($aliasId);
        $this->auditLog->log([
            'action' => 'alias.deleted',
            'target_type' => 'alias',
            'target_id' => $aliasId,
            'tenant_id' => $alias['tenant_id'] ?? null,
        ]);
    }

    private function assertAddressBelongsToDomain(string $address, string $expectedDomain): void
    {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Alias source address must be a valid email.');
        }

        [$localPart, $domain] = explode('@', $address, 2);
        Validator::localPart($localPart);

        if (strtolower($domain) !== strtolower($expectedDomain)) {
            throw new InvalidArgumentException('Alias source address does not belong to the selected domain.');
        }
    }
}
