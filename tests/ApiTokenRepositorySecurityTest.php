<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class ApiTokenRepositorySecurityTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Repositories/Pdo/ApiTokenRepository.php');
        $this->assertIsString($source);
        $this->source = $source;
    }

    public function test_token_listing_requires_owner_scope_and_never_lists_everything(): void
    {
        $this->assertStringContainsString('$userId === null && $mailboxId === null', $this->source);
        $this->assertStringContainsString('return [];', $this->source);
        $this->assertMatchesRegularExpression(
            '/WHERE\s*\(\s*\(:user_id IS NOT NULL AND at\.user_id = :user_id\)\s*OR\s*\(:mailbox_id IS NOT NULL AND at\.mailbox_id = :mailbox_id\)\s*\)/s',
            $this->source
        );
    }

    public function test_token_listing_filters_revoked_expired_and_invalid_actor_lifecycle(): void
    {
        $this->assertStringContainsString('AND at.revoked_at IS NULL', $this->source);
        $this->assertStringContainsString('AND (at.expires_at IS NULL OR at.expires_at > NOW())', $this->source);
        $this->assertStringContainsString('AND u.deleted_at IS NULL', $this->source);
        $this->assertStringContainsString('AND u.role = at.actor_role', $this->source);
        $this->assertStringContainsString('COALESCE(u.force_password_change, 0) = 0', $this->source);
        $this->assertStringContainsString('AND m.deleted_at IS NULL', $this->source);
        $this->assertStringContainsString("AND m.status = \\'active\\'", $this->source);
        $this->assertStringContainsString('COALESCE(m.force_password_change, 0) = 0', $this->source);
        $this->assertStringContainsString("AND d.status = \\'active\\'", $this->source);
        $this->assertStringContainsString('TenantLifecyclePolicy::sqlMailAccessCondition', $this->source);
    }
}
