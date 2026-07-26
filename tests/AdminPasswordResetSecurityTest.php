<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Tests\Support\ControllerSource;
use PHPUnit\Framework\TestCase;

final class AdminPasswordResetSecurityTest extends TestCase
{
    public function test_mailbox_and_owner_password_resets_require_admin_reauthentication(): void
    {
        $controller = ControllerSource::adminWeb();
        $mailboxesView = ControllerSource::file('src/Views/admin/pages/mailboxes.php');
        $tenantsView = ControllerSource::file('src/Views/admin/pages/tenants.php');

        $this->assertMatchesRegularExpression(
            '/elseif \\(\\$action === \'password\'\\) \\{\\s*\\$this->assertCurrentAdminSensitiveAction\\(\\$request\\);/s',
            $controller
        );
        $this->assertMatchesRegularExpression(
            '/if \\(\\$resetPassword === 1\\) \\{\\s*\\$this->assertCurrentAdminSensitiveAction\\(\\$request\\);/s',
            $controller
        );
        $this->assertMatchesRegularExpression('/name="action" value="password"[\\s\\S]*name="current_password"/', $mailboxesView);
        $this->assertMatchesRegularExpression('/name="reset_password"[\\s\\S]*name="current_password"/', $tenantsView);
    }
}
