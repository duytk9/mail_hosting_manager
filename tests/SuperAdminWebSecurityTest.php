<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class SuperAdminWebSecurityTest extends TestCase
{
    public function test_super_admin_mutations_require_recent_sensitive_action_verification(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminSecurityController.php');
        $view = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/super_admins.php');

        $this->assertStringContainsString('assertCurrentAdminSensitiveAction($request)', $controller);
        $this->assertStringContainsString('assertSensitiveActionAllowed(', $controller);
        $this->assertMatchesRegularExpression('/superAdmins\\([\\s\\S]*assertCurrentAdminSensitiveAction\\(\\$request\\)[\\s\\S]*handleCreateSuperAdmin/', $controller);
        $this->assertStringContainsString('name="linux_username"', $view);
        $this->assertStringContainsString('$request->body[\'linux_username\'] ?? ($request->body[\'username\'] ?? \'\')', $controller);
        $this->assertStringContainsString('name="new_password"', $view);
        $this->assertStringContainsString("'toggle_security_lock'", $controller);
        $this->assertStringContainsString('handleToggleSuperAdminSecurityLock', $controller);
        $this->assertStringContainsString('setSecurityLock(', $controller);
        $this->assertStringContainsString('name="_intent" value="toggle_security_lock"', $view);
        $this->assertStringContainsString('name="locked"', $view);
        $this->assertStringNotContainsString('Mật khẩu mới: [', $controller);
        $this->assertGreaterThanOrEqual(5, substr_count($view, 'name="current_password"'));
        $this->assertGreaterThanOrEqual(5, substr_count($view, 'name="otp"'));
    }

    public function test_impersonation_does_not_bypass_force_password_change(): void
    {
        $controller = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminAuthController.php');

        $this->assertStringNotContainsString('$targetIdentity[\'force_password_change\'] = 0;', $controller);
        $this->assertStringContainsString('force_password_change', $controller);
        $this->assertMatchesRegularExpression('/function requireImpersonatableUser[\\s\\S]*force_password_change[\\s\\S]*throw new \\\\InvalidArgumentException/', $controller);
    }
}
