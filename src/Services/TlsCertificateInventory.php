<?php

declare(strict_types=1);

namespace MailPanel\Services;

final class TlsCertificateInventory
{
    private const DEFAULT_WARN_DAYS = 30;
    private readonly string $sniRoot;

    public function __construct(
        string $sniRoot = '/etc/mailpanel/tls/sni',
    ) {
        $this->sniRoot = $this->safeSniRoot($sniRoot);
    }

    public function entries(): array
    {
        if (!is_dir($this->sniRoot)) {
            return [];
        }

        $items = scandir($this->sniRoot);
        if ($items === false) {
            return [];
        }

        $entries = [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || !$this->isSupportedHostname($item)) {
                continue;
            }

            $certificate = $this->certificatePath($item);
            $privateKey = $this->privateKeyPath($item);

            if (!is_file($certificate) || !is_file($privateKey)) {
                continue;
            }

            $entries[$item] = [
                'certificate' => $certificate,
                'privatekey' => $privateKey,
            ] + $this->parseCertificateMetadata($certificate);
        }

        ksort($entries);

        return $entries;
    }

    public function find(string $hostname): ?array
    {
        $hostname = strtolower($hostname);

        return $this->entries()[$hostname] ?? null;
    }

    public function describe(string $hostname, int $warnDays = self::DEFAULT_WARN_DAYS): array
    {
        $entry = $this->find($hostname);

        if ($entry === null) {
            return [
                'hostname' => strtolower($hostname),
                'has_certificate' => false,
                'status' => 'missing',
                'status_label' => 'missing',
                'certificate' => null,
                'privatekey' => null,
                'not_before_at' => null,
                'expires_at' => null,
                'expires_in_days' => null,
            ];
        }

        $status = 'present';
        $expiresAt = $entry['expires_at'] ?? null;
        $expiresInDays = $entry['expires_in_days'] ?? null;

        if (is_int($expiresAt) && is_int($expiresInDays)) {
            if ($expiresInDays < 0) {
                $status = 'expired';
            } elseif ($expiresInDays <= $warnDays) {
                $status = 'expiring_soon';
            } else {
                $status = 'active';
            }
        }

        return [
            'hostname' => strtolower($hostname),
            'has_certificate' => true,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'certificate' => $entry['certificate'],
            'privatekey' => $entry['privatekey'],
            'not_before_at' => $entry['not_before_at'] ?? null,
            'expires_at' => $expiresAt,
            'expires_in_days' => $expiresInDays,
        ];
    }

    public function certificatePath(string $hostname): string
    {
        return $this->hostRoot($hostname) . '/fullchain.pem';
    }

    public function privateKeyPath(string $hostname): string
    {
        return $this->hostRoot($hostname) . '/privkey.pem';
    }

    private function parseCertificateMetadata(string $certificatePath): array
    {
        if (!function_exists('openssl_x509_parse')) {
            return [
                'not_before_at' => null,
                'expires_at' => null,
                'expires_in_days' => null,
            ];
        }

        $contents = @file_get_contents($certificatePath);
        if (!is_string($contents) || $contents === '') {
            return [
                'not_before_at' => null,
                'expires_at' => null,
                'expires_in_days' => null,
            ];
        }

        $parsed = @openssl_x509_parse($contents);
        if (!is_array($parsed)) {
            return [
                'not_before_at' => null,
                'expires_at' => null,
                'expires_in_days' => null,
            ];
        }

        $notBeforeAt = isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null;
        $expiresAt = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $expiresInDays = $expiresAt !== null ? (int) floor(($expiresAt - time()) / 86400) : null;

        return [
            'not_before_at' => $notBeforeAt,
            'expires_at' => $expiresAt,
            'expires_in_days' => $expiresInDays,
        ];
    }

    private function hostRoot(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        if (!$this->isSupportedHostname($hostname)) {
            throw new \InvalidArgumentException('Invalid TLS hostname.');
        }

        return rtrim($this->sniRoot, '/\\') . '/' . $hostname;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'active',
            'expiring_soon' => 'expiring soon',
            'expired' => 'expired',
            'present' => 'present',
            default => 'missing',
        };
    }

    private function isSupportedHostname(string $hostname): bool
    {
        if (strlen($hostname) > 253 || preg_match('/\A[A-Za-z0-9.-]+\z/', $hostname) !== 1) {
            return false;
        }

        $labels = explode('.', $hostname);
        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\z/', $label) !== 1) {
                return false;
            }
        }

        return count($labels) >= 2;
    }

    private function safeSniRoot(string $path): string
    {
        $path = rtrim(trim($path), '/\\');
        $isUnixAbsolute = str_starts_with($path, '/') && preg_match('/\A[\/A-Za-z0-9._-]+\z/', $path) === 1;
        $isWindowsAbsolute = preg_match('/\A[A-Za-z]:[\/\\\\A-Za-z0-9._~-]+\z/', $path) === 1;

        if ($path === '' || (!$isUnixAbsolute && !$isWindowsAbsolute) || $this->containsPathTraversal(str_replace('\\', '/', $path))) {
            throw new \InvalidArgumentException('Invalid TLS SNI root.');
        }

        return $path;
    }

    private function containsPathTraversal(string $path): bool
    {
        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

        return in_array('..', $segments, true);
    }
}

