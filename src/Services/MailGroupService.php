<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailGroupMemberRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Support\Validator;

final class MailGroupService
{
    public function __construct(
        private readonly MailGroupRepository $groups,
        private readonly MailGroupMemberRepository $members,
        private readonly DomainRepository $domains,
        private readonly AliasRepository $aliases,
        private readonly ForwardRepository $forwards,
        private readonly MailboxRepository $mailboxes,
        private readonly AuditLogService $auditLog,
        private readonly ?TenantPurgeRepository $tenantPurge = null,
        private readonly ?TenantRepository $tenants = null
    ) {
    }

    /**
     * @param int|null $tenantId Restricts the query to one tenant. Callers acting
     *                           for a tenant_admin MUST pass their tenant id so the
     *                           filter runs in SQL rather than in PHP.
     */
    public function list(?int $tenantId = null): array
    {
        return $this->attachMembers($this->groups->all($tenantId));
    }

    public function find(int $groupId): ?array
    {
        $group = $this->groups->find($groupId);

        if ($group === null) {
            return null;
        }

        return $this->attachMembers([$group])[0] ?? null;
    }

    public function create(array $data): array
    {
        [$domain, $payload, $members] = $this->normalizePayload($data);
        $this->assertTenantCanProvision((int) ($domain['tenant_id'] ?? 0));
        $this->assertAddressAvailable($payload['email']);

        $group = $this->groups->create($payload + [
            'tenant_id' => (int) $domain['tenant_id'],
            'domain_id' => (int) $domain['id'],
            'status' => 'active',
        ]);

        $this->members->replaceMembers((int) $group['id'], $members);

        $this->auditLog->log([
            'action' => 'mail_group.created',
            'target_type' => 'mail_group',
            'target_id' => $group['id'] ?? null,
            'tenant_id' => $group['tenant_id'] ?? null,
            'new_values' => $group + ['members' => $members],
        ]);

        $group['members'] = $members;
        return $group;
    }

    public function update(int $groupId, array $data): array
    {
        $currentGroup = $this->find($groupId);

        if ($currentGroup === null) {
            throw new InvalidArgumentException('Mail group not found.');
        }

        [$domain, $payload, $members] = $this->normalizePayload($data);

        if ((int) ($currentGroup['tenant_id'] ?? 0) !== (int) ($domain['tenant_id'] ?? 0)) {
            throw new InvalidArgumentException('Mail group cannot be moved to another tenant.');
        }

        $this->assertAddressAvailable($payload['email'], $groupId);

        $updated = $this->groups->update($groupId, [
            'domain_id' => (int) $domain['id'],
            'local_part' => $payload['local_part'],
            'email' => $payload['email'],
            'display_name' => $payload['display_name'],
            'status' => (string) ($currentGroup['status'] ?? 'active'),
        ]);

        $this->members->replaceMembers($groupId, $members);

        $this->auditLog->log([
            'action' => 'mail_group.updated',
            'target_type' => 'mail_group',
            'target_id' => $groupId,
            'tenant_id' => $currentGroup['tenant_id'] ?? null,
            'old_values' => $currentGroup,
            'new_values' => $updated + ['members' => $members],
        ]);

        $updated['members'] = $members;

        return $updated;
    }

    public function delete(int $groupId): void
    {
        $group = $this->groups->find($groupId);

        if ($group === null) {
            throw new InvalidArgumentException('Mail group not found.');
        }

        if ($this->tenantPurge !== null) {
            $this->tenantPurge->purgeMailGroup($groupId);
        } else {
            $this->groups->softDelete($groupId);
        }

        $this->auditLog->log([
            'action' => 'mail_group.deleted',
            'target_type' => 'mail_group',
            'target_id' => $groupId,
            'tenant_id' => $group['tenant_id'] ?? null,
        ]);
    }

    /**
     * @return array{0: array, 1: array, 2: array<int, string>}
     */
    private function normalizePayload(array $data): array
    {
        Validator::required($data, ['domain_id', 'local_part', 'display_name', 'members']);
        $domain = $this->domains->find((int) $data['domain_id']);

        if ($domain === null) {
            throw new InvalidArgumentException('Domain not found.');
        }

        $localPart = strtolower(trim((string) $data['local_part']));
        Validator::localPart($localPart);

        $payload = [
            'local_part' => $localPart,
            'email' => $localPart . '@' . strtolower((string) $domain['domain']),
            'display_name' => trim((string) $data['display_name']),
        ];
        $members = $this->normalizeMembers((string) $data['members']);

        if (in_array($payload['email'], $members, true)) {
            throw new InvalidArgumentException('Mail group cannot contain its own address as a member.');
        }

        return [$domain, $payload, $members];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeMembers(string $members): array
    {
        $normalized = [];

        foreach (preg_split('/[\r\n,;]+/', $members) ?: [] as $value) {
            $email = strtolower(trim($value));

            if ($email === '') {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Mail group member must be a valid email.');
            }

            $normalized[$email] = $email;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Mail group must have at least one member.');
        }

        return array_values($normalized);
    }

    private function assertAddressAvailable(string $email, ?int $ignoreGroupId = null): void
    {
        $existingGroup = $this->groups->findByEmail($email);
        if ($existingGroup !== null && (int) ($existingGroup['id'] ?? 0) !== $ignoreGroupId) {
            throw new InvalidArgumentException('Mail group already exists.');
        }

        if ($this->aliases->findBySource($email) !== null) {
            throw new InvalidArgumentException('Alias already uses this source address.');
        }

        if ($this->forwards->findBySource($email) !== null) {
            throw new InvalidArgumentException('Forward rule already uses this source address.');
        }

        if ($this->mailboxes->findByEmail($email) !== null) {
            throw new InvalidArgumentException('Mailbox already uses this address.');
        }
    }

    private function assertTenantCanProvision(int $tenantId): void
    {
        if ($this->tenants === null) {
            return;
        }

        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        TenantLifecyclePolicy::assertCanProvision($tenant);
    }

    private function attachMembers(array $groups): array
    {
        $ids = array_column($groups, 'id');
        $members = $this->members->forGroupIds($ids);
        $byGroup = [];

        foreach ($members as $member) {
            $byGroup[(int) $member['group_id']][] = $member['recipient_address'];
        }

        foreach ($groups as &$group) {
            $group['members'] = $byGroup[(int) $group['id']] ?? [];
        }

        return $groups;
    }
}
