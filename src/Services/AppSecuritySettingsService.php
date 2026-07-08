<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Security\IpAllowlist;
use MailPanel\Support\AppSecuritySettingsStore;

final class AppSecuritySettingsService
{
    public function __construct(
        private readonly string $appRoot,
        private readonly array $appConfig,
        private readonly AuditLogService $auditLog
    ) {
    }

    public function portalDomainConfig(): array
    {
        $existing = AppSecuritySettingsStore::load($this->appRoot);
        return [
            'portal_domain' => (string) ($existing['portal_domain'] ?? ''),
        ];
    }

    public function portalSettingsConfig(): array
    {
        $existing = AppSecuritySettingsStore::load($this->appRoot);
        $passwordReset = is_array($existing['password_reset'] ?? null) ? $existing['password_reset'] : [];
        $mail = is_array($passwordReset['mail'] ?? null) ? $passwordReset['mail'] : [];
        $configuredMail = is_array($this->appConfig['password_reset']['mail'] ?? null)
            ? $this->appConfig['password_reset']['mail']
            : [];

        return [
            'portal_domain' => (string) ($existing['portal_domain'] ?? ''),
            'base_url' => (string) ($this->appConfig['base_url'] ?? ''),
            'password_reset' => [
                'ttl_seconds' => max(300, min(3600, (int) ($passwordReset['ttl_seconds'] ?? ($this->appConfig['password_reset']['ttl_seconds'] ?? 3600)))),
                'mail' => [
                    'from_email' => (string) ($mail['from_email'] ?? ($configuredMail['from_email'] ?? '')),
                    'from_name' => (string) ($mail['from_name'] ?? ($configuredMail['from_name'] ?? 'MailPanel')),
                    'subject' => (string) ($mail['subject'] ?? ($configuredMail['subject'] ?? 'Đặt lại mật khẩu MailPanel')),
                    'transport' => (string) ($mail['transport'] ?? ($configuredMail['transport'] ?? 'mail')),
                    'smtp' => $this->smtpConfigForDisplay(is_array($mail['smtp'] ?? null) ? $mail['smtp'] : ($configuredMail['smtp'] ?? [])),
                ],
            ],
        ];
    }

