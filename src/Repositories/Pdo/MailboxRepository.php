<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class MailboxRepository extends AbstractPdoRepository
{
    /**
     * @param int|null $tenantId When set, restricts the result to that tenant.
     *                           Passing null returns every tenant's rows and is
     *                           only valid for super_admin / support_readonly.
     */
    public function all(?int $tenantId = null): array
    {
        if ($tenantId === null) {
            return $this->fetchAll('SELECT * FROM mailboxes WHERE deleted_at IS NULL ORDER BY id DESC');
        }

        return $this->fetchAll(
            'SELECT * FROM mailboxes WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY id DESC',
            ['tenant_id' => $tenantId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM mailboxes WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne('SELECT * FROM mailboxes WHERE email = :email AND deleted_at IS NULL', ['email' => $email]);
    }

    public function totalQuotaForTenant(int $tenantId): int
    {
        $row = $this->fetchOne('SELECT COALESCE(SUM(quota_mb), 0) AS total FROM mailboxes WHERE tenant_id = :tenant_id AND deleted_at IS NULL', ['tenant_id' => $tenantId]);

        return (int) ($row['total'] ?? 0);
    }

    public function countByDomain(int $domainId): int
    {
        $row = $this->fetchOne('SELECT COUNT(*) AS total FROM mailboxes WHERE domain_id = :domain_id AND deleted_at IS NULL', ['domain_id' => $domainId]);

        return (int) ($row['total'] ?? 0);
    }

    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO mailboxes (tenant_id, domain_id, local_part, email, password_hash, display_name, quota_mb, status, force_password_change, imap_enabled, pop3_enabled, smtp_enabled, managesieve_enabled, created_at, updated_at) VALUES (:tenant_id, :domain_id, :local_part, :email, :password_hash, :display_name, :quota_mb, :status, :force_password_change, :imap_enabled, :pop3_enabled, :smtp_enabled, :managesieve_enabled, NOW(), NOW())',
            $data
        );

        return $this->find($this->lastInsertId()) ?? [];
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->execute(
            'UPDATE mailboxes SET password_hash = :hash, force_password_change = 0, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'hash' => $hash]
        );
    }

    public function updateStatus(int $id, string $status): void
    {
        $this->execute('UPDATE mailboxes SET status = :status, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL', [
            'id' => $id,
            'status' => $status,
        ]);
    }

    public function updateQuota(int $id, int $quotaMb): void
    {
        $this->execute(
            'UPDATE mailboxes SET quota_mb = :quota_mb, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'quota_mb' => $quotaMb]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute('DELETE FROM mailboxes WHERE id = :id', [
            'id' => $id,
        ]);
    }

    public function markLastLogin(int $id): void
    {
        $this->execute('UPDATE mailboxes SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL', [
            'id' => $id,
        ]);
    }

    public function countByTenant(int $tenantId): int
    {
        $row = $this->fetchOne('SELECT COUNT(id) AS cnt FROM mailboxes WHERE tenant_id = :t AND deleted_at IS NULL', ['t' => $tenantId]);
        return (int) ($row['cnt'] ?? 0);
    }
}
