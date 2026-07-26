<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Tests\Support\ControllerSource;
use PHPUnit\Framework\TestCase;

final class SuperAdminWebSecurityTest extends TestCase
{
    public function test_super_admin_mutations_require_recent_sensitive_action_verification(): void
    {
        $controller = ControllerSource::adminWeb();
        $view = ControllerSource::file('src/Views/admin/pages/super_admins.php');

        $this->assertStringContainsString('assertCurrentAdminSensitiveAction($request)', $controller);
        $this->assertStringContainsString('assertSensitiveActionAllowed(', $controller);
        $this->assertMatchesRegularExpression('/superAdmins\\([\\s\\S]*assertCurrentAdminSensitiveAction\\(\\$request\\)[\\s\\S]*handleCreateSuperAdmin/', $controller);
        $this->assertGreaterThanOrEqual(5, substr_count($view, 'name="current_password"'));
        $this->assertGreaterThanOrEqual(5, substr_count($view, 'name="otp"'));
    }

    public function test_impersonation_does_not_bypass_force_password_change(): void
    {
        $controller = ControllerSource::adminWeb();

        $this->assertStringNotContainsString('$targetIdentity[\'force_password_change\'] = 0;', $controller);
        $this->assertStringContainsString('Không thể impersonate tài khoản đang bắt buộc đổi mật khẩu.', $controller);
        $this->assertMatchesRegularExpression('/function requireImpersonatableUser[\\s\\S]*force_password_change[\\s\\S]*throw new \\\\InvalidArgumentException/', $controller);
    }
}
