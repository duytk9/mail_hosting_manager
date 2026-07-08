<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Repositories\Pdo\PasswordResetTokenRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use RuntimeException;
use Throwable;

final class PasswordResetService
{
    public const GENERIC_REQUEST_MESSAGE = 'Nếu tài khoản tồn tại và có email hợp lệ, hệ thống sẽ gửi đường dẫn đặt lại mật khẩu.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordResetTokenRepository $tokens,
        private readonly UserPasswordHistoryRepository $passwordHistory,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly PasswordHashingService $passwordHasher,
        private readonly SuperAdminLinuxAccountManager $linuxAccounts,
        private readonly PasswordResetMailService $mailer,
        private readonly AuditLogService $auditLog,
        private readonly RateLimiterService $rateLimiter,
        private readonly array $config = [],
        private readonly string $appKey = ''
    ) {
    }

    public function requestReset(string $login, string $baseUrl, ?string $ipAddress, ?string $userAgent): string
    {
        $login = $this->normalizeLogin($login);
        $ipAddress ??= '127.0.0.1';
        $rateLimit = $this->config['rate_limit'] ?? ['max_attempts' => 5, 'window_seconds' => 900];
        $bucket = sprintf('admin-password-reset-request:%s:%s', $this->fingerprint($login), $ipAddress);
        $this->rateLimiter->hit($bucket, (int) ($rateLimit['max_attempts'] ?? 5), (int) ($rateLimit['window_seconds'] ?? 900));

        if ($login === '') {
            $this->auditNoopRequest($login, $ipAddress, $userAgent, 'empty_login');
            return self::GENERIC_REQUEST_MESSAGE;
        }

        $user = $this->users->findAdminByLogin($login);
        if ($user === null || !$this->isResettableUser($user)) {
            $this->auditNoopRequest($login, $ipAddress, $userAgent, 'not_found_or_not_resettable');
            return self::GENERIC_REQUEST_MESSAGE;
        }

        $rawToken = $this->newToken();
        $expiresAt = $this->futureTimestamp($this->ttlSeconds());
        $this->tokens->invalidateActiveForUser((int) $user['id']);
        $this->tokens->create(
            (int) $user['id'],
            $this->tokenHash($rawToken),
            $expiresAt,
            $ipAddress,
            $userAgent
        );

        $resetUrl = rtrim($this->safeBaseUrl($baseUrl), '/') . '/admin/reset-password?token=' . rawurlencode($rawToken);

        try {
            $this->mailer->send(
                (string) $user['email'],
                (string) ($user['name'] ?? ''),
                $resetUrl,
                $this->ttlSeconds()
            );
            $this->auditLog->log([
                'actor_id' => null,
                'actor_role' => 'anonymous',
                'tenant_id' => $user['tenant_id'] ?? null,
                'action' => 'auth.password_reset.requested',
                'target_type' => 'user',
                'target_id' => $user['id'] ?? null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'new_values' => [
                    'delivery' => 'email',
                    'ttl_seconds' => $this->ttlSeconds(),
                ],
            ]);
        } catch (Throwable $exception) {
            $this->auditLog->log([
                'actor_id' => null,
                'actor_role' => 'anonymous',
                'tenant_id' => $user['tenant_id'] ?? null,
                'action' => 'auth.password_reset.email_failed',
                'target_type' => 'user',
                'target_id' => $user['id'] ?? null,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'new_values' => [
                    'error_class' => $exception::class,
                ],
            ]);
        }

        return self::GENERIC_REQUEST_MESSAGE;
    }

    public function validToken(string $token): bool
    {
        if (!$this->looksLikeToken($token)) {
            return false;
        }

        return $this->tokens->findUsableByHash($this->tokenHash($token), $this->now()) !== null;
    }

    public function completeReset(string $token, string $newPassword, string $confirmPassword, ?string $ipAddress, ?string $userAgent): void
    {
        $ipAddress ??= '127.0.0.1';
        $rateLimit = $this->config['complete_rate_limit'] ?? ['max_attempts' => 8, 'window_seconds' => 900];
        $bucket = sprintf('admin-password-reset-complete:%s:%s', $this->fingerprint($token), $ipAddress);
        $this->rateLimiter->hit($bucket, (int) ($rateLimit['max_attempts'] ?? 8), (int) ($rateLimit['window_seconds'] ?? 900));

        if (!$this->looksLikeToken($token)) {
            throw new InvalidArgumentException('Đường dẫn đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.');
        }

        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException('Mật khẩu xác nhận không khớp.');
        }

        $tokenRow = $this->tokens->findUsableByHash($this->tokenHash($token), $this->now());
        if ($tokenRow === null || !$this->isResettableUser($tokenRow)) {
            throw new InvalidArgumentException('Đường dẫn đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.');
        }

        if ($this->passwordHasher->verify($newPassword, (string) ($tokenRow['password_hash'] ?? ''))) {
            throw new InvalidArgumentException('Mật khẩu mới phải khác mật khẩu hiện tại.');
        }

        $userId = (int) $tokenRow['user_id'];
        $tenantId = isset($tokenRow['tenant_id']) ? (int) $tokenRow['tenant_id'] : null;
        $this->passwordPolicy->assertStrong($newPassword);
        $this->passwordPolicy->assertNotReused(
            $newPassword,
            $this->passwordHistory->recentHashesForUser($userId, $this->passwordPolicy->historyCount())
        );

        if (!empty($tokenRow['linux_username'])) {
            $this->linuxAccounts->syncAccount(
                (string) $tokenRow['linux_username'],
                !empty($tokenRow['ssh_enabled']),
                !empty($tokenRow['ssh_sudo_enabled']),
                isset($tokenRow['ssh_public_key']) ? (string) $tokenRow['ssh_public_key'] : null,
                $newPassword
            );
        }

        $hash = $this->passwordHasher->hash($newPassword);
        $this->users->updatePassword($userId, $hash);
        $this->users->resetTotpGraceLoginCount($userId);
        $this->tokens->markUsed((int) $tokenRow['id']);
        $this->tokens->invalidateActiveForUser($userId);
        $this->passwordHistory->store($userId, $tenantId, $hash);
        $this->rateLimiter->clear($bucket);

        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => (string) ($tokenRow['role'] ?? 'admin'),
            'tenant_id' => $tenantId,
            'action' => 'auth.password_reset.completed',
            'target_type' => 'user',
            'target_id' => $userId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'new_values' => [
                'linux_username' => $tokenRow['linux_username'] ?? null,
            ],
        ]);
    }

    private function isResettableUser(array $user): bool
    {
        return in_array((string) ($user['role'] ?? ''), ['super_admin', 'tenant_admin', 'domain_admin', 'support_readonly'], true)
            && empty($user['deleted_at'])
            && empty($user['security_locked_at'])
            && filter_var((string) ($user['email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function normalizeLogin(string $login): string
    {
        return mb_strtolower(trim($login), 'UTF-8');
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function looksLikeToken(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', trim($token)) === 1;
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', trim($token), $this->hmacKey());
    }

    private function fingerprint(string $value): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($value), 'UTF-8'), $this->hmacKey());
    }

    private function hmacKey(): string
    {
        $key = trim($this->appKey);
        if (strlen($key) < 32) {
            throw new RuntimeException('APP_KEY must be at least 32 characters for password reset tokens.');
        }

        return $key;
    }

    private function ttlSeconds(): int
    {
        $ttl = (int) ($this->config['ttl_seconds'] ?? 3600);

        return max(300, min(3600, $ttl));
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function futureTimestamp(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }

    private function safeBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            throw new InvalidArgumentException('Invalid password reset base URL.');
        }

        $port = parse_url($baseUrl, PHP_URL_PORT);
        $authority = $host . (is_int($port) ? ':' . $port : '');

        return $scheme . '://' . $authority;
    }

    private function auditNoopRequest(string $login, ?string $ipAddress, ?string $userAgent, string $reason): void
    {
        $this->auditLog->log([
            'actor_id' => null,
            'actor_role' => 'anonymous',
            'action' => 'auth.password_reset.requested_noop',
            'target_type' => 'user',
            'target_id' => null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'new_values' => [
                'reason' => $reason,
                'login_fingerprint' => $this->fingerprint($login),
            ],
        ]);
    }
}
