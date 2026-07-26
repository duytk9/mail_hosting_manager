<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\AuthRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Contracts\MailboxPasswordManager;
use MailPanel\Security\IpAllowlist;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TotpService;

final class AuthService
{
    public function __construct(
        private readonly AuthRepository $authRepository,
        private readonly UserRepository $users,
        private readonly UserPasswordHistoryRepository $passwordHistory,
        private readonly AuditLogService $auditLog,
        private readonly SessionManager $sessions,
        private readonly PasswordHashingService $passwordHasher,
        private readonly MailboxPasswordManager $mailboxPasswords,
        private readonly RateLimiterService $rateLimiter,
        private readonly TotpService $totp,
        private readonly AdminPasswordVerifier $adminPasswordVerifier,
        private readonly array $appConfig = []
    ) {
    }

    public function loginAdmin(
        string $login,
        string $password,
        ?string $oneTimeCode = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $login = strtolower(trim($login));
        $ipAddress ??= '127.0.0.1';
        $rateLimit = $this->appConfig['rate_limits']['admin_login'] ?? ['max_attempts' => 5, 'window_seconds' => 900];
        $rateLimitKey = sprintf('admin-login:%s:%s', $login, $ipAddress);
        $this->rateLimiter->assertWithinLimit($rateLimitKey, (int) $rateLimit['max_attempts'], (int) $rateLimit['window_seconds']);

        $user = $this->authRepository->findAdminByLogin($login);
        $verification = $user !== null ? $this->adminPasswordVerifier->verify($user, $password) : ['verified' => false];

        if ($user === null || !$verification['verified']) {
            $this->recordFailure($rateLimitKey, $rateLimit, 'auth.admin_login.failed', $user, $ipAddress, $userAgent, 'user');
            throw new InvalidArgumentException('Invalid credentials.');
        }

        if (!empty($verification['migrated'])) {
            $user = $this->syncPanelPasswordFromSuccessfulLinuxLogin($user, $password, $ipAddress, $userAgent);
        }

        if ($this->isSuperAdminIpAllowlistEnabled() && ($user['role'] ?? null) === 'super_admin' && !$this->isAllowedSuperAdminIp($ipAddress)) {
            $this->recordFailure($rateLimitKey, $rateLimit, 'auth.admin_login.ip_denied', $user, $ipAddress, $userAgent, 'user');
            throw new InvalidArgumentException('Super admin login is not allowed from this IP.');
        }

        if (!empty($user['totp_enabled'])) {
            if ($oneTimeCode === null || trim($oneTimeCode) === '') {
                $this->recordFailure($rateLimitKey, $rateLimit, 'auth.admin_login.totp_required', $user, $ipAddress, $userAgent, 'user');
                throw new InvalidArgumentException('One-time password is required.');
            }

            if (!$this->totp->verify((string) ($user['totp_secret'] ?? ''), $oneTimeCode)) {
                $this->recordFailure($rateLimitKey, $rateLimit, 'auth.admin_login.totp_failed', $user, $ipAddress, $userAgent, 'user');
                throw new InvalidArgumentException('Invalid one-time password.');
            }
        }

        $this->authRepository->updateAdminLastLogin((int) $user['id']);
        $this->users->updateLastLogin((int) $user['id']);
        $this->rateLimiter->clear($rateLimitKey);

        $this->auditLog->log([
            'actor_id' => $user['id'],
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.admin_login',
            'target_type' => 'user',
            'target_id' => $user['id'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $identity = $this->sanitizeIdentity($user);
        $this->sessions->putIdentity($identity, 'admin');
        $passwordProof = $this->passwordProof((int) $user['id'], $password);
        if ($passwordProof !== '') {
            $this->sessions->storeAdminPasswordProof([
                'user_id' => (int) $user['id'],
                'verified_at' => time(),
                'proof' => $passwordProof,
                'source' => $verification['source'] ?? 'panel',
            ]);
        } else {
            $this->sessions->clearAdminPasswordProof();
        }

        return $identity;
    }

    public function loginMailbox(string $email, string $password, ?string $ipAddress = null, ?string $userAgent = null): array
    {
        $email = strtolower(trim($email));
        $ipAddress ??= '127.0.0.1';
        $rateLimit = $this->appConfig['rate_limits']['mailbox_login'] ?? ['max_attempts' => 5, 'window_seconds' => 900];
        $rateLimitKey = sprintf('mailbox-login:%s:%s', $email, $ipAddress);
        $this->rateLimiter->assertWithinLimit($rateLimitKey, (int) $rateLimit['max_attempts'], (int) $rateLimit['window_seconds']);

        $mailbox = $this->authRepository->findMailboxByEmail($email);

        if ($mailbox === null || !$this->passwordHasher->verify($password, (string) $mailbox['password_hash'])) {
            $this->recordFailure($rateLimitKey, $rateLimit, 'auth.mailbox_login.failed', $mailbox, $ipAddress, $userAgent, 'mailbox');
            throw new InvalidArgumentException('Invalid credentials.');
        }

        $this->authRepository->updateMailboxLastLogin((int) $mailbox['id']);
        $this->rateLimiter->clear($rateLimitKey);

        $this->auditLog->log([
            'actor_id' => $mailbox['id'],
            'actor_role' => 'mailbox_user',
            'tenant_id' => $mailbox['tenant_id'],
            'action' => 'auth.mailbox_login',
            'target_type' => 'mailbox',
            'target_id' => $mailbox['id'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        $identity = $this->sanitizeIdentity($mailbox);
        $this->sessions->putIdentity($identity, 'mailbox');

        return $identity;
    }

    public function changeMailboxPasswordByCredentials(
        string $email,
        string $currentPassword,
        string $newPassword,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $email = strtolower(trim($email));
        $currentPassword = (string) $currentPassword;
        $newPassword = (string) $newPassword;
        $ipAddress ??= '127.0.0.1';

        if ($email === '') {
            throw new InvalidArgumentException('Email is required.');
        }

        if ($currentPassword === '') {
            throw new InvalidArgumentException('Current password is required.');
        }

        if ($newPassword === '') {
            throw new InvalidArgumentException('New password is required.');
        }

        $rateLimit = $this->appConfig['rate_limits']['mailbox_password_change'] ?? ['max_attempts' => 5, 'window_seconds' => 900];
        $rateLimitKey = sprintf('mailbox-password-change:%s:%s', $email, $ipAddress);
        $this->rateLimiter->assertWithinLimit($rateLimitKey, (int) $rateLimit['max_attempts'], (int) $rateLimit['window_seconds']);

        $mailbox = $this->authRepository->findMailboxByEmail($email);

        if ($mailbox === null || !$this->passwordHasher->verify($currentPassword, (string) $mailbox['password_hash'])) {
            $this->recordFailure($rateLimitKey, $rateLimit, 'auth.mailbox_password_change.failed', $mailbox, $ipAddress, $userAgent, 'mailbox');
            throw new InvalidArgumentException('Invalid credentials.');
        }

        if ($this->passwordHasher->verify($newPassword, (string) $mailbox['password_hash'])) {
            throw new InvalidArgumentException('New password must be different from the current password.');
        }

        $this->mailboxPasswords->changePassword((int) $mailbox['id'], $newPassword);
        $this->rateLimiter->clear($rateLimitKey);

        $this->auditLog->log([
            'actor_id' => $mailbox['id'],
            'actor_role' => 'mailbox_user',
            'tenant_id' => $mailbox['tenant_id'],
            'action' => 'auth.mailbox_password_changed',
            'target_type' => 'mailbox',
            'target_id' => $mailbox['id'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'new_values' => [
                'mode' => 'credential_bridge',
                'email' => $email,
            ],
        ]);

        return [
            'changed' => true,
            'email' => $email,
        ];
    }

    private function sanitizeIdentity(array $identity): array
    {
        unset($identity['password_hash'], $identity['totp_secret'], $identity['totp_pending_secret']);

        return $identity;
    }

    private function recordFailure(
        string $bucket,
        array $rateLimit,
        string $action,
        ?array $subject,
        ?string $ipAddress,
        ?string $userAgent,
        string $targetType
    ): void {
        try {
            $this->rateLimiter->hit($bucket, (int) $rateLimit['max_attempts'], (int) $rateLimit['window_seconds']);
        } catch (\Throwable) {
        }

        $this->auditLog->log([
            'actor_id' => $subject['id'] ?? null,
            'actor_role' => $subject['role'] ?? ($targetType === 'mailbox' ? 'mailbox_user' : 'anonymous'),
            'tenant_id' => $subject['tenant_id'] ?? null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $subject['id'] ?? null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }

    private function isSuperAdminIpAllowlistEnabled(): bool
    {
        return (bool) ($this->appConfig['super_admin_ip_allowlist_enabled'] ?? false);
    }

    private function isAllowedSuperAdminIp(string $ipAddress): bool
    {
        try {
            return IpAllowlist::contains($ipAddress, $this->appConfig['super_admin_ip_allowlist'] ?? []);
        } catch (\Throwable) {
            return false;
        }
    }

    private function syncPanelPasswordFromSuccessfulLinuxLogin(
        array $user,
        string $password,
        ?string $ipAddress,
        ?string $userAgent
    ): array {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return $user;
        }

        $hash = $this->passwordHasher->hash($password);
        $this->users->syncPasswordHash($userId, $hash);
        $this->passwordHistory->store($userId, isset($user['tenant_id']) ? (int) $user['tenant_id'] : null, $hash);

        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.admin_login.password_resynced',
            'target_type' => 'user',
            'target_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'new_values' => [
                'auth_mode' => 'hybrid',
                'linux_username' => $user['linux_username'] ?? null,
                'password_store' => 'panel_db',
            ],
        ]);

        return $this->users->find($userId) ?? ($user + ['password_hash' => $hash]);
    }

    private function passwordProof(int $userId, string $password): string
    {
        $key = $this->recentPasswordProofKey();
        if ($key === '') {
            return '';
        }

        return hash_hmac('sha256', $userId . "\n" . $password, $key);
    }

    private function recentPasswordProofKey(): string
    {
        $key = trim((string) ($this->appConfig['key'] ?? ''));

        return strlen($key) >= 32 ? $key : '';
    }

}
