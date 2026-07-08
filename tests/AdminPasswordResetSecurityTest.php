<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AdminPasswordResetSecurityTest extends TestCase
{
    public function test_mailbox_and_owner_password_resets_require_admin_reauthentication(): void
    {
        $mailboxController = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminMailboxController.php');
        $tenantController = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminTenantController.php');
        $mailboxesView = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/mailboxes.php');
        $tenantsView = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/tenants.php');

        $this->assertMatchesRegularExpression(
            '/elseif \\(\\$action === \'password\'\\) \\{\\s*\\$this->assertCurrentAdminSensitiveAction\\(\\$request\\);/s',
            $mailboxController
        );
        $this->assertMatchesRegularExpression(
            '/if \\(\\$resetPassword === 1\\) \\{\\s*\\$this->assertCurrentAdminSensitiveAction\\(\\$request\\);/s',
            $tenantController
        );
        $this->assertMatchesRegularExpression('/name="action" value="password"[\\s\\S]*name="current_password"/', $mailboxesView);
        $this->assertMatchesRegularExpression('/name="action" value="password"[\\s\\S]*name="new_password"/', $mailboxesView);
        $this->assertMatchesRegularExpression('/name="reset_password"[\\s\\S]*name="current_password"/', $tenantsView);
        $this->assertMatchesRegularExpression('/name="reset_password"[\\s\\S]*name="new_password"/', $tenantsView);
        $this->assertStringNotContainsString('Mật khẩu mới là: [', $mailboxController);
        $this->assertStringNotContainsString('Mật khẩu mới là: [', $tenantController);
    }

    public function test_secure_admin_account_utility_uses_app_root_and_clears_security_lock_on_reset(): void
    {
        $script = (string) file_get_contents(__DIR__ . '/../scripts/admin_account.php');

        $this->assertStringContainsString('$appRoot = dirname(__DIR__);', $script);
        $this->assertStringContainsString("require_once \$appRoot . '/vendor/autoload.php';", $script);
        $this->assertStringContainsString('$users->clearSecurityLock((int) $user[\'id\']);', $script);
        $this->assertStringContainsString('$users->resetTotpGraceLoginCount((int) $user[\'id\']);', $script);
        $this->assertStringNotContainsString("__DIR__ . '/vendor/autoload.php'", $script);
    }
}
