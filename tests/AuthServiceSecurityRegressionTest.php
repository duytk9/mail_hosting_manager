<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AuthServiceSecurityRegressionTest extends TestCase
{
    public function test_missing_admin_totp_is_recorded_as_rate_limited_failure(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Services/AuthService.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("'auth.admin_login.totp_required'", $source);
        $this->assertMatchesRegularExpression(
            "/if \\(\\\$oneTimeCode === null \\|\\| trim\\(\\\$oneTimeCode\\) === ''\\) \\{\\s*\\\$this->recordFailure\\([^;]+auth\\.admin_login\\.totp_required/s",
            $source
        );
    }

    public function test_admin_totp_grace_logins_lock_account_after_limit(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/AuthService.php');
        $migration = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/015_add_admin_totp_enforcement.sql');
        $envExample = (string) file_get_contents(dirname(__DIR__) . '/.env.example');

        $this->assertStringContainsString('DEFAULT_ADMIN_TOTP_GRACE_LOGINS = 5', $source);
        $this->assertStringContainsString('enforceAdminTotpGrace', $source);
        $this->assertStringContainsString('lockForSecurity', $source);
        $this->assertStringContainsString('auth.admin_login.locked_totp_required', $source);
        $this->assertStringContainsString('auth.admin_login.totp_grace_used', $source);
        $this->assertStringContainsString('totp_grace_login_count', $migration);
        $this->assertStringContainsString('security_locked_at', $migration);
        $this->assertStringContainsString('ADMIN_TOTP_GRACE_LOGINS=5', $envExample);
    }
}
