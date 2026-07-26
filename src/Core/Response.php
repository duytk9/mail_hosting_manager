<?php

declare(strict_types=1);

namespace MailPanel\Core;

final class Response
{
    private const DEFAULT_SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), geolocation=(), microphone=()',
        'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        'Cross-Origin-Resource-Policy' => 'same-origin',
    ];

    private readonly int $status;
    private readonly array|string $body;
    private readonly array $headers;

    public function __construct(int $status, array|string $body, array $headers = ['Content-Type' => 'application/json'])
    {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP status code.');
        }

        $this->status = $status;
        $this->body = $body;
        $this->headers = $this->safeHeaders($headers);
    }

    public static function json(array $payload, int $status = 200): self
    {
        return new self($status, $payload);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; img-src 'self' data: https:; object-src 'none'; script-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src 'self' data: https://fonts.gstatic.com; connect-src 'self' https://fonts.googleapis.com https://fonts.gstatic.com",
        ]);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        if (!in_array($status, [301, 302, 303, 307, 308], true)) {
            throw new \InvalidArgumentException('Invalid redirect status code.');
        }

        return new self($status, '', ['Location' => self::safeRedirectLocation($location)]);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers + self::DEFAULT_SECURITY_HEADERS as $name => $value) {
            header("$name: $value");
        }

        echo is_array($this->body) ? json_encode($this->body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $this->body;
    }

    private static function safeRedirectLocation(string $location): string
    {
        $location = trim($location);

        if (
            $location === ''
            || !str_starts_with($location, '/')
            || str_starts_with($location, '//')
            || str_contains($location, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $location) === 1
        ) {
            throw new \InvalidArgumentException('Invalid redirect location.');
        }

        return $location;
    }

    private function safeHeaders(array $headers): array
    {
        $safe = [];
        foreach ($headers as $name => $value) {
            $name = (string) $name;
            $value = (string) $value;

            if (!preg_match('/\A[A-Za-z0-9!#$%&\'*+.^_`|~-]+\z/', $name)) {
                throw new \InvalidArgumentException('Invalid HTTP header name.');
            }

            if (preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1) {
                throw new \InvalidArgumentException('Invalid HTTP header value.');
            }

            $safe[$name] = $value;
        }

        return $safe;
    }
}
