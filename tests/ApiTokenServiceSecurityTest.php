<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Repositories\Pdo\ApiTokenRepository;
use MailPanel\Services\ApiTokenService;
use MailPanel\Services\AuditLogService;
use PHPUnit\Framework\TestCase;

final class ApiTokenServiceSecurityTest extends TestCase
{
    public function test_issue_admin_token_never_returns_stored_token_hash(): void
    {
        $service = new ApiTokenService(
            new class extends ApiTokenRepository {
                public array $created = [];

                public function __construct()
                {
                }

                public function create(array $data): array
                {
                    $this->created = $data;

                    return [
                        'id' => 123,
                        'tenant_id' => $data['tenant_id'],
                        'user_id' => $data['user_id'],
                        'mailbox_id' => $data['mailbox_id'],
                        'actor_role' => $data['actor_role'],
                        'name' => $data['name'],
                        'token_hash' => $data['token_hash'],
                        'scopes' => $data['scopes'],
                        'last_used_at' => null,
                        'expires_at' => $data['expires_at'],
                        'revoked_at' => null,
                        'created_at' => '2026-01-01 00:00:00',
                        'updated_at' => '2026-01-01 00:00:00',
                    ];
                }
            },
            new class extends AuditLogService {
                public function __construct()
                {
                }

                public function log(array $entry): void
                {
                }
            }
        );

        $token = $service->issueAdminToken(7, null, 'super_admin', 'ops-key', ['dashboard.read']);

        $this->assertArrayHasKey('plain_text_token', $token);
        $this->assertArrayNotHasKey('token_hash', $token);
        $this->assertArrayNotHasKey('last_used_at', $token);
        $this->assertArrayNotHasKey('revoked_at', $token);
        $this->assertSame('ops-key', $token['name']);
        $this->assertSame(7, $token['user_id']);
        $this->assertSame('dashboard.read', json_decode((string) $token['scopes'], true, 512, JSON_THROW_ON_ERROR)[0]);
    }

    public function test_auth_controller_uses_generic_message_for_unexpected_login_key_errors(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/AuthController.php');
        $this->assertIsString($source);
        $this->assertStringContainsString('Unable to issue Login Key.', $source);
        $this->assertStringContainsString('ApiResponse::exception(', $source);
        $this->assertStringContainsString('[\\InvalidArgumentException::class, \\RuntimeException::class]', $source);
    }
}
