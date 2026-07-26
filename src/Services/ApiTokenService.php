<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Repositories\Pdo\ApiTokenRepository;

final class ApiTokenService
{
    private const ALLOWED_SCOPES = [
        '*',
        'tokens.write',
        'dashboard.read',
        'packages.read',
        'packages.write',
        'tenants.read',
        'tenants.write',
        'domains.read',
        'domains.write',
        'mailboxes.read',
        'mailboxes.write',
        'aliases.read',
        'aliases.write',
        'forwards.read',
        'forwards.write',
        'config.read',
        'config.write',
        'quota.read',
        'quota.write',
        'profile.write',
    ];

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly AuditLogService $auditLog
    ) {
    }

    public function issueAdminToken(int $userId, ?int $tenantId, string $actorRole, string $name, array $scopes, ?string $expiresAt = null): array
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 120) {
            throw new \InvalidArgumentException('Token name is required and must be at most 120 characters.');
        }

        $scopes = $this->normalizeScopes($scopes, $actorRole);
        $expiresAt = $this->normalizeExpiry($expiresAt);

        $plain = bin2hex(random_bytes(24));
        $row = $this->tokens->create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'mailbox_id' => null,
            'actor_role' => $actorRole,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'scopes' => json_encode(array_values($scopes), JSON_UNESCAPED_SLASHES),
            'expires_at' => $expiresAt,
        ]);

        $this->auditLog->log([
            'actor_id' => $userId,
            'actor_role' => $actorRole,
            'action' => 'api_token.created',
            'target_type' => 'api_token',
            'target_id' => $row['id'] ?? null,
            'tenant_id' => $tenantId,
            'new_values' => ['name' => $name, 'scopes' => $scopes],
        ]);

        return $this->publicTokenPayload($row, $plain);
    }

    private function publicTokenPayload(array $row, string $plain): array
    {
        $payload = array_intersect_key($row, array_flip([
            'id',
            'tenant_id',
            'user_id',
            'mailbox_id',
            'actor_role',
            'name',
            'scopes',
            'expires_at',
            'created_at',
            'updated_at',
        ]));
        $payload['plain_text_token'] = $plain;

        return $payload;
    }

    private function normalizeScopes(array $scopes, string $actorRole): array
    {
        $scopes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $scope): string => trim((string) $scope),
            $scopes
        ))));

        if ($scopes === []) {
            throw new \InvalidArgumentException('At least one API token scope is required.');
        }

        foreach ($scopes as $scope) {
            if (!in_array($scope, self::ALLOWED_SCOPES, true)) {
                throw new \InvalidArgumentException('Invalid API token scope.');
            }
        }

        if (in_array('*', $scopes, true) && $actorRole !== 'super_admin') {
            throw new \InvalidArgumentException('Wildcard API token scope is restricted to super admins.');
        }

        if (in_array('*', $scopes, true) && count($scopes) > 1) {
            return ['*'];
        }

        return $scopes;
    }

    private function normalizeExpiry(?string $expiresAt): ?string
    {
        $expiresAt = trim((string) ($expiresAt ?? ''));
        if ($expiresAt === '') {
            return null;
        }

        $timestamp = strtotime($expiresAt);
        if ($timestamp === false) {
            throw new \InvalidArgumentException('Invalid API token expiry date.');
        }

        if ($timestamp <= time()) {
            throw new \InvalidArgumentException('API token expiry date must be in the future.');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}
