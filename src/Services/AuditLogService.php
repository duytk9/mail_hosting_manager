<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Core\Database;

class AuditLogService
{
    private const SECRET_KEY_PATTERN = '/(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password|authorization|otp|login[_-]?key)/i';

    private const SECRET_KEYS = [
        'password',
        'generated_password',
        'temporary_password',
        'admin_password',
        'mailbox_password',
        'current_password',
        'new_password',
        'old_password',
        'password_hash',
        'linux_password_hash',
        'token',
        'plain_text_token',
        'login_key',
        'plain_text_login_key',
        'secret',
        'otp',
        'one_time_password',
        'totp_secret',
        'totp_pending_secret',
        'private_key',
        'ssh_public_key',
        'dkim_private_key',
        'db_password',
        'api_key',
        'api_secret',
        'authorization',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function log(array $entry): void
    {
        $entry = $this->sanitize($entry);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO audit_logs (actor_id, actor_role, tenant_id, action, target_type, target_id, old_values, new_values, ip_address, user_agent, created_at, updated_at) VALUES (:actor_id, :actor_role, :tenant_id, :action, :target_type, :target_id, :old_values, :new_values, :ip_address, :user_agent, NOW(), NOW())'
        );

        $statement->execute([
            'actor_id' => $entry['actor_id'] ?? null,
            'actor_role' => $entry['actor_role'] ?? 'system',
            'tenant_id' => $entry['tenant_id'] ?? null,
            'action' => $entry['action'],
            'target_type' => $entry['target_type'],
            'target_id' => $entry['target_id'] ?? null,
            'old_values' => isset($entry['old_values']) ? json_encode($entry['old_values'], JSON_UNESCAPED_SLASHES) : null,
            'new_values' => isset($entry['new_values']) ? json_encode($entry['new_values'], JSON_UNESCAPED_SLASHES) : null,
            'ip_address' => $entry['ip_address'] ?? null,
            'user_agent' => $entry['user_agent'] ?? null,
        ]);
    }

    public function sanitize(array $entry): array
    {
        if (isset($entry['old_values'])) {
            $entry['old_values'] = $this->sanitizeValue($entry['old_values']);
        }

        if (isset($entry['new_values'])) {
            $entry['new_values'] = $this->sanitizeValue($entry['new_values']);
        }

        if (isset($entry['user_agent'])) {
            $entry['user_agent'] = substr($this->redactSensitiveString((string) $entry['user_agent']), 0, 255);
        }

        return $entry;
    }

    private function sanitizeValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSecretKey($key)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizeValue($childValue, is_string($childKey) ? $childKey : null);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            $value = $this->redactSensitiveString($value);

            if (strlen($value) > 4000) {
                return substr($value, 0, 4000);
            }
        }

        return $value;
    }

    private function isSecretKey(string $key): bool
    {
        return in_array(strtolower($key), self::SECRET_KEYS, true)
            || preg_match(self::SECRET_KEY_PATTERN, $key) === 1;
    }

    private function redactSensitiveString(string $value): string
    {
        $patterns = [
            '/(Authorization:\s*(?:Bearer|Basic)\s+)[A-Za-z0-9+\/._~=-]+/i',
            '/((?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password|otp|login[_-]?key)\s*[=:]\s*)([^\s,"\']+)/i',
            '/("(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password|authorization|otp|login[_-]?key)"\s*:\s*")([^"]+)(")/i',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        ];

        $redacted = preg_replace($patterns[0], '$1[REDACTED]', $value);
        $redacted = is_string($redacted) ? $redacted : $value;
        $redacted = preg_replace($patterns[1], '$1[REDACTED]', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
        $redacted = preg_replace($patterns[2], '$1[REDACTED]$3', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
        $redacted = preg_replace($patterns[3], '[REDACTED_PRIVATE_KEY]', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
        $redacted = preg_replace('/[\x00-\x1F\x7F]/', '', $redacted);

        return is_string($redacted) ? $redacted : '[REDACTED]';
    }
}
