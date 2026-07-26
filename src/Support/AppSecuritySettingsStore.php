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

        return $settings;
    }

    public static function save(string $appRoot, array $settings): void
    {
        $path = self::path($appRoot);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the application settings directory.');
        }

        if (is_link($directory)) {
            throw new RuntimeException('Unsafe application settings directory.');
        }

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
}
