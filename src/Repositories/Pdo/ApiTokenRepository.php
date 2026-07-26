<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

use MailPanel\Services\TenantLifecyclePolicy;

class ApiTokenRepository extends AbstractPdoRepository
{
    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO api_tokens (tenant_id, user_id, mailbox_id, actor_role, name, token_hash, scopes, last_used_at, expires_at, created_at, updated_at) VALUES (:tenant_id, :user_id, :mailbox_id, :actor_role, :name, :token_hash, :scopes, NULL, :expires_at, NOW(), NOW())',
            $data
        );

        return $this->fetchOne('SELECT * FROM api_tokens WHERE id = :id', ['id' => $this->lastInsertId()]) ?? [];
    }

    public function findByHash(string $tokenHash): ?array
    {
        return $this->fetchOne(
            'SELECT at.*
             FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id
             LEFT JOIN mailboxes m ON m.id = at.mailbox_id
             LEFT JOIN domains d ON d.id = m.domain_id AND d.deleted_at IS NULL
             LEFT JOIN tenants mailbox_tenant ON mailbox_tenant.id = m.tenant_id AND mailbox_tenant.deleted_at IS NULL
             LEFT JOIN tenants user_tenant ON user_tenant.id = u.tenant_id AND user_tenant.deleted_at IS NULL
             WHERE at.token_hash = :token_hash
               AND at.revoked_at IS NULL
               AND (at.expires_at IS NULL OR at.expires_at > NOW())
               AND (
                    (
                        at.user_id IS NOT NULL
                        AND u.id IS NOT NULL
                        AND u.deleted_at IS NULL
                        AND u.role = at.actor_role
                        AND COALESCE(u.force_password_change, 0) = 0
                        AND (u.tenant_id IS NULL OR ' . TenantLifecyclePolicy::sqlMailAccessCondition('user_tenant') . ')
                    )
                    OR
                    (
                        at.mailbox_id IS NOT NULL
                        AND m.id IS NOT NULL
                        AND m.deleted_at IS NULL
                        AND m.status = \'active\'
                        AND COALESCE(m.force_password_change, 0) = 0
                        AND d.id IS NOT NULL
                        AND d.status = \'active\'
                        AND d.inbound_enabled = 1
                        AND mailbox_tenant.id IS NOT NULL
                        AND ' . TenantLifecyclePolicy::sqlMailAccessCondition('mailbox_tenant') . '
                    )
               )',
            ['token_hash' => $tokenHash]
        );
    }

    public function touch(int $id): void
    {
        $this->execute('UPDATE api_tokens SET last_used_at = NOW(), updated_at = NOW() WHERE id = :id', ['id' => $id]);
    }

    public function allForUser(?int $userId, ?int $mailboxId): array
    {
        if ($userId === null && $mailboxId === null) {
            return [];
        }

        return $this->fetchAll(
            'SELECT at.*
             FROM api_tokens at
             LEFT JOIN users u ON u.id = at.user_id
             LEFT JOIN mailboxes m ON m.id = at.mailbox_id
             LEFT JOIN domains d ON d.id = m.domain_id AND d.deleted_at IS NULL
             LEFT JOIN tenants mailbox_tenant ON mailbox_tenant.id = m.tenant_id AND mailbox_tenant.deleted_at IS NULL
             LEFT JOIN tenants user_tenant ON user_tenant.id = u.tenant_id AND user_tenant.deleted_at IS NULL
             WHERE (
                    (:user_id IS NOT NULL AND at.user_id = :user_id)
                    OR
                    (:mailbox_id IS NOT NULL AND at.mailbox_id = :mailbox_id)
               )
               AND at.revoked_at IS NULL
               AND (at.expires_at IS NULL OR at.expires_at > NOW())
               AND (
                    (
                        at.user_id IS NOT NULL
                        AND u.id IS NOT NULL
                        AND u.deleted_at IS NULL
                        AND u.role = at.actor_role
                        AND COALESCE(u.force_password_change, 0) = 0
                        AND (u.tenant_id IS NULL OR ' . TenantLifecyclePolicy::sqlMailAccessCondition('user_tenant') . ')
                    )
                    OR
                    (
                        at.mailbox_id IS NOT NULL
                        AND m.id IS NOT NULL
                        AND m.deleted_at IS NULL
                        AND m.status = \'active\'
                        AND COALESCE(m.force_password_change, 0) = 0
                        AND d.id IS NOT NULL
                        AND d.status = \'active\'
                        AND d.inbound_enabled = 1
                        AND mailbox_tenant.id IS NOT NULL
                        AND ' . TenantLifecyclePolicy::sqlMailAccessCondition('mailbox_tenant') . '
                    )
               )
             ORDER BY at.id DESC',
            ['user_id' => $userId, 'mailbox_id' => $mailboxId]
        );
    }
}