    public function updatePortalDomain(
        string $portalDomain,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $portalDomain = strtolower(trim($portalDomain));
        if ($portalDomain !== '' && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/', $portalDomain) !== 1) {
            throw new InvalidArgumentException('Portal domain is invalid.');
        }

        $previous = $this->portalDomainConfig();
        $updated = [
            'portal_domain' => $portalDomain,
        ];

        $settings = AppSecuritySettingsStore::load($this->appRoot);
        $settings['portal_domain'] = $portalDomain;
        AppSecuritySettingsStore::save($this->appRoot, $settings);

        $this->auditLog->log([
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? 'super_admin',
            'action' => 'security.portal_domain_updated',
            'target_type' => 'system_settings',
            'old_values' => [
                'portal_domain' => $previous['portal_domain'],
            ],
            'new_values' => [
                'portal_domain' => $updated['portal_domain'],
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $updated;
    }

    public function updatePortalSettings(
        string $portalDomain,
        string $passwordResetFromEmail,
        string $passwordResetFromName,
        string $passwordResetSubject,
        int $passwordResetTtlSeconds,
        string $passwordResetTransport = 'mail',
        string $smtpHost = '',
        int $smtpPort = 587,
        string $smtpEncryption = 'starttls',
        string $smtpUsername = '',
        string $smtpPassword = '',
        bool $clearSmtpPassword = false,
        int $smtpTimeoutSeconds = 15,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $portalDomain = $this->normalizePortalDomain($portalDomain);
        if ($portalDomain === '') {
            throw new InvalidArgumentException('Vui lòng nhập Portal Domain.');
        }

        $fromEmail = strtolower(trim($passwordResetFromEmail));
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email gửi link reset không hợp lệ.');
        }

        $fromName = $this->safeHeaderText($passwordResetFromName, 'MailPanel');
        $subject = $this->safeHeaderText($passwordResetSubject, 'Đặt lại mật khẩu MailPanel');
        $ttlSeconds = max(300, min(3600, $passwordResetTtlSeconds));
        $transport = $passwordResetTransport === 'smtp' ? 'smtp' : 'mail';
        $settings = AppSecuritySettingsStore::load($this->appRoot);
        $existingPasswordReset = is_array($settings['password_reset'] ?? null) ? $settings['password_reset'] : [];
        $existingMail = is_array($existingPasswordReset['mail'] ?? null) ? $existingPasswordReset['mail'] : [];
        $existingSmtp = is_array($existingMail['smtp'] ?? null) ? $existingMail['smtp'] : [];
        $smtp = $this->normalizeSmtpConfig(
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUsername,
            $smtpPassword,
            $clearSmtpPassword,
            $smtpTimeoutSeconds,
            $existingSmtp
        );
        if ($transport === 'smtp') {
            if ((string) $smtp['host'] === '') {
                throw new InvalidArgumentException('Vui lòng nhập SMTP host khi chọn SMTP bên ngoài.');
            }
            if ((string) $smtp['username'] !== '' && (string) $smtp['password_encrypted'] === '') {
                throw new InvalidArgumentException('Vui lòng nhập SMTP password hoặc bỏ trống SMTP username.');
            }
        }

        $previous = $this->portalSettingsConfig();
        $updated = [
            'portal_domain' => $portalDomain,
            'password_reset' => [
                'ttl_seconds' => $ttlSeconds,
                'mail' => [
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'subject' => $subject,
                    'transport' => $transport,
                    'smtp' => $smtp,
                ],
            ],
        ];

        $settings['portal_domain'] = $portalDomain;
        $settings['password_reset'] = $updated['password_reset'];
        AppSecuritySettingsStore::save($this->appRoot, $settings);

        $auditPrevious = $this->redactPortalSettingsForAudit($previous);
        $auditUpdated = $this->redactPortalSettingsForAudit($updated + ['base_url' => 'https://' . $portalDomain]);

        $this->auditLog->log([
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? 'super_admin',
            'action' => 'system.portal_settings_updated',
            'target_type' => 'system_settings',
            'old_values' => $auditPrevious,
            'new_values' => $auditUpdated,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $updated + ['base_url' => 'https://' . $portalDomain];
    }

    public function superAdminIpAllowlistConfig(): array
    {
        $entries = array_values(array_filter(array_map(
            static fn (string $entry): string => trim($entry),
            $this->appConfig['super_admin_ip_allowlist'] ?? []
        )));

        return [
            'enabled' => (bool) ($this->appConfig['super_admin_ip_allowlist_enabled'] ?? false),
            'entries' => $entries,
            'raw' => implode(PHP_EOL, $entries),
        ];
    }

    public function updateSuperAdminIpAllowlist(
        bool $enabled,
        string $rawAllowlist,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $entries = IpAllowlist::normalizeEntries($rawAllowlist);

        if ($enabled && $entries === []) {
            throw new InvalidArgumentException('Super admin IP allowlist must contain at least one IP or CIDR when enabled.');
        }

        $previous = $this->superAdminIpAllowlistConfig();
        $updated = [
            'enabled' => $enabled,
            'entries' => $entries,
            'raw' => implode(PHP_EOL, $entries),
        ];

        AppSecuritySettingsStore::save($this->appRoot, [
            'super_admin_ip_allowlist_enabled' => $enabled,
            'super_admin_ip_allowlist' => $entries,
        ]);

        $this->auditLog->log([
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? 'super_admin',
            'action' => 'security.super_admin_ip_allowlist_updated',
            'target_type' => 'system_settings',
            'old_values' => [
                'enabled' => $previous['enabled'],
                'entries' => $previous['entries'],
            ],
            'new_values' => [
                'enabled' => $updated['enabled'],
                'entries' => $updated['entries'],
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $updated;
    }

    private function normalizePortalDomain(string $portalDomain): string
    {
        $portalDomain = strtolower(trim($portalDomain));
        if ($portalDomain !== '' && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/', $portalDomain) !== 1) {
            throw new InvalidArgumentException('Portal domain is invalid.');
        }

        return $portalDomain;
    }

    private function safeHeaderText(string $value, string $fallback): string
    {
        $value = trim(preg_replace('/[\r\n\x00-\x1F\x7F]+/', ' ', $value) ?? '');
        if ($value === '') {
            return $fallback;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, 120) : substr($value, 0, 120);
    }

    /**
     * @param array<string, mixed> $existingSmtp
     * @return array<string, mixed>
     */
    private function normalizeSmtpConfig(
        string $host,
        int $port,
        string $encryption,
        string $username,
        string $password,
        bool $clearPassword,
        int $timeoutSeconds,
        array $existingSmtp
    ): array {
        $host = strtolower(trim($host));
        if ($host !== '' && preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $host) !== 1) {
            throw new InvalidArgumentException('SMTP host không hợp lệ.');
        }

        $encryption = strtolower(trim($encryption));
        if (!in_array($encryption, ['none', 'ssl', 'tls', 'starttls'], true)) {
            throw new InvalidArgumentException('Kiểu mã hóa SMTP không hợp lệ.');
        }

        $username = $this->safeHeaderText(trim($username), '');
        if ($username !== '' && $encryption === 'none') {
            throw new InvalidArgumentException('SMTP AUTH không được phép khi chưa bật mã hóa TLS/SSL.');
        }

        $passwordEncrypted = $clearPassword ? '' : trim((string) ($existingSmtp['password_encrypted'] ?? ''));
        if ($password !== '') {
            $passwordEncrypted = $this->encryptSecret($password);
        }
        if ($username === '') {
            $passwordEncrypted = '';
        }

        return [
            'host' => $host,
            'port' => max(1, min(65535, $port)),
            'encryption' => $encryption,
            'username' => $username,
            'password_encrypted' => $passwordEncrypted,
            'timeout_seconds' => max(3, min(30, $timeoutSeconds)),
        ];
    }

    /**
     * @param array<string, mixed> $smtp
     * @return array<string, mixed>
     */
    private function smtpConfigForDisplay(array $smtp): array
    {
        return [
            'host' => (string) ($smtp['host'] ?? ''),
            'port' => (int) ($smtp['port'] ?? 587),
            'encryption' => (string) ($smtp['encryption'] ?? 'starttls'),
            'username' => (string) ($smtp['username'] ?? ''),
            'password_configured' => trim((string) ($smtp['password_encrypted'] ?? '')) !== '',
            'timeout_seconds' => (int) ($smtp['timeout_seconds'] ?? 15),
        ];
    }

    private function encryptSecret(string $secret): string
    {
        $appKey = trim((string) ($this->appConfig['key'] ?? ''));
        if (strlen($appKey) < 32) {
            throw new InvalidArgumentException('APP_KEY phải có tối thiểu 32 ký tự để lưu mật khẩu SMTP.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            hash('sha256', $appKey, true),
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );
        if (!is_string($ciphertext)) {
            throw new InvalidArgumentException('Không thể mã hóa mật khẩu SMTP.');
        }

        return base64_encode((string) json_encode([
            'alg' => 'aes-256-gcm',
            'nonce' => base64_encode($nonce),
            'tag' => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function redactPortalSettingsForAudit(array $settings): array
    {
        $redacted = $settings;
        if (isset($redacted['password_reset']['mail']['smtp']) && is_array($redacted['password_reset']['mail']['smtp'])) {
            $smtp = $redacted['password_reset']['mail']['smtp'];
            $smtp['password_configured'] = !empty($smtp['password_configured'])
                || trim((string) ($smtp['password_encrypted'] ?? '')) !== '';
            unset($smtp['password_encrypted']);
            $redacted['password_reset']['mail']['smtp'] = $smtp;
        }

        return $redacted;
    }
}
