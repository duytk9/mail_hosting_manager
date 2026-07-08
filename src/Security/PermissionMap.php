<?php

declare(strict_types=1);

namespace MailPanel\Security;

final class PermissionMap
{
    private const ACTIONS = [
        'view',
        'create',
        'update',
        'delete',
        'restore',
        'export',
        'import',
        'bulk_action',
    ];

    private const MODULES = [
        'dashboard',
        'security',
        'users',
        'roles',
        'permissions',
        'super_admins',
        'api_tokens',
        'tenants',
        'packages',
        'domains',
        'dns_checks',
        'mailboxes',
        'aliases',
        'forwards',
        'mail_groups',
        'routing',
        'catch_all',
        'dkim',
        'spam_policies',
        'quarantine',
        'sieve_rules',
        'rate_limit_rules',
        'allowlists',
        'blocklists',
        'fail2ban',
        'config_versions',
        'services',
        'queue',
        'logs',
        'audit_logs',
        'system_settings',
        'jobs',
        'quota',
        'profile',
    ];

    private const ROLE_GRANTS = [
        'tenant_admin' => [
            'dashboard.view',
            'security.view',
            'security.update',
            'spam_policies.view',
            'spam_policies.update',
            'domains.view',
            'domains.create',
            'domains.update',
            'domains.delete',
            'domains.export',
            'dns_checks.view',
            'dns_checks.update',
            'mailboxes.view',
            'mailboxes.create',
            'mailboxes.update',
            'mailboxes.delete',
            'mailboxes.export',
            'aliases.view',
            'aliases.create',
            'aliases.delete',
            'aliases.export',
            'forwards.view',
            'forwards.create',
            'forwards.delete',
            'forwards.export',
            'mail_groups.view',
            'mail_groups.create',
            'mail_groups.update',
            'mail_groups.delete',
            'mail_groups.export',
            'routing.view',
            'quota.view',
        ],
        'domain_admin' => [
            'security.view',
            'security.update',
            'profile.update',
        ],
        'support_readonly' => [
            'dashboard.view',
            'security.view',
            'packages.view',
            'packages.export',
            'tenants.view',
            'tenants.export',
            'domains.view',
            'domains.export',
            'dns_checks.view',
            'mailboxes.view',
            'mailboxes.export',
            'aliases.view',
            'aliases.export',
            'forwards.view',
            'forwards.export',
            'mail_groups.view',
            'mail_groups.export',
            'routing.view',
            'config_versions.view',
            'config_versions.export',
            'services.view',
            'queue.view',
            'logs.view',
            'audit_logs.view',
        ],
        'mailbox_user' => [
            'quota.view',
            'profile.update',
            'forwards.view',
            'forwards.create',
            'forwards.delete',
        ],
    ];

    /** @var array<int, string>|null */
    private static ?array $allPermissions = null;

    /**
     * @return array<int, string>
     */
    public static function allPermissions(): array
    {
        if (self::$allPermissions !== null) {
            return self::$allPermissions;
        }

        $permissions = [];

        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = $module . '.' . $action;
            }
        }

        sort($permissions);

        return self::$allPermissions = $permissions;
    }

    /**
     * @return array<int, string>
     */
    public static function permissionsForRole(string $role): array
    {
        if ($role === 'super_admin') {
            return self::allPermissions();
        }

        $permissions = self::ROLE_GRANTS[$role] ?? [];
        $permissions = array_values(array_unique(array_filter(
            $permissions,
            static fn (string $permission): bool => self::isValid($permission)
        )));
        sort($permissions);

        return $permissions;
    }

    public static function isValid(string $permission): bool
    {
        return in_array($permission, self::allPermissions(), true);
    }
}
