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
}
