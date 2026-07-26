<?php

declare(strict_types=1);

namespace MailPanel\Support;

use InvalidArgumentException;

final class SafePath
{
    public static function absolute(string $path, string $label): string
    {
        $path = trim(str_replace('\\', '/', $path));

        if ($path === '') {
            throw new InvalidArgumentException("Invalid {$label}: path is empty.");
        }

        $isUnixAbsolute = str_starts_with($path, '/');
        $isWindowsAbsolute = preg_match('/\A[A-Za-z]:\//', $path) === 1;

        if (!$isUnixAbsolute && !$isWindowsAbsolute) {
            throw new InvalidArgumentException("Invalid {$label}: path must be absolute.");
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $path)) {
            throw new InvalidArgumentException("Invalid {$label}: path contains control characters.");
        }

        if ($path === '/' || preg_match('/\A[A-Za-z]:\/\z/', $path) === 1) {
            throw new InvalidArgumentException("Invalid {$label}: path cannot be the filesystem root.");
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));
        if (in_array('..', $segments, true)) {
            throw new InvalidArgumentException("Invalid {$label}: path traversal is not allowed.");
        }

        return rtrim($path, '/');
    }
}
