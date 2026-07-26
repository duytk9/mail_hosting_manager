<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Support\Validator;

final class TenantAdminService
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly DomainRepository $domains,
        private readonly UserRepository $users,
        private readonly UserPasswordHistoryRepository $passwordHistory,
        private readonly AuditLogService $auditLog,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly PasswordHashingService $passwordHasher,
        private readonly SuperAdminLinuxAccountManager $linuxAccounts,
        private readonly ?TenantPurgeRepository $tenantPurge = null
    ) {
    }

    public function createForPrimaryDomain(array $data): array
    {
        Validator::required($data, ['tenant_id', 'name', 'local_part', 'password', 'linux_username']);
        $tenant = $this->tenants->find((int) $data['tenant_id']);

        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        $primaryDomain = $this->domains->findPrimaryByTenant((int) $tenant['id']);
        if ($primaryDomain === null) {
            throw new InvalidArgumentException('Tenant needs a primary domain before creating tenant admin.');
        }

        $localPart = strtolower(trim((string) $data['local_part']));
        Validator::localPart($localPart);
        $email = $localPart . '@' . $primaryDomain['domain'];
        $password = (string) $data['password'];
        $linuxUsername = $this->normalizeLinuxUsername($data['linux_username'] ?? null);
        $this->passwordPolicy->assertStrong($password);

        if ($this->users->findByEmail($email) !== null) {
            throw new InvalidArgumentException('Tenant admin email already exists.');
        }

        if ($linuxUsername === null) {
            throw new InvalidArgumentException('Tenant admin login username is required.');
        }

        if ($this->users->findByLinuxUsername($linuxUsername) !== null) {
            throw new InvalidArgumentException('Tenant admin login username already exists.');
        }

        $user = $this->users->create([
            'tenant_id' => (int) $tenant['id'],
            'role' => 'tenant_admin',
            'name' => trim((string) $data['name']),
            'email' => $email,
            'password_hash' => $this->passwordHasher->hash($password),
            'linux_username' => $linuxUsername,
            'ssh_enabled' => 0,
            'ssh_sudo_enabled' => 0,
            'force_password_change' => (int) ($data['force_password_change'] ?? 0),
        ]);
        $this->passwordHistory->store((int) $user['id'], (int) $tenant['id'], (string) $user['password_hash']);

        try {
            $this->linuxAccounts->syncAccount($linuxUsername, false, false, null, $password);
        } catch (\Throwable $exception) {
            $this->tenantPurge?->purgeUser((int) $user['id']);
            if ($this->tenantPurge === null) {
                $this->users->softDelete((int) $user['id']);
            }
            throw $exception;
        }

        $this->tenants->updateAdminUser((int) $tenant['id'], (int) $user['id']);
        $this->auditLog->log([
            'action' => 'tenant_admin.created',
            'target_type' => 'user',
            'target_id' => $user['id'] ?? null,
            'tenant_id' => $tenant['id'] ?? null,
            'new_values' => [
                'id' => $user['id'] ?? null,
                'email' => $email,
                'linux_username' => $linuxUsername,
                'role' => 'tenant_admin',
            ],
        ]);

        return $user;
    }

    public function update(int $userId, array $data): array
    {
        Validator::required($data, ['name', 'local_part']);
        $user = $this->users->find($userId);

        if ($user === null || (string) ($user['role'] ?? '') !== 'tenant_admin') {
            throw new InvalidArgumentException('Tenant admin not found.');
        }

        $name = trim((string) $data['name']);
        $tenantId = (int) ($user['tenant_id'] ?? 0);
        $tenant = $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant admin must belong to an active tenant.');
        }

        $primaryDomain = $this->domains->findPrimaryByTenant($tenantId);
        if ($primaryDomain === null) {
            throw new InvalidArgumentException('Tenant needs a primary domain before updating tenant admin mailbox.');
        }

        $localPart = strtolower(trim((string) $data['local_part']));
        Validator::localPart($localPart);
        $email = $localPart . '@' . (string) $primaryDomain['domain'];
        $linuxUsername = $this->normalizeLinuxUsername($data['linux_username'] ?? ($user['linux_username'] ?? null));
        if ($name === '') {
            throw new InvalidArgumentException('Tenant admin data is invalid.');
        }

        if ($linuxUsername === null) {
            throw new InvalidArgumentException('Tenant admin login username is required.');
        }

        $existing = $this->users->findByEmail($email);
        if ($existing !== null && (int) ($existing['id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('Tenant admin email already exists.');
        }

        $existingLinux = $linuxUsername !== null ? $this->users->findByLinuxUsername($linuxUsername) : null;
        if ($existingLinux !== null && (int) ($existingLinux['id'] ?? 0) !== $userId) {
            throw new InvalidArgumentException('Tenant admin login username already exists.');
        }

        $linuxUsernameChanged = $linuxUsername !== ($user['linux_username'] ?? null);
        if ($linuxUsernameChanged && empty($data['reset_password'])) {
            throw new InvalidArgumentException('Reset password is required when changing tenant admin login username.');
        }

        $this->users->updateTenantAdminIdentity($userId, $name, $email, $linuxUsername);

        $generatedPassword = null;
        if (!empty($data['reset_password'])) {
            $generatedPassword = bin2hex(random_bytes(16)) . 'A1@';
            $this->passwordPolicy->assertStrong($generatedPassword);
            $hash = $this->passwordHasher->hash($generatedPassword);
            $this->users->updatePassword($userId, $hash);
            $this->users->updateForcePasswordChange($userId, true);
            $this->passwordHistory->store($userId, (int) ($user['tenant_id'] ?? 0), $hash);

            if ($linuxUsername !== null) {
                $this->linuxAccounts->syncAccount($linuxUsername, false, false, null, $generatedPassword);
                if ($linuxUsernameChanged && !empty($user['linux_username'])) {
                    $this->linuxAccounts->purge((string) $user['linux_username']);
                }
            }
        }

        $fresh = $this->users->find($userId) ?? [];
        $this->auditLog->log([
            'action' => 'tenant_admin.updated',
            'target_type' => 'user',
            'target_id' => $userId,
            'tenant_id' => $fresh['tenant_id'] ?? null,
            'old_values' => [
                'name' => $user['name'] ?? null,
                'email' => $user['email'] ?? null,
                'linux_username' => $user['linux_username'] ?? null,
            ],
            'new_values' => [
                'name' => $fresh['name'] ?? null,
                'email' => $fresh['email'] ?? null,
                'linux_username' => $fresh['linux_username'] ?? null,
            ],
        ]);

        return [
            'user' => $fresh,
            'generated_password' => $generatedPassword,
        ];
    }

    public function delete(int $userId): void
    {
        $user = $this->users->find($userId);

        if ($user === null || (string) ($user['role'] ?? '') !== 'tenant_admin') {
            throw new InvalidArgumentException('Tenant admin not found.');
        }

        $tenantId = (int) ($user['tenant_id'] ?? 0);
        $tenant = $tenantId > 0 ? $this->tenants->find($tenantId) : null;
        if ($tenant !== null && $this->users->countActiveTenantAdmins($tenantId) <= 1) {
            throw new InvalidArgumentException('Cannot delete the last tenant admin of an active tenant.');
        }

        $replacement = $tenantId > 0 ? $this->users->findTenantAdminByTenant($tenantId, $userId) : null;

        if (!empty($user['linux_username'])) {
            $this->linuxAccounts->purge((string) $user['linux_username']);
        }

        if ($this->tenantPurge !== null) {
            $this->tenantPurge->purgeUser($userId);
        } else {
            $this->users->softDelete($userId);
        }

        if ($tenant !== null) {
            $this->tenants->updateAdminUser($tenantId, isset($replacement['id']) ? (int) $replacement['id'] : null);
        }

        $this->auditLog->log([
            'action' => 'tenant_admin.deleted',
            'target_type' => 'user',
            'target_id' => $userId,
            'tenant_id' => $tenantId > 0 ? $tenantId : null,
            'old_values' => [
                'id' => $userId,
                'email' => $user['email'] ?? null,
                'linux_username' => $user['linux_username'] ?? null,
            ],
        ]);
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
}
