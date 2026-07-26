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

final class ForwardService
{
    public function __construct(
        private readonly ForwardRepository $forwards,
        private readonly MailboxRepository $mailboxes,
        private readonly AliasRepository $aliases,
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
        return $this->forwards->all($tenantId);
    }

    public function find(int $forwardId): ?array
    {
        return $this->forwards->find($forwardId);
    }

    public function listForMailbox(int $mailboxId): array
    {
        $mailbox = $this->mailboxes->find($mailboxId);
        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        return array_values(array_filter(
            $this->forwards->all(),
            static fn (array $forward): bool => strtolower((string) ($forward['source_address'] ?? '')) === strtolower((string) $mailbox['email'])
        ));
    }

    public function create(array $data): array
    {
        Validator::required($data, ['tenant_id', 'domain_id', 'source_address', 'destination_address']);

        $tenantId = (int) $data['tenant_id'];
        $domainId = (int) $data['domain_id'];
        $source = strtolower(trim((string) $data['source_address']));
        $destination = strtolower(trim((string) $data['destination_address']));

        $domain = $this->domains->find($domainId);
        if ($domain === null || (int) ($domain['tenant_id'] ?? 0) !== $tenantId) {
            throw new InvalidArgumentException('Selected domain does not belong to the chosen tenant.');
        }
        
        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }
        TenantLifecyclePolicy::assertCanProvision($tenant);

        if ($this->forwards->countByTenant($tenantId) >= (int) ($tenant['max_forwarders'] ?? 0)) {
            throw new InvalidArgumentException('Tenant has reached the maximum number of forwards.');
        }

        $this->assertAddressBelongsToDomain($source, (string) $domain['domain']);

        if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Forward destination address must be a valid email.');
        }

        if ($source === $destination) {
            throw new InvalidArgumentException('Forward loop detected.');
        }

        if ($this->aliases->findBySource($source) !== null) {
            throw new InvalidArgumentException('Alias already uses this source address.');
        }

        if ($this->forwards->findBySource($source) !== null) {
            throw new InvalidArgumentException('Forward rule already exists.');
        }

        $forward = $this->forwards->create([
            'tenant_id' => $tenantId,
            'domain_id' => $domainId,
            'source_address' => $source,
            'destination_address' => $destination,
            'keep_copy' => (int) ($data['keep_copy'] ?? 0),
        ]);

        $this->auditLog->log([
            'action' => 'forward.created',
            'target_type' => 'forward',
            'target_id' => $forward['id'] ?? null,
            'tenant_id' => $forward['tenant_id'] ?? null,
            'new_values' => $forward,
        ]);

        return $forward;
    }

    public function createForMailboxUser(int $mailboxId, array $data): array
    {
        $mailbox = $this->mailboxes->find($mailboxId);
        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        $source = strtolower(trim((string) ($data['source_address'] ?? '')));
        if ($source !== strtolower((string) $mailbox['email'])) {
            throw new InvalidArgumentException('Mailbox users can only manage forwarding for their own address.');
        }

        return $this->create([
            'tenant_id' => (int) $mailbox['tenant_id'],
            'domain_id' => (int) $mailbox['domain_id'],
            'source_address' => $source,
            'destination_address' => trim((string) ($data['destination_address'] ?? '')),
            'keep_copy' => (int) ($data['keep_copy'] ?? 0),
        ]);
    }

    public function delete(int $forwardId): void
    {
        $forward = $this->forwards->find($forwardId);
        if ($forward === null) {
            throw new InvalidArgumentException('Forward not found.');
        }

        $this->forwards->softDelete($forwardId);
        $this->auditLog->log([
            'action' => 'forward.deleted',
            'target_type' => 'forward',
            'target_id' => $forwardId,
            'tenant_id' => $forward['tenant_id'] ?? null,
        ]);
    }

    private function assertAddressBelongsToDomain(string $address, string $expectedDomain): void
    {
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Forward source address must be a valid email.');
        }

        [$localPart, $domain] = explode('@', $address, 2);
        Validator::localPart($localPart);

        if (strtolower($domain) !== strtolower($expectedDomain)) {
            throw new InvalidArgumentException('Forward source address does not belong to the selected domain.');
        }
    }
}
