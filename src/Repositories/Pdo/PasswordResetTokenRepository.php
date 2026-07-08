<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

final class PasswordResetTokenRepository extends AbstractPdoRepository
{
    public function invalidateActiveForUser(int $userId): void
    {
        $this->execute(
            'UPDATE password_reset_tokens
             SET used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE user_id = :user_id AND used_at IS NULL',
            ['user_id' => $userId]
        );
    }

    public function create(int $userId, string $tokenHash, string $expiresAt, ?string $ipAddress, ?string $userAgent): void
    {
        $this->execute(
            'INSERT INTO password_reset_tokens
                (user_id, token_hash, expires_at, request_ip, request_user_agent, created_at, updated_at)
             VALUES
                (:user_id, :token_hash, :expires_at, :request_ip, :request_user_agent, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            [
                'user_id' => $userId,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'request_ip' => $ipAddress !== null ? substr($ipAddress, 0, 45) : null,
                'request_user_agent' => $userAgent !== null ? substr($userAgent, 0, 255) : null,
            ]
        );
    }

    public function findUsableByHash(string $tokenHash, string $now): ?array
    {
        return $this->fetchOne(
            'SELECT prt.*, u.email, u.name, u.role, u.tenant_id, u.password_hash, u.linux_username,
                    u.ssh_enabled, u.ssh_sudo_enabled, u.ssh_public_key, u.deleted_at
             FROM password_reset_tokens prt
             INNER JOIN users u ON u.id = prt.user_id
             WHERE prt.token_hash = :token_hash
               AND prt.used_at IS NULL
               AND prt.expires_at > :now
               AND u.deleted_at IS NULL
             LIMIT 1',
            [
                'token_hash' => $tokenHash,
                'now' => $now,
            ]
        );
    }

    public function markUsed(int $id): void
    {
        $this->execute(
            'UPDATE password_reset_tokens
             SET used_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND used_at IS NULL',
            ['id' => $id]
        );
    }
}
