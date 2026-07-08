<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

final class UserRepository extends AbstractPdoRepository
{
    public function allSuperAdmins(): array
    {
        return $this->fetchAll('SELECT * FROM users WHERE role = :role AND deleted_at IS NULL ORDER BY id DESC', ['role' => 'super_admin']);
    }

    public function allTenantAdmins(): array
    {
        return $this->fetchAll('SELECT * FROM users WHERE role = :role AND deleted_at IS NULL ORDER BY id DESC', ['role' => 'tenant_admin']);
    }

    public function findTenantAdminByTenant(int $tenantId, ?int $excludeId = null): ?array
    {
        $params = [
            'tenant_id' => $tenantId,
            'role' => 'tenant_admin',
        ];
        $sql = 'SELECT * FROM users WHERE tenant_id = :tenant_id AND role = :role AND deleted_at IS NULL';

        if ($excludeId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params['exclude_id'] = $excludeId;
        }

        $sql .= ' ORDER BY id DESC LIMIT 1';

        return $this->fetchOne($sql, $params);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->fetchOne('SELECT * FROM users WHERE email = :email AND deleted_at IS NULL', ['email' => $email]);
    }

    public function findByLinuxUsername(string $linuxUsername): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM users WHERE linux_username = :linux_username AND deleted_at IS NULL',
            ['linux_username' => $linuxUsername]
        );
    }

    public function findAdminByLogin(string $login): ?array
    {
        $login = strtolower(trim($login));
        if ($login === '') {
            return null;
        }

        return $this->fetchOne(
            'SELECT * FROM users
             WHERE deleted_at IS NULL
               AND role IN (:super_admin, :tenant_admin, :domain_admin, :support_readonly)
               AND (LOWER(email) = :email_login OR LOWER(linux_username) = :linux_login)
             ORDER BY id DESC
             LIMIT 1',
            [
                'super_admin' => 'super_admin',
                'tenant_admin' => 'tenant_admin',
                'domain_admin' => 'domain_admin',
                'support_readonly' => 'support_readonly',
                'email_login' => $login,
                'linux_login' => $login,
            ]
        );
    }

    public function create(array $data): array
    {
        $payload = $data + [
            'linux_username' => null,
            'ssh_enabled' => 0,
            'ssh_sudo_enabled' => 0,
            'ssh_public_key' => null,
            'force_password_change' => 0,
            'totp_secret' => null,
            'totp_pending_secret' => null,
            'totp_enabled' => 0,
        ];

        $this->execute(
            'INSERT INTO users (tenant_id, role, name, email, password_hash, linux_username, ssh_enabled, ssh_sudo_enabled, ssh_public_key, force_password_change, totp_secret, totp_pending_secret, totp_enabled, password_changed_at, created_at, updated_at) VALUES (:tenant_id, :role, :name, :email, :password_hash, :linux_username, :ssh_enabled, :ssh_sudo_enabled, :ssh_public_key, :force_password_change, :totp_secret, :totp_pending_secret, :totp_enabled, NOW(), NOW(), NOW())',
            $payload
        );

        return $this->find($this->lastInsertId()) ?? [];
    }

    public function updateLinuxAccess(int $id, array $data): void
    {
        $this->execute(
            'UPDATE users SET linux_username = :linux_username, ssh_enabled = :ssh_enabled, ssh_sudo_enabled = :ssh_sudo_enabled, ssh_public_key = :ssh_public_key, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            [
                'id' => $id,
                'linux_username' => $data['linux_username'] ?? null,
                'ssh_enabled' => (int) ($data['ssh_enabled'] ?? 0),
                'ssh_sudo_enabled' => (int) ($data['ssh_sudo_enabled'] ?? 0),
                'ssh_public_key' => $data['ssh_public_key'] ?? null,
            ]
        );
    }

    public function updateProfile(int $id, string $name, string $email): void
    {
        $this->execute(
            'UPDATE users SET name = :name, email = :email, updated_at = NOW() WHERE id = :id AND role = :role AND deleted_at IS NULL',
            [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'role' => 'tenant_admin',
            ]
        );
    }

    public function updateTenantAdminIdentity(int $id, string $name, string $email, ?string $linuxUsername): void
    {
        $this->execute(
            'UPDATE users
             SET name = :name,
                 email = :email,
                 linux_username = :linux_username,
                 updated_at = NOW()
             WHERE id = :id AND role = :role AND deleted_at IS NULL',
            [
                'id' => $id,
                'name' => $name,
                'email' => $email,
                'linux_username' => $linuxUsername,
                'role' => 'tenant_admin',
            ]
        );
    }

    public function softDelete(int $id): void
    {
        $this->execute('DELETE FROM users WHERE id = :id', [
            'id' => $id,
        ]);
    }

    public function updatePassword(int $id, string $hash): void
    {
        $this->execute(
            'UPDATE users SET password_hash = :hash, force_password_change = 0, password_changed_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'hash' => $hash]
        );
    }

    public function syncPasswordHash(int $id, string $hash): void
    {
        $this->execute(
            'UPDATE users SET password_hash = :hash, password_changed_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'hash' => $hash]
        );
    }

    public function updateForcePasswordChange(int $id, bool $enabled): void
    {
        $this->execute(
            'UPDATE users SET force_password_change = :enabled, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'enabled' => $enabled ? 1 : 0]
        );
    }

    public function updateLastLogin(int $id): void
    {
        $this->execute(
            'UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function incrementTotpGraceLoginCount(int $id): int
    {
        $this->execute(
            'UPDATE users SET totp_grace_login_count = COALESCE(totp_grace_login_count, 0) + 1, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );

        $row = $this->fetchOne(
            'SELECT totp_grace_login_count FROM users WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );

        return (int) ($row['totp_grace_login_count'] ?? 0);
    }

    public function resetTotpGraceLoginCount(int $id): void
    {
        $this->execute(
            'UPDATE users SET totp_grace_login_count = 0, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function lockForSecurity(int $id, string $reason): void
    {
        $this->execute(
            'UPDATE users SET security_locked_at = NOW(), security_lock_reason = :reason, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'reason' => substr($reason, 0, 100)]
        );
    }

    public function clearSecurityLock(int $id): void
    {
        $this->execute(
            'UPDATE users SET security_locked_at = NULL, security_lock_reason = NULL, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }

    public function countActiveTenantAdmins(int $tenantId): int
    {
        $result = $this->fetchOne(
            'SELECT COUNT(*) AS aggregate_count FROM users WHERE tenant_id = :tenant_id AND role = :role AND deleted_at IS NULL',
            ['tenant_id' => $tenantId, 'role' => 'tenant_admin']
        );

        return (int) ($result['aggregate_count'] ?? 0);
    }

    public function storePendingTotpSecret(int $id, ?string $secret): void
    {
        $this->execute(
            'UPDATE users SET totp_pending_secret = :secret, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'secret' => $secret]
        );
    }

    public function enableTotp(int $id, string $secret): void
    {
        $this->execute(
            'UPDATE users SET totp_secret = :secret, totp_pending_secret = NULL, totp_enabled = 1, totp_confirmed_at = NOW(), totp_grace_login_count = 0, security_locked_at = NULL, security_lock_reason = NULL, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id, 'secret' => $secret]
        );
    }

    public function disableTotp(int $id): void
    {
        $this->execute(
            'UPDATE users SET totp_secret = NULL, totp_pending_secret = NULL, totp_enabled = 0, totp_confirmed_at = NULL, totp_grace_login_count = 0, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id]
        );
    }
}
