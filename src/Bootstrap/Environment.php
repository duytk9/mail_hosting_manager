<?php

declare(strict_types=1);

namespace MailPanel\Bootstrap;

final class Environment
{
    public static function load(string $basePath): void
    {
        $file = $basePath . '/.env';

        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if (!self::isValidKey($key)) {
                continue;
            }

            if (self::hasExistingValue($key)) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    private static function hasExistingValue(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        return array_key_exists($key, $_ENV)
            || array_key_exists($key, $_SERVER)
            || getenv($key) !== false;
    }

    private static function isValidKey(string $key): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $key) === 1;
    }
}
