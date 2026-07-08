<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Support\Validator;

final class SuperAdminService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHistoryRepository $passwordHistory,
        private readonly SuperAdminLinuxAccountManager $linuxAccounts,
        private readonly AuditLogService $auditLog,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly PasswordHashingService $passwordHasher,
        private readonly ?TenantPurgeRepository $tenantPurge = null
    ) {
    }

    public function list(): array
    {
        return $this->users->allSuperAdmins();
    }

    public function create(array $data): array
    {
        Validator::required($data, ['name', 'email', 'password', 'linux_username']);

        $email = strtolower(trim((string) $data['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Super admin email is invalid.');
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new InvalidArgumentException('Super admin email already exists.');
        }

        $password = (string) $data['password'];
        $this->passwordPolicy->assertStrong($password);

        $linuxUsername = $this->normalizeLinuxUsername($data['linux_username'] ?? null);
        $sshEnabled = !empty($data['ssh_enabled']);
        $sshSudoEnabled = !empty($data['ssh_sudo_enabled']);
        $sshPublicKey = trim((string) ($data['ssh_public_key'] ?? ''));

        $this->validateLinuxAccess($linuxUsername, $sshEnabled, $sshSudoEnabled, $sshPublicKey);

        if ($linuxUsername !== null && $this->users->findByLinuxUsername($linuxUsername) !== null) {
            throw new InvalidArgumentException('Linux username already mapped to another super admin.');
        }

        $user = $this->users->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'name' => trim((string) $data['name']),
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'linux_username' => $linuxUsername,
            'ssh_enabled' => $sshEnabled ? 1 : 0,
            'ssh_sudo_enabled' => $sshSudoEnabled ? 1 : 0,
            'ssh_public_key' => $sshPublicKey !== '' ? $sshPublicKey : null,
            'force_password_change' => (int) ($data['force_password_change'] ?? 0),
        ]);
        $this->passwordHistory->store((int) $user['id'], null, (string) $user['password_hash']);

        try {
            if ($linuxUsername !== null) {
                $this->linuxAccounts->syncAccount(
                    $linuxUsername,
                    $sshEnabled,
                    $sshSudoEnabled,
                    $sshPublicKey !== '' ? $sshPublicKey : null,
                    $password
                );
            }
        } catch (\Throwable $exception) {
            $this->tenantPurge?->purgeUser((int) $user['id']);
            if ($this->tenantPurge === null) {
                $this->users->softDelete((int) $user['id']);
            }
            throw $exception;
        }

        $this->auditLog->log([
            'action' => 'super_admin.created',
            'target_type' => 'user',
            'target_id' => $user['id'] ?? null,
            'new_values' => [
                'id' => $user['id'] ?? null,
                'email' => $email,
                'role' => 'super_admin',
                'linux_username' => $linuxUsername,
                'ssh_enabled' => $sshEnabled,
                'ssh_sudo_enabled' => $sshSudoEnabled,
            ],
        ]);

        return $user;
    }

    public function syncAccess(int $userId, array $data): array
    {
        $user = $this->requireSuperAdmin($userId);

        $linuxUsername = $this->normalizeLinuxUsername($data['linux_username'] ?? ($user['linux_username'] ?? null));
        $sshEnabled = !empty($data['ssh_enabled']);
        $sshSudoEnabled = !empty($data['ssh_sudo_enabled']);
        $sshPublicKey = trim((string) ($data['ssh_public_key'] ?? ''));
        $resetPassword = !empty($data['reset_password']);

        if ($linuxUsername === null) {
            throw new InvalidArgumentException('Linux username is required for super admin accounts.');
        }

        $this->validateLinuxAccess($linuxUsername, $sshEnabled, $sshSudoEnabled, $sshPublicKey);

        $existing = $linuxUsername !== null ? $this->users->findByLinuxUsername($linuxUsername) : null;
        if ($existing !== null && (int) $existing['id'] !== $userId) {
            throw new InvalidArgumentException('Linux username already mapped to another super admin.');
        }

        $previousLinuxUsername = $this->normalizeLinuxUsername($user['linux_username'] ?? null);
        $linuxUsernameChanged = $linuxUsername !== $previousLinuxUsername;
        if ($linuxUsernameChanged && !$resetPassword) {
            throw new InvalidArgumentException('Reset password is required when changing super admin Linux username.');
        }

        $newPassword = null;
        if ($resetPassword) {
            $newPassword = (string) ($data['new_password'] ?? '');
            if ($newPassword === '') {
                throw new InvalidArgumentException('New password is required when resetting super admin access.');
            }
            $this->passwordPolicy->assertStrong($newPassword);
        }

        $this->linuxAccounts->syncAccount(
            $linuxUsername,
            $sshEnabled,
            $sshSudoEnabled,
            $sshPublicKey !== '' ? $sshPublicKey : null,
            $newPassword
        );

        if ($linuxUsernameChanged && $previousLinuxUsername !== null) {
            $this->linuxAccounts->purge($previousLinuxUsername);
        }

        if ($newPassword !== null) {
            $hash = $this->passwordHasher->hash($newPassword);
            $this->users->updatePassword($userId, $hash);
            $this->users->updateForcePasswordChange($userId, true);
            $this->passwordHistory->store($userId, null, $hash);
        }

        $this->users->updateLinuxAccess($userId, [
            'linux_username' => $linuxUsername,
            'ssh_enabled' => $sshEnabled ? 1 : 0,
            'ssh_sudo_enabled' => $sshSudoEnabled ? 1 : 0,
            'ssh_public_key' => $sshPublicKey !== '' ? $sshPublicKey : null,
        ]);

        $updated = $this->requireSuperAdmin($userId);
        $this->auditLog->log([
            'action' => 'super_admin.access_synced',
            'target_type' => 'user',
            'target_id' => $userId,
            'old_values' => [
                'linux_username' => $previousLinuxUsername,
                'ssh_enabled' => !empty($user['ssh_enabled']),
                'ssh_sudo_enabled' => !empty($user['ssh_sudo_enabled']),
            ],
            'new_values' => [
                'linux_username' => $linuxUsername,
                'ssh_enabled' => $sshEnabled,
                'ssh_sudo_enabled' => $sshSudoEnabled,
                'force_password_change' => $newPassword !== null ? 1 : (int) ($updated['force_password_change'] ?? 0),
            ],
        ]);

        return [
            'user' => $updated,
            'password_reset' => $newPassword !== null,
        ];
    }
    public function toggleSsh(int $userId): array
    {
        $user = $this->requireSuperAdmin($userId);
        
        $linuxUsername = $this->normalizeLinuxUsername($user['linux_username'] ?? null);
        if ($linuxUsername === null) {
            throw new InvalidArgumentException('Tài khoản chưa có username hệ thống.');
        }

        $sshEnabled = empty($user['ssh_enabled']);
        $sshSudoEnabled = !empty($user['ssh_sudo_enabled']);
        if (!$sshEnabled && $sshSudoEnabled) {
            $sshSudoEnabled = false;
        }
        
        $sshPublicKey = (string) ($user['ssh_public_key'] ?? '');

        $this->linuxAccounts->syncAccount(
            $linuxUsername,
            $sshEnabled,
            $sshSudoEnabled,
            $sshPublicKey !== '' ? $sshPublicKey : null,
            null
        );

        $this->users->updateLinuxAccess($userId, [
            'linux_username' => $linuxUsername,
            'ssh_enabled' => $sshEnabled ? 1 : 0,
            'ssh_sudo_enabled' => $sshSudoEnabled ? 1 : 0,
            'ssh_public_key' => $sshPublicKey !== '' ? $sshPublicKey : null,
        ]);

        $updated = $this->requireSuperAdmin($userId);
        $this->auditLog->log([
            'action' => 'super_admin.ssh_toggled',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => ['ssh_enabled' => $sshEnabled ? 1 : 0, 'ssh_sudo_enabled' => $sshSudoEnabled ? 1 : 0],
        ]);

        return $updated;
    }

    public function toggleSudo(int $userId): array
    {
        $user = $this->requireSuperAdmin($userId);
        
        $linuxUsername = $this->normalizeLinuxUsername($user['linux_username'] ?? null);
        if ($linuxUsername === null) {
            throw new InvalidArgumentException('Tài khoản chưa có username hệ thống.');
        }

        $sshEnabled = !empty($user['ssh_enabled']);
        $sshSudoEnabled = empty($user['ssh_sudo_enabled']);
        
        if ($sshSudoEnabled && !$sshEnabled) {
            throw new InvalidArgumentException('Không thể bật Sudo khi SSH đang tắt.');
        }
        
        $sshPublicKey = (string) ($user['ssh_public_key'] ?? '');

        $this->linuxAccounts->syncAccount(
            $linuxUsername,
            $sshEnabled,
            $sshSudoEnabled,
            $sshPublicKey !== '' ? $sshPublicKey : null,
            null
        );

        $this->users->updateLinuxAccess($userId, [
            'linux_username' => $linuxUsername,
            'ssh_enabled' => $sshEnabled ? 1 : 0,
            'ssh_sudo_enabled' => $sshSudoEnabled ? 1 : 0,
            'ssh_public_key' => $sshPublicKey !== '' ? $sshPublicKey : null,
        ]);

        $updated = $this->requireSuperAdmin($userId);
        $this->auditLog->log([
            'action' => 'super_admin.sudo_toggled',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => ['ssh_sudo_enabled' => $sshSudoEnabled ? 1 : 0],
        ]);

        return $updated;
    }
    public function disableSsh(int $userId): array
    {
        $user = $this->requireSuperAdmin($userId);

        if (!empty($user['linux_username'])) {
            $this->linuxAccounts->revoke((string) $user['linux_username']);
        }

        $this->users->updateLinuxAccess($userId, [
            'linux_username' => $user['linux_username'] ?? null,
            'ssh_enabled' => 0,
            'ssh_sudo_enabled' => 0,
            'ssh_public_key' => null,
        ]);

        $updated = $this->requireSuperAdmin($userId);
        $this->auditLog->log([
            'action' => 'super_admin.ssh_disabled',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => ['ssh_enabled' => 0, 'ssh_sudo_enabled' => 0],
        ]);

        return $updated;
    }

    public function delete(int $userId, ?int $actorId = null, bool $purgeLinuxAccount = false): void
    {
        $user = $this->requireSuperAdmin($userId);

        if ($actorId !== null && $actorId === $userId) {
            throw new InvalidArgumentException('You cannot delete the currently logged-in super admin.');
        }

        if (!empty($user['linux_username'])) {
            if ($purgeLinuxAccount) {
                $this->linuxAccounts->purge((string) $user['linux_username']);
            } else {
                $this->linuxAccounts->revoke((string) $user['linux_username']);
            }
        }

        if ($this->tenantPurge !== null) {
            $this->tenantPurge->purgeUser($userId);
        } else {
            $this->users->softDelete($userId);
        }

        $this->auditLog->log([
            'action' => 'super_admin.deleted',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => [
                'email' => $user['email'] ?? null,
                'linux_username' => $user['linux_username'] ?? null,
                'purge_linux_account' => $purgeLinuxAccount,
            ],
        ]);
    }

    public function resetPassword(int $userId, string $newPassword): array
    {
        $user = $this->requireSuperAdmin($userId);
        $newPassword = (string) $newPassword;
        if ($newPassword === '') {
            throw new InvalidArgumentException('New password is required.');
        }
        $this->passwordPolicy->assertStrong($newPassword);

        if (!empty($user['linux_username'])) {
            $this->linuxAccounts->syncAccount(
                (string) $user['linux_username'],
                !empty($user['ssh_enabled']),
                !empty($user['ssh_sudo_enabled']),
                isset($user['ssh_public_key']) ? (string) $user['ssh_public_key'] : null,
                $newPassword
            );
        }

        $hash = $this->passwordHasher->hash($newPassword);
        $this->users->updatePassword($userId, $hash);
        $this->users->updateForcePasswordChange($userId, true);
        $this->passwordHistory->store($userId, null, $hash);

        $fresh = $this->requireSuperAdmin($userId);
        $this->auditLog->log([
            'action' => 'super_admin.password_reset',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => [
                'linux_username' => $fresh['linux_username'] ?? null,
                'force_password_change' => 1,
            ],
        ]);

        return [
            'user' => $fresh,
            'password_reset' => true,
        ];
    }

    public function setSecurityLock(int $userId, bool $locked, ?int $actorId = null): array
    {
        $user = $this->requireSuperAdmin($userId);

        if ($locked && $actorId !== null && $actorId === $userId) {
            throw new InvalidArgumentException('You cannot lock the currently logged-in super admin.');
        }

        if ($locked) {
            $this->users->lockForSecurity($userId, 'manual_super_admin_lock');
        } else {
            $this->users->clearSecurityLock($userId);
            $this->users->resetTotpGraceLoginCount($userId);
        }

        $updated = $this->requireSuperAdmin($userId);
        $this->auditLog->log([
            'actor_id' => $actorId,
            'actor_role' => 'super_admin',
            'action' => $locked ? 'super_admin.security_locked' : 'super_admin.security_unlocked',
            'target_type' => 'user',
            'target_id' => $userId,
            'old_values' => [
                'security_locked_at' => $user['security_locked_at'] ?? null,
                'security_lock_reason' => $user['security_lock_reason'] ?? null,
            ],
            'new_values' => [
                'security_locked_at' => $updated['security_locked_at'] ?? null,
                'security_lock_reason' => $updated['security_lock_reason'] ?? null,
            ],
        ]);

        return $updated;
    }

    private function requireSuperAdmin(int $userId): array
    {
        $user = $this->users->find($userId);

        if ($user === null || ($user['role'] ?? null) !== 'super_admin') {
            throw new InvalidArgumentException('Super admin not found.');
        }

        return $user;
    }

    private function normalizeLinuxUsername(mixed $value): ?string
    {
        $username = strtolower(trim((string) ($value ?? '')));
        if ($username === '') {
            return null;
        }

        Validator::linuxUsername($username);

        return $username;
    }

    private function validateLinuxAccess(?string $linuxUsername, bool $sshEnabled, bool $sshSudoEnabled, string $sshPublicKey): void
    {
        if ($sshEnabled && $linuxUsername === null) {
            throw new InvalidArgumentException('Linux username is required when SSH is enabled.');
        }

        if ($sshSudoEnabled && !$sshEnabled) {
            throw new InvalidArgumentException('SSH must be enabled before sudo can be granted.');
        }

        if ($sshPublicKey !== '' && !preg_match('/^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521))\s+[A-Za-z0-9+\/=]+(?:\s+.*)?$/', $sshPublicKey)) {
            throw new InvalidArgumentException('SSH public key format is invalid.');
        }
    }
}
