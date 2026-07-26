<?php

declare(strict_types=1);

namespace MailPanel\Core;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $server,
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly string $rawBody = '',
    ) {
    }

    public static function capture(): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $input = file_get_contents('php://input');
        $decoded = json_decode($input, true);
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true)) {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            is_array($decoded) ? $decoded : $_POST,
            $_SERVER,
            $headers,
            $_COOKIE,
            $input ?: '',
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $lookup = strtoupper(str_replace('-', '_', $name));

        if (isset($this->server['HTTP_' . $lookup])) {
            return (string) $this->server['HTTP_' . $lookup];
        }

        if (isset($this->server[$lookup])) {
            return (string) $this->server[$lookup];
        }

        $normalizedName = strtoupper(str_replace('-', '_', $name));
        foreach ($this->headers as $headerName => $value) {
            if (strtoupper(str_replace('-', '_', (string) $headerName)) === $normalizedName) {
                return is_scalar($value) ? (string) $value : $default;
            }
        }

        return $default;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '127.0.0.1');
    }

    public function userAgent(): string
    {
        return (string) ($this->server['HTTP_USER_AGENT'] ?? '');
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api');
    }
}
