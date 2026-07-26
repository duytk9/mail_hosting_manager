<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class MailboxPasswordHistoryRepository extends AbstractPdoRepository
{
    public function store(int $mailboxId, int $tenantId, string $passwordHash): void
    {
        $this->execute(
            'INSERT INTO mailbox_password_history (tenant_id, mailbox_id, password_hash, created_at, updated_at) VALUES (:tenant_id, :mailbox_id, :password_hash, NOW(), NOW())',
            [
                'tenant_id' => $tenantId,
                'mailbox_id' => $mailboxId,
                'password_hash' => $passwordHash,
            ]
        );
    }

    public function recentHashesForMailbox(int $mailboxId, int $limit): array
    {
        $limit = max(1, min($limit, 50));

        return array_column(
            $this->fetchAll(
                'SELECT password_hash FROM mailbox_password_history WHERE mailbox_id = :mailbox_id ORDER BY id DESC LIMIT ' . $limit,
                ['mailbox_id' => $mailboxId]
            ),
            'password_hash'
        );
    }
}
