<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use RuntimeException;

final class AcmeTlsService
{
    private const CERT_WARN_DAYS = 30;

    private const PROFILES = [
        'mail_only' => [
            'label' => 'Mail only',
            'description' => 'Cấp chứng chỉ cho mail.<domain> để IMAP/POP3/SMTP dùng ngay.',
            'include_apex' => false,
            'mail_patterns' => ['mail.%s'],
            'web_patterns' => [],
            'recommended' => true,
        ],
        'mail_and_web' => [
            'label' => 'Mail + Webmail',
            'description' => 'Cấp cho mail, webmail, autodiscover và autoconfig.',
            'include_apex' => false,
            'mail_patterns' => ['mail.%s'],
            'web_patterns' => ['webmail.%s', 'autodiscover.%s', 'autoconfig.%s'],
            'recommended' => false,
        ],
        'portal_only' => [
            'label' => 'Portal Only',
            'description' => 'Cấp chứng chỉ cho hệ thống Portal.',
            'include_apex' => true,
            'mail_patterns' => [],
            'web_patterns' => [],
            'recommended' => false,
        ],
    ];

    /** @var callable */
    private $executor;

    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly TlsCertificateInventory $tlsInventory,
        private readonly string $appRoot = '/opt/mailpanel',
        ?callable $executor = null,
        ?callable $resolver = null,
    ) {
        $this->executor = $executor ?? static function (array $payload): array {
            throw new RuntimeException('ACME executor is not configured.');
        };
        $this->resolver = $resolver ?? static function (string $hostname, string $type): array {
            $dnsType = match ($type) {
                'A' => DNS_A,
                'AAAA' => defined('DNS_AAAA') ? DNS_AAAA : 0,
                default => 0,
            };

            if ($dnsType === 0) {
                return [];
            }

            $records = @dns_get_record($hostname, $dnsType);

            return is_array($records) ? $records : [];
        };
    }

    public function inspectDomain(array $domain): array
    {
        $domainName = strtolower(trim((string) ($domain['domain'] ?? '')));
        if ($domainName === '') {
            throw new InvalidArgumentException('Domain not found.');
        }

        $profiles = [];

        foreach (self::PROFILES as $key => $profile) {
            $hostRows = [];
            $allReady = true;
            $allCovered = true;

            foreach ($this->expandHosts($domainName, $profile) as $hostname) {
                $addresses = $this->resolveHost($hostname);
                $dnsReady = $addresses !== [];
                $certificate = $this->tlsInventory->describe($hostname, self::CERT_WARN_DAYS);
                $hostRows[] = [
                    'hostname' => $hostname,
                    'dns_status' => $dnsReady ? 'ok' : 'failed',
                    'dns_observed' => $addresses === [] ? 'No matching record found.' : implode("\n", $addresses),
                    'certificate_status' => (string) ($certificate['status'] ?? 'missing'),
                    'certificate_label' => (string) ($certificate['status_label'] ?? 'missing'),
                    'certificate_expires_at' => $certificate['expires_at'] ?? null,
                    'certificate_expires_in_days' => $certificate['expires_in_days'] ?? null,
                    'certificate_observed' => $this->formatCertificateObserved($certificate),
                ];
                $allReady = $allReady && $dnsReady;
                $allCovered = $allCovered && $this->isUsableCertificateStatus((string) ($certificate['status'] ?? 'missing'));
            }

            $profiles[] = [
                'key' => $key,
                'label' => $profile['label'],
                'description' => $profile['description'],
                'recommended' => (bool) ($profile['recommended'] ?? false),
                'dns_ready' => $allReady,
                'certificate_ready' => $allCovered,
                'hosts' => $hostRows,
            ];
        }

        return [
            'domain' => $domainName,
            'profiles' => $profiles,
        ];
    }

    public function summarizeDomainCertificate(array $domain): array
    {
        $domainName = strtolower(trim((string) ($domain['domain'] ?? '')));
        if ($domainName === '') {
            throw new InvalidArgumentException('Domain not found.');
        }

        $mailHost = sprintf('mail.%s', $domainName);
        $certificate = $this->tlsInventory->describe($mailHost, self::CERT_WARN_DAYS);

        return [
            'hostname' => $mailHost,
            'status' => (string) ($certificate['status'] ?? 'missing'),
            'status_label' => (string) ($certificate['status_label'] ?? 'missing'),
            'expires_at' => $certificate['expires_at'] ?? null,
            'expires_in_days' => $certificate['expires_in_days'] ?? null,
            'has_certificate' => (bool) ($certificate['has_certificate'] ?? false),
        ];
    }

    public function issue(array $domain, array $input, array $actor = []): array
    {
        return $this->runProvision($domain, $input, $actor, false);
    }

    public function renew(array $domain, array $input, array $actor = []): array
    {
        return $this->runProvision($domain, $input, $actor, true);
    }

    public function issuePortalDomain(string $portalDomain, string $email, array $actor = []): array
    {
        return $this->runProvision($portalDomain, ['email' => $email, 'profile' => 'portal_only'], $actor, false);
    }

    public function profileOptions(): array
    {
        return self::PROFILES;
    }

    private function runProvision(array|string $domain, array $input, array $actor, bool $forceRenewal): array
    {
        if (is_string($domain)) {
            $domainId = 0;
            $domainName = strtolower(trim($domain));
            $tenantId = null;
        } else {
            $domainId = (int) ($domain['id'] ?? 0);
            $domainName = strtolower(trim((string) ($domain['domain'] ?? '')));
            $tenantId = isset($domain['tenant_id']) ? (int) $domain['tenant_id'] : null;
        }
        
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $profileKey = (string) ($input['profile'] ?? 'mail_only');

        if ($domainName === '') {
            throw new InvalidArgumentException('Domain not found.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email ACME không hợp lệ.');
        }

        if (!isset(self::PROFILES[$profileKey])) {
            throw new InvalidArgumentException('Scope SSL không hợp lệ.');
        }

        $inspection = $this->inspectDomain(is_string($domain) ? ['domain' => $domainName] : $domain);
        $profile = $this->findProfile($inspection['profiles'], $profileKey);
        $missingHosts = array_values(array_map(
            static fn (array $host): string => $host['hostname'],
            array_filter(
                $profile['hosts'],
                static fn (array $host): bool => ($host['dns_status'] ?? 'failed') !== 'ok'
            )
        ));

        if ($missingHosts !== []) {
            throw new InvalidArgumentException('DNS chưa sẵn sàng cho SSL: ' . implode(', ', $missingHosts));
        }

        $payload = [
            'action' => $forceRenewal ? 'renew-domain' : 'provision-domain',
            'app_root' => $this->appRoot,
            'domain' => $domainName,
            'email' => $email,
            'profile' => $profileKey,
            'timeout' => 600,
        ];

        try {
            $result = ($this->executor)($payload);
            $this->auditLog->log([
                'actor_id' => $actor['actor_id'] ?? null,
                'actor_role' => $actor['actor_role'] ?? 'admin',
                'tenant_id' => $tenantId,
                'action' => $forceRenewal ? 'tls.acme_renewed' : 'tls.acme_issued',
                'target_type' => 'domain',
                'target_id' => $domainId > 0 ? $domainId : null,
                'new_values' => [
                    'domain' => $domainName,
                    'email' => $email,
                    'profile' => $profileKey,
                    'hosts' => array_map(static fn (array $host): string => $host['hostname'], $profile['hosts']),
                ],
                'ip_address' => $actor['ip_address'] ?? null,
                'user_agent' => $actor['user_agent'] ?? null,
            ]);

            return [
                'action' => $forceRenewal ? 'renew' : 'issue',
                'profile' => $profileKey,
                'profile_label' => (string) $profile['label'],
                'hosts' => array_map(static fn (array $host): string => $host['hostname'], $profile['hosts']),
                'result' => $result,
            ];
        } catch (\Throwable $exception) {
            $this->auditLog->log([
                'actor_id' => $actor['actor_id'] ?? null,
                'actor_role' => $actor['actor_role'] ?? 'admin',
                'tenant_id' => $tenantId,
                'action' => $forceRenewal ? 'tls.acme_renew_failed' : 'tls.acme_issue_failed',
                'target_type' => 'domain',
                'target_id' => $domainId > 0 ? $domainId : null,
                'new_values' => [
                    'domain' => $domainName,
                    'email' => $email,
                    'profile' => $profileKey,
                    'error' => 'ACME operation failed; check service logs.',
                ],
                'ip_address' => $actor['ip_address'] ?? null,
                'user_agent' => $actor['user_agent'] ?? null,
            ]);

            throw $exception;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $profiles
     * @return array<string, mixed>
     */
    private function findProfile(array $profiles, string $profileKey): array
    {
        foreach ($profiles as $profile) {
            if (($profile['key'] ?? null) === $profileKey) {
                return $profile;
            }
        }

        throw new InvalidArgumentException('Scope SSL không hợp lệ.');
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<int, string>
     */
    private function expandHosts(string $domainName, array $profile): array
    {
        $hosts = [];

        if (!empty($profile['include_apex'])) {
            $hosts[] = $domainName;
        }

        foreach (array_merge($profile['mail_patterns'] ?? [], $profile['web_patterns'] ?? []) as $pattern) {
            $hosts[] = sprintf((string) $pattern, $domainName);
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * @return array<int, string>
     */
    private function resolveHost(string $hostname): array
    {
        $records = array_merge(
            $this->resolve($hostname, 'A'),
            $this->resolve($hostname, 'AAAA')
        );
        $addresses = [];

        foreach ($records as $record) {
            $address = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        $addresses = array_values(array_unique($addresses));
        sort($addresses);

        return $addresses;
    }

    private function resolve(string $hostname, string $type): array
    {
        return array_values(array_filter(
            (($this->resolver)($hostname, $type) ?: []),
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    private function formatCertificateObserved(array $certificate): string
    {
        if (!($certificate['has_certificate'] ?? false)) {
            return 'No certificate files found.';
        }

        $expiresAt = $certificate['expires_at'] ?? null;
        $expiresInDays = $certificate['expires_in_days'] ?? null;
        if (is_int($expiresAt) && is_int($expiresInDays)) {
            return sprintf(
                'Expires at %s UTC (%d days).',
                gmdate('Y-m-d H:i', $expiresAt),
                $expiresInDays
            );
        }

        return 'Certificate files detected.';
    }

    private function isUsableCertificateStatus(string $status): bool
    {
        return in_array($status, ['active', 'expiring_soon', 'present'], true);
    }
}
