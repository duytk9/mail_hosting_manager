<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class ForwardRepository extends AbstractPdoRepository
{
    /**
     * @param int|null $tenantId When set, restricts the result to that tenant.
     *                           Passing null returns every tenant's rows and is
     *                           only valid for super_admin / support_readonly.
     */
    public function all(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            return $this->fetchAll('SELECT * FROM forwards WHERE deleted_at IS NULL ORDER BY id DESC');
        }

        return $this->fetchAll(
            'SELECT * FROM forwards WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY id DESC',
            ['tenant_id' => $tenantId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM forwards WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function findBySource(string $source): ?array
    {
        return $this->fetchOne('SELECT * FROM forwards WHERE source_address = :source AND deleted_at IS NULL', ['source' => $source]);
    }

    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO forwards (tenant_id, domain_id, source_address, destination_address, keep_copy, created_at, updated_at) VALUES (:tenant_id, :domain_id, :source_address, :destination_address, :keep_copy, NOW(), NOW())',
            $data
        );

        return $this->fetchOne('SELECT * FROM forwards WHERE id = :id', ['id' => $this->lastInsertId()]) ?? [];
    }

    public function softDelete(int $id): void
    {
        $this->execute('DELETE FROM forwards WHERE id = :id', [
            'id' => $id,
        ]);
    }

    public function countByTenant(int $tenantId): int
    {
        $row = $this->fetchOne('SELECT COUNT(id) AS cnt FROM forwards WHERE tenant_id = :t AND deleted_at IS NULL', ['t' => $tenantId]);
        return (int) ($row['cnt'] ?? 0);
    }
}
