<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class SpamPolicyRepository extends AbstractPdoRepository
{
    public function findByDomainId(int $domainId): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM spam_policies WHERE domain_id = :domain_id');
        $stmt->execute(['domain_id' => $domainId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findByTenantId(int $tenantId): array
    {
        $stmt = $this->pdo()->prepare('SELECT * FROM spam_policies WHERE tenant_id = :tenant_id');
        $stmt->execute(['tenant_id' => $tenantId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function findAll(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM spam_policies');
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function updateListsByDomainId(int $domainId, string $allowlistSenders, string $blocklistSenders): bool
    {
        $stmt = $this->pdo()->prepare('
            UPDATE spam_policies 
            SET allowlist_senders = :allowlist_senders, 
                blocklist_senders = :blocklist_senders,
                updated_at = CURRENT_TIMESTAMP
            WHERE domain_id = :domain_id
        ');

        $stmt->execute([
            'domain_id' => $domainId,
            'allowlist_senders' => $allowlistSenders,
            'blocklist_senders' => $blocklistSenders,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function createForDomain(int $tenantId, int $domainId, string $allowlistSenders = '', string $blocklistSenders = ''): int
    {
        $stmt = $this->pdo()->prepare('
            INSERT INTO spam_policies (tenant_id, domain_id, allowlist_senders, blocklist_senders, created_at, updated_at)
            VALUES (:tenant_id, :domain_id, :allowlist_senders, :blocklist_senders, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ');
        $stmt->execute([
            'tenant_id' => $tenantId,
            'domain_id' => $domainId,
            'allowlist_senders' => $allowlistSenders,
            'blocklist_senders' => $blocklistSenders,
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
