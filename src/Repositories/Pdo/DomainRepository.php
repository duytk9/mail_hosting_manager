<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class DomainRepository extends AbstractPdoRepository
{

    public function findAllByTenantId(int $tenantId): array
    {
        return $this->fetchAll(
            'SELECT * FROM domains WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY domain ASC',
            ['tenant_id' => $tenantId]
        );
    }

    /**
     * @param int|null $tenantId When set, restricts the result to that tenant.
     *                           Passing null returns every tenant's rows and is
     *                           only valid for super_admin / support_readonly.
     */
    public function all(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            return $this->fetchAll('SELECT * FROM domains WHERE deleted_at IS NULL ORDER BY id DESC');
        }

        return $this->fetchAll(
            'SELECT * FROM domains WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY id DESC',
            ['tenant_id' => $tenantId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM domains WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function findByName(string $domain): ?array
    {
        return $this->fetchOne('SELECT * FROM domains WHERE domain = :domain AND deleted_at IS NULL', ['domain' => $domain]);
    }

    public function findPrimaryByTenant(int $tenantId): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM domains WHERE tenant_id = :tenant_id AND is_primary = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
            ['tenant_id' => $tenantId]
        );
    }

    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO domains (tenant_id, domain, status, is_primary, catchall_mailbox_id, inbound_enabled, outbound_enabled, dkim_enabled, dmarc_policy_expected, created_at, updated_at) VALUES (:tenant_id, :domain, :status, :is_primary, :catchall_mailbox_id, :inbound_enabled, :outbound_enabled, :dkim_enabled, :dmarc_policy_expected, NOW(), NOW())',
            $data
        );

        return $this->find($this->lastInsertId()) ?? [];
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->execute('UPDATE domains SET status = :status, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL', [
            'id' => $id,
            'status' => $status,
        ]);
    }

    public function clearPrimaryForTenant(int $tenantId): void
    {
        $this->execute(
            'UPDATE domains SET is_primary = 0, updated_at = NOW() WHERE tenant_id = :tenant_id AND deleted_at IS NULL',
            ['tenant_id' => $tenantId]
        );
    }

    public function setPrimary(int $id): void
    {
        $this->execute(
            'UPDATE domains SET is_primary = 1, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute('DELETE FROM domains WHERE id = :id', [
            'id' => $id,
        ]);
    }

    public function countByTenant(int $tenantId): int
    {
        $row = $this->fetchOne('SELECT COUNT(id) AS cnt FROM domains WHERE tenant_id = :t AND deleted_at IS NULL', ['t' => $tenantId]);
        return (int) ($row['cnt'] ?? 0);
    }
}
