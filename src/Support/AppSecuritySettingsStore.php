<?php

declare(strict_types=1);

namespace MailPanel\Support;

use RuntimeException;

final class AppSecuritySettingsStore
{
    private const RELATIVE_PATH = 'storage/app_settings/app_security.json';

    public static function path(string $appRoot): string
    {
        $appRoot = SafePath::absolute($appRoot, 'application root');

        return rtrim($appRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    public static function load(string $appRoot): array
    {
        $path = self::path($appRoot);
        if (!is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        $settings = [];

        if (array_key_exists('super_admin_ip_allowlist_enabled', $decoded)) {
            $settings['super_admin_ip_allowlist_enabled'] = (bool) $decoded['super_admin_ip_allowlist_enabled'];
        }

        if (array_key_exists('super_admin_ip_allowlist', $decoded)) {
            $settings['super_admin_ip_allowlist'] = self::normalizeEntries($decoded['super_admin_ip_allowlist']);
        }

        if (array_key_exists('portal_domain', $decoded)) {
            $settings['portal_domain'] = (string) $decoded['portal_domain'];
        }

        if (array_key_exists('password_reset', $decoded) && is_array($decoded['password_reset'])) {
            $passwordReset = [];
            if (array_key_exists('ttl_seconds', $decoded['password_reset'])) {
                $passwordReset['ttl_seconds'] = max(300, min(3600, (int) $decoded['password_reset']['ttl_seconds']));
            }
            if (array_key_exists('mail', $decoded['password_reset']) && is_array($decoded['password_reset']['mail'])) {
                $passwordReset['mail'] = [
                    'from_email' => (string) ($decoded['password_reset']['mail']['from_email'] ?? ''),
                    'from_name' => (string) ($decoded['password_reset']['mail']['from_name'] ?? ''),
                    'subject' => (string) ($decoded['password_reset']['mail']['subject'] ?? ''),
                ];
                if (array_key_exists('transport', $decoded['password_reset']['mail'])) {
                    $passwordReset['mail']['transport'] = (string) $decoded['password_reset']['mail']['transport'];
                }
                if (array_key_exists('smtp', $decoded['password_reset']['mail']) && is_array($decoded['password_reset']['mail']['smtp'])) {
                    $passwordReset['mail']['smtp'] = self::normalizeSmtp($decoded['password_reset']['mail']['smtp']);
                }
            }
            if ($passwordReset !== []) {
                $settings['password_reset'] = $passwordReset;
            }
        }

        return $settings;
    }

    public static function save(string $appRoot, array $settings): void
    {
        $path = self::path($appRoot);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the application settings directory.');
        }

        self::assertNoSymlinkSegments($appRoot, $directory);
        @chmod($directory, 0750);

        $payload = [
            'super_admin_ip_allowlist_enabled' => (bool) ($settings['super_admin_ip_allowlist_enabled'] ?? false),
            'super_admin_ip_allowlist' => self::normalizeEntries($settings['super_admin_ip_allowlist'] ?? []),
        ];

        if (array_key_exists('portal_domain', $settings)) {
            $payload['portal_domain'] = (string) $settings['portal_domain'];
        } else {
            $existing = self::load($appRoot);
            if (array_key_exists('portal_domain', $existing)) {
                $payload['portal_domain'] = $existing['portal_domain'];
            }
        }

        if (array_key_exists('password_reset', $settings) && is_array($settings['password_reset'])) {
            $payload['password_reset'] = self::normalizePasswordReset($settings['password_reset']);
        } else {
            $existing = self::load($appRoot);
            if (array_key_exists('password_reset', $existing) && is_array($existing['password_reset'])) {
                $payload['password_reset'] = self::normalizePasswordReset($existing['password_reset']);
            }
        }

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode application security settings.');
        }

        $tempPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($tempPath, $encoded . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the temporary application settings file.');
        }

        @chmod($tempPath, 0640);

        if (!rename($tempPath, $path)) {
            @unlink($tempPath);
            throw new RuntimeException('Unable to publish the application settings file.');
        }

        @chmod($path, 0640);
    }

    private static function normalizeEntries(mixed $entries): array
    {
        if (!is_array($entries)) {
            $entries = explode(',', (string) $entries);
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $entries
        )));

        return array_values(array_unique($normalized));
    }

    private static function assertNoSymlinkSegments(string $appRoot, string $path): void
    {
        $root = rtrim(str_replace('\\', '/', SafePath::absolute($appRoot, 'application root')), '/');
        $target = rtrim(str_replace('\\', '/', $path), '/');

        if ($target !== $root && !str_starts_with($target, $root . '/')) {
            throw new RuntimeException('Unsafe application settings directory.');
        }

        $relative = trim(substr($target, strlen($root)), '/');
        if ($relative === '') {
            return;
        }

        $current = $root;
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '..') {
                throw new RuntimeException('Unsafe application settings directory.');
            }

            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new RuntimeException('Unsafe application settings directory.');
            }
        }
    }

    private static function normalizePasswordReset(array $settings): array
    {
        $mail = is_array($settings['mail'] ?? null) ? $settings['mail'] : [];

        return [
            'ttl_seconds' => max(300, min(3600, (int) ($settings['ttl_seconds'] ?? 3600))),
            'mail' => [
                'from_email' => (string) ($mail['from_email'] ?? ''),
                'from_name' => (string) ($mail['from_name'] ?? 'MailPanel'),
                'subject' => (string) ($mail['subject'] ?? 'Đặt lại mật khẩu MailPanel'),
                'transport' => in_array((string) ($mail['transport'] ?? 'mail'), ['mail', 'smtp'], true) ? (string) ($mail['transport'] ?? 'mail') : 'mail',
                'smtp' => self::normalizeSmtp(is_array($mail['smtp'] ?? null) ? $mail['smtp'] : []),
            ],
        ];
    }

    private static function normalizeSmtp(array $smtp): array
    {
        $encryption = strtolower(trim((string) ($smtp['encryption'] ?? 'starttls')));
        if (!in_array($encryption, ['none', 'ssl', 'tls', 'starttls'], true)) {
            $encryption = 'starttls';
        }

        return [
            'host' => strtolower(trim((string) ($smtp['host'] ?? ''))),
            'port' => max(1, min(65535, (int) ($smtp['port'] ?? 587))),
            'encryption' => $encryption,
            'username' => trim((string) ($smtp['username'] ?? '')),
            'password_encrypted' => trim((string) ($smtp['password_encrypted'] ?? '')),
            'timeout_seconds' => max(3, min(30, (int) ($smtp['timeout_seconds'] ?? 15))),
        ];
    }
}
