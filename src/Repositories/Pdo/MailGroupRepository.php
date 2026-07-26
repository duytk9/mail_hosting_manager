<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class MailGroupRepository extends AbstractPdoRepository
{
    /**
     * @param int|null $tenantId When set, restricts the result to that tenant.
     *                           Passing null returns every tenant's rows and is
     *                           only valid for super_admin / support_readonly.
     */
    public function all(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            return $this->fetchAll('SELECT * FROM mail_groups WHERE deleted_at IS NULL ORDER BY id DESC');
        }

        return $this->fetchAll(
            'SELECT * FROM mail_groups WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY id DESC',
            ['tenant_id' => $tenantId]
        );
    }

    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne('SELECT * FROM mail_groups WHERE email = :email AND deleted_at IS NULL', ['email' => $email]);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM mail_groups WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO mail_groups (tenant_id, domain_id, local_part, email, display_name, status, created_at, updated_at) VALUES (:tenant_id, :domain_id, :local_part, :email, :display_name, :status, NOW(), NOW())',
            $data
        );

        return $this->find($this->lastInsertId()) ?? [];
    }

    public function update(int $id, array $data): array
    {
        $this->execute(
            'UPDATE mail_groups SET domain_id = :domain_id, local_part = :local_part, email = :email, display_name = :display_name, status = :status, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            [
                'id' => $id,
                'domain_id' => $data['domain_id'],
                'local_part' => $data['local_part'],
                'email' => $data['email'],
                'display_name' => $data['display_name'],
                'status' => $data['status'],
            ]
        );

        return $this->find($id) ?? [];
    }

    public function softDelete(int $id): void
    {
        $this->execute('DELETE FROM mail_groups WHERE id = :id', [
            'id' => $id,
        ]);
    }
}
