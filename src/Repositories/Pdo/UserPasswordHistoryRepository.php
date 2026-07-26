<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

// Not final: tests substitute a stub. See AuthRepository for rationale.
class UserPasswordHistoryRepository extends AbstractPdoRepository
{
    public function store(int $userId, ?int $tenantId, string $passwordHash): void
    {
        $this->execute(
            'INSERT INTO user_password_history (tenant_id, user_id, password_hash, created_at, updated_at) VALUES (:tenant_id, :user_id, :password_hash, NOW(), NOW())',
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'password_hash' => $passwordHash,
            ]
        );
    }

    public function recentHashesForUser(int $userId, int $limit): array
    {
        $limit = max(1, min($limit, 50));

        return array_column(
            $this->fetchAll(
                'SELECT password_hash FROM user_password_history WHERE user_id = :user_id ORDER BY id DESC LIMIT ' . $limit,
                ['user_id' => $userId]
            ),
            'password_hash'
        );
    }
}
