<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TotpService;

final class AdminSecurityService
{
    private const RECENT_LOGIN_PROOF_TTL = 900;

    public function __construct(
        private readonly UserRepository $users,
        private readonly UserPasswordHistoryRepository $passwordHistory,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly PasswordHashingService $passwordHasher,
        private readonly TotpService $totp,
        private readonly AuditLogService $auditLog,
        private readonly SuperAdminLinuxAccountManager $linuxAccounts,
        private readonly AdminPasswordVerifier $adminPasswordVerifier,
        private readonly SessionManager $sessions,
        private readonly string $recentPasswordProofKey = '',
    ) {
    }

    public function profile(int $userId): array
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new InvalidArgumentException('Admin user not found.');
        }

        unset($user['password_hash'], $user['totp_secret'], $user['totp_pending_secret']);

        return $user;
    }

    public function startTotpEnrollment(int $userId, string $currentPassword, ?string $otp = null): array
    {
        $user = $this->requireUser($userId);
        $this->assertSensitiveActionAllowed($userId, $currentPassword, $otp);

        $secret = $this->totp->generateSecret();
        $this->users->storePendingTotpSecret($userId, $this->totp->protectSecret($secret));

        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.totp.enrollment_started',
            'target_type' => 'user',
            'target_id' => $userId,
        ]);

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->otpauthUri((string) $user['email'], $secret),
        ];
    }

    public function confirmTotpEnrollment(int $userId, string $code, string $currentPassword): void
    {
        $user = $this->requireUser($userId);
        $this->assertSensitiveActionAllowed($userId, $currentPassword);

        $secret = (string) ($user['totp_pending_secret'] ?? '');

        if ($secret === '' || !$this->totp->verify($secret, $code)) {
            throw new InvalidArgumentException('Invalid TOTP code.');
        }

        $this->users->enableTotp($userId, $secret);
        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.totp.enabled',
            'target_type' => 'user',
            'target_id' => $userId,
        ]);
    }

    public function disableTotp(int $userId, string $code, string $currentPassword): void
    {
        $user = $this->requireUser($userId);
        $this->assertSensitiveActionAllowed($userId, $currentPassword, $code);

        $secret = (string) ($user['totp_secret'] ?? '');

        if ($secret === '' || !$this->totp->verify($secret, $code)) {
            throw new InvalidArgumentException('Invalid TOTP code.');
        }

        $this->users->disableTotp($userId);
        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.totp.disabled',
            'target_type' => 'user',
            'target_id' => $userId,
        ]);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->requireUser($userId);

        $verification = $this->verifyCurrentPassword($user, $currentPassword);
        if (!$verification['verified']) {
            $this->auditLog->log([
                'actor_id' => $userId,
                'actor_role' => $user['role'] ?? 'super_admin',
                'tenant_id' => $user['tenant_id'] ?? null,
                'action' => 'auth.admin_password_change_failed',
                'target_type' => 'user',
                'target_id' => $userId,
                'new_values' => [
                    'reason' => 'current_password_invalid',
                ],
            ]);

            throw new InvalidArgumentException('Current password is invalid.');
        }

        $this->passwordPolicy->assertStrong($newPassword);
        $this->passwordPolicy->assertNotReused(
            $newPassword,
            $this->passwordHistory->recentHashesForUser($userId, $this->passwordPolicy->historyCount())
        );

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
        $this->passwordHistory->store($userId, isset($user['tenant_id']) ? (int) $user['tenant_id'] : null, $hash);
        $this->storeRecentSessionPasswordProof($userId, $newPassword, 'password_change');

        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.admin_password_changed',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => [
                'verification_source' => $verification['source'] ?? 'unknown',
            ],
        ]);
    }

    public function assertSensitiveActionAllowed(int $userId, string $currentPassword, ?string $otp = null): void
    {
        $user = $this->requireUser($userId);
        $verification = $this->verifyCurrentPassword($user, $currentPassword);

        if (!$verification['verified']) {
            $this->auditLog->log([
                'actor_id' => $userId,
                'actor_role' => $user['role'] ?? 'super_admin',
                'tenant_id' => $user['tenant_id'] ?? null,
                'action' => 'auth.sensitive_action_denied',
                'target_type' => 'user',
                'target_id' => $userId,
                'new_values' => ['reason' => 'current_password_invalid'],
            ]);

            throw new InvalidArgumentException('Current password is invalid.');
        }

        if (!empty($user['totp_enabled'])) {
            if ($otp === null || trim($otp) === '') {
                throw new InvalidArgumentException('One-time password is required.');
            }

            if (!$this->totp->verify((string) ($user['totp_secret'] ?? ''), $otp)) {
                $this->auditLog->log([
                    'actor_id' => $userId,
                    'actor_role' => $user['role'] ?? 'super_admin',
                    'tenant_id' => $user['tenant_id'] ?? null,
                    'action' => 'auth.sensitive_action_denied',
                    'target_type' => 'user',
                    'target_id' => $userId,
                    'new_values' => ['reason' => 'totp_invalid'],
                ]);

                throw new InvalidArgumentException('Invalid one-time password.');
            }
        }

        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $user['role'] ?? 'super_admin',
            'tenant_id' => $user['tenant_id'] ?? null,
            'action' => 'auth.sensitive_action_verified',
            'target_type' => 'user',
            'target_id' => $userId,
            'new_values' => ['verification_source' => $verification['source'] ?? 'unknown'],
        ]);
    }

    private function requireUser(int $userId): array
    {
        $user = $this->users->find($userId);
        if ($user === null) {
            throw new InvalidArgumentException('Admin user not found.');
        }

        return $user;
    }

    private function verifyCurrentPassword(array $user, string $currentPassword): array
    {
        $verification = $this->adminPasswordVerifier->verify($user, $currentPassword);
        if (!$verification['verified'] && $this->verifyRecentSessionPasswordProof((int) ($user['id'] ?? 0), $currentPassword)) {
            return [
                'verified' => true,
                'source' => 'session_recent_login',
                'migrated' => false,
            ];
        }

        return $verification;
    }

    private function verifyRecentSessionPasswordProof(int $userId, string $password): bool
    {
        $proof = $this->sessions->adminPasswordProof();
        if (!is_array($proof)) {
            return false;
        }

        if ((int) ($proof['user_id'] ?? 0) !== $userId) {
            return false;
        }

        $verifiedAt = (int) ($proof['verified_at'] ?? 0);
        if ($verifiedAt <= 0 || (time() - $verifiedAt) > self::RECENT_LOGIN_PROOF_TTL) {
            $this->sessions->clearAdminPasswordProof();

            return false;
        }

        $storedProof = (string) ($proof['proof'] ?? '');
        if ($storedProof === '' || $this->recentPasswordProofKey() === '') {
            return false;
        }

        return hash_equals($storedProof, $this->passwordProof($userId, $password));
    }

    private function storeRecentSessionPasswordProof(int $userId, string $password, string $source): void
    {
        if ($this->recentPasswordProofKey() === '') {
            $this->sessions->clearAdminPasswordProof();

            return;
        }

        $this->sessions->storeAdminPasswordProof([
            'user_id' => $userId,
            'verified_at' => time(),
            'proof' => $this->passwordProof($userId, $password),
            'source' => $source,
        ]);
    }

    private function passwordProof(int $userId, string $password): string
    {
        return hash_hmac('sha256', $userId . "\n" . $password, $this->recentPasswordProofKey());
    }

    private function recentPasswordProofKey(): string
    {
        $key = trim($this->recentPasswordProofKey);

        return strlen($key) >= 32 ? $key : '';
    }
}
