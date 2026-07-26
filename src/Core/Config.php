<?php

declare(strict_types=1);

namespace MailPanel\Core;

final class Config
{
    public function __construct(
        private readonly string $basePath,
        private readonly array $items
    ) {
    }

    public function basePath(string $suffix = ''): string
    {
        return $suffix === '' ? $this->basePath : $this->basePath . '/' . ltrim($suffix, '/');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
