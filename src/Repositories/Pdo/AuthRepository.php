<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

use MailPanel\Services\TenantLifecyclePolicy;

final class AuthRepository extends AbstractPdoRepository
{
    public function findAdminByLogin(string $login): ?array
    {
        $sql = 'SELECT u.* FROM users u
             LEFT JOIN tenants user_tenant ON user_tenant.id = u.tenant_id AND user_tenant.deleted_at IS NULL
             WHERE u.deleted_at IS NULL
               AND u.linux_username = :login
               AND u.role IN (\'super_admin\', \'tenant_admin\', \'domain_admin\', \'support_readonly\')
               AND u.security_locked_at IS NULL
               AND (u.tenant_id IS NULL OR ' . TenantLifecyclePolicy::sqlMailAccessCondition('user_tenant') . ')
             LIMIT 1';

        return $this->fetchOne($sql, ['login' => $login]);
    }

    public function findMailboxByEmail(string $email): ?array
    {
        return $this->fetchOne(
            'SELECT m.*, d.status AS domain_status, d.inbound_enabled, d.outbound_enabled, t.status AS tenant_status, t.billing_status AS tenant_billing_status
             FROM mailboxes m
             INNER JOIN domains d ON d.id = m.domain_id AND d.deleted_at IS NULL
             INNER JOIN tenants t ON t.id = m.tenant_id AND t.deleted_at IS NULL
             WHERE m.email = :email
               AND m.deleted_at IS NULL
               AND m.status = "active"
               AND d.status = "active"
               AND d.inbound_enabled = 1
               AND ' . TenantLifecyclePolicy::sqlMailAccessCondition('t'),
            ['email' => $email]
        );
    }

    public function updateAdminLastLogin(int $userId): void
    {
        $this->execute('UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL', ['id' => $userId]);
    }

    public function updateMailboxLastLogin(int $mailboxId): void
    {
        $this->execute('UPDATE mailboxes SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL', ['id' => $mailboxId]);
    }
}
