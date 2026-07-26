<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Request;
use MailPanel\Repositories\Pdo\ApiTokenRepository;
use MailPanel\Security\TokenGuard;
use PHPUnit\Framework\TestCase;

final class TokenGuardTest extends TestCase
{
    public function test_resolve_rejects_malformed_bearer_without_repository_lookup(): void
    {
        $repository = new class extends ApiTokenRepository {
            public int $lookups = 0;

            public function __construct()
            {
            }

            public function findByHash(string $tokenHash): ?array
            {
                $this->lookups++;

                return null;
            }
        };

        $guard = new TokenGuard($repository);

        foreach (['Bearer', 'Bearer ', 'Bearer short', "Bearer abc\n123", 'Basic abcdefghijklmnopqrstuvwxyz123456'] as $header) {
            $this->assertNull($guard->resolve($this->requestWithAuthorization($header)));
        }

        $this->assertSame(0, $repository->lookups);
    }

    public function test_resolve_accepts_case_insensitive_bearer_and_touches_token(): void
    {
        $plainToken = str_repeat('a', 48);
        $repository = new class extends ApiTokenRepository {
            public ?string $lastHash = null;
            public ?int $touchedId = null;

            public function __construct()
            {
            }

            public function findByHash(string $tokenHash): ?array
            {
                $this->lastHash = $tokenHash;

                return ['id' => 42, 'user_id' => 7, 'actor_role' => 'super_admin', 'scopes' => '["dashboard.read"]'];
            }

            public function touch(int $id): void
            {
                $this->touchedId = $id;
            }
        };

        $guard = new TokenGuard($repository);
        $token = $guard->resolve($this->requestWithAuthorization('bearer ' . $plainToken));

        $this->assertSame(42, $token['id'] ?? null);
        $this->assertSame(hash('sha256', $plainToken), $repository->lastHash);
        $this->assertSame(42, $repository->touchedId);
    }

    public function test_has_bearer_credential_matches_invalid_bearer_as_present(): void
    {
        $this->assertTrue(TokenGuard::hasBearerCredential('Bearer'));
        $this->assertTrue(TokenGuard::hasBearerCredential('bearer short'));
        $this->assertFalse(TokenGuard::hasBearerCredential('Basic short'));
    }

    private function requestWithAuthorization(string $authorization): Request
    {
        return new Request(
            'GET',
            '/api/secure',
            [],
            [],
            ['HTTP_AUTHORIZATION' => $authorization],
        );
    }
}
