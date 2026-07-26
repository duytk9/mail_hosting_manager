<?php

declare(strict_types=1);

namespace MailPanel\Security;

use RuntimeException;

final class AuthorizationService
{
    public function can(Actor $actor, string $permission): bool
    {
        return $this->checkPermissions($actor, [$permission], false);
    }

    public function canAny(Actor $actor, array $permissions): bool
    {
        return $this->checkPermissions($actor, $permissions, true);
    }

    public function canAll(Actor $actor, array $permissions): bool
    {
        return $this->checkPermissions($actor, $permissions, false);
    }

    /**
     * @return array<int, string>
     */
    public function permissionsForActor(Actor $actor): array
    {
        return PermissionMap::permissionsForRole($actor->role);
    }

    public function requireRole(Actor $actor, array $allowedRoles): void
    {
        if (!in_array($actor->role, $allowedRoles, true)) {
            throw new RuntimeException('Forbidden.');
        }
    }

    public function requireTenantScope(Actor $actor, ?int $tenantId): void
    {
        if ($actor->role === 'super_admin' || $actor->role === 'support_readonly') {
            return;
        }

        if ($tenantId === null || $actor->tenantId !== $tenantId) {
            throw new RuntimeException('Tenant scope violation.');
        }
    }

    public function assertWritable(Actor $actor): void
    {
        if ($actor->role === 'support_readonly') {
            throw new RuntimeException('Readonly role cannot modify resources.');
        }
    }

    public function requirePermission(Actor $actor, string|array $permissions, bool $matchAny = false): void
    {
        $permissions = is_array($permissions) ? array_values($permissions) : [$permissions];

        if (!$this->checkPermissions($actor, $permissions, $matchAny)) {
            throw new RuntimeException('Permission denied.');
        }
    }

    public function requireScopes(array $token, array $requiredScopes): void
    {
        $scopes = json_decode((string) ($token['scopes'] ?? '[]'), true);
        $scopes = is_array($scopes) ? $scopes : [];

        foreach ($requiredScopes as $scope) {
            if (!in_array($scope, $scopes, true) && !in_array('*', $scopes, true)) {
                throw new RuntimeException('API token scope denied.');
            }
        }
    }

    /**
     * @param array<int, string> $permissions
     */
    private function checkPermissions(Actor $actor, array $permissions, bool $matchAny): bool
    {
        if ($permissions === []) {
            return true;
        }

        $permissions = array_values(array_filter($permissions, static fn (string $permission): bool => $permission !== ''));

        if ($permissions === []) {
            return true;
        }

        foreach ($permissions as $permission) {
            if (!PermissionMap::isValid($permission)) {
                return false;
            }
        }

        $grants = $this->permissionsForActor($actor);
        $matches = array_map(
            static fn (string $permission): bool => in_array($permission, $grants, true),
            $permissions
        );

        return $matchAny
            ? in_array(true, $matches, true)
            : !in_array(false, $matches, true);
    }
}
