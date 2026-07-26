<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Security\IpAllowlist;
use MailPanel\Support\AppSecuritySettingsStore;

final class AppSecuritySettingsService
{
    public function __construct(
        private readonly string $appRoot,
        private readonly array $appConfig,
        private readonly AuditLogService $auditLog
    ) {
    }

    public function portalDomainConfig(): array
    {
        $existing = AppSecuritySettingsStore::load($this->appRoot);
        return [
            'portal_domain' => (string) ($existing['portal_domain'] ?? ''),
        ];
    }

    public function updatePortalDomain(
        string $portalDomain,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $portalDomain = strtolower(trim($portalDomain));
        if ($portalDomain !== '' && preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+\z/', $portalDomain) !== 1) {
            throw new InvalidArgumentException('Portal domain is invalid.');
        }

        $previous = $this->portalDomainConfig();
        $updated = [
            'portal_domain' => $portalDomain,
        ];

        $settings = AppSecuritySettingsStore::load($this->appRoot);
        $settings['portal_domain'] = $portalDomain;
        AppSecuritySettingsStore::save($this->appRoot, $settings);

        $this->auditLog->log([
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? 'super_admin',
            'action' => 'security.portal_domain_updated',
            'target_type' => 'system_settings',
            'old_values' => [
                'portal_domain' => $previous['portal_domain'],
            ],
            'new_values' => [
                'portal_domain' => $updated['portal_domain'],
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $updated;
    }

    public function superAdminIpAllowlistConfig(): array
    {
        $entries = array_values(array_filter(array_map(
            static fn (string $entry): string => trim($entry),
            $this->appConfig['super_admin_ip_allowlist'] ?? []
        )));

        return [
            'enabled' => (bool) ($this->appConfig['super_admin_ip_allowlist_enabled'] ?? false),
            'entries' => $entries,
            'raw' => implode(PHP_EOL, $entries),
        ];
    }

    public function updateSuperAdminIpAllowlist(
        bool $enabled,
        string $rawAllowlist,
        ?int $actorId = null,
        ?string $actorRole = null,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $entries = IpAllowlist::normalizeEntries($rawAllowlist);

        if ($enabled && $entries === []) {
            throw new InvalidArgumentException('Super admin IP allowlist must contain at least one IP or CIDR when enabled.');
        }

        $previous = $this->superAdminIpAllowlistConfig();
        $updated = [
            'enabled' => $enabled,
            'entries' => $entries,
            'raw' => implode(PHP_EOL, $entries),
        ];

        AppSecuritySettingsStore::save($this->appRoot, [
            'super_admin_ip_allowlist_enabled' => $enabled,
            'super_admin_ip_allowlist' => $entries,
        ]);

        $this->auditLog->log([
            'actor_id' => $actorId,
            'actor_role' => $actorRole ?? 'super_admin',
            'action' => 'security.super_admin_ip_allowlist_updated',
            'target_type' => 'system_settings',
            'old_values' => [
                'enabled' => $previous['enabled'],
                'entries' => $previous['entries'],
            ],
            'new_values' => [
                'enabled' => $updated['enabled'],
                'entries' => $updated['entries'],
            ],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $updated;
    }
}
