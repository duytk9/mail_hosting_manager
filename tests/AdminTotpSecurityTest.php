<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Tests\Support\ControllerSource;
use PHPUnit\Framework\TestCase;

final class AdminTotpSecurityTest extends TestCase
{
    public function test_totp_actions_require_current_password_in_service_controller_and_view(): void
    {
        $service = ControllerSource::file('src/Services/AdminSecurityService.php');
        $controller = ControllerSource::adminWeb();
        $view = ControllerSource::file('src/Views/admin/pages/security.php');

        $this->assertMatchesRegularExpression(
            '/function startTotpEnrollment\\(int \\$userId, string \\$currentPassword, \\?string \\$otp = null\\)/',
            $service
        );
        $this->assertMatchesRegularExpression(
            '/function confirmTotpEnrollment\\(int \\$userId, string \\$code, string \\$currentPassword\\)/',
            $service
        );
        $this->assertMatchesRegularExpression(
            '/function disableTotp\\(int \\$userId, string \\$code, string \\$currentPassword\\)/',
            $service
        );
        $this->assertMatchesRegularExpression('/startTotpEnrollment\\([\\s\\S]*current_password/', $controller);
        $this->assertMatchesRegularExpression('/confirmTotpEnrollment\\([\\s\\S]*current_password/', $controller);
        $this->assertMatchesRegularExpression('/disableTotp\\([\\s\\S]*current_password/', $controller);
        $this->assertSame(4, substr_count($view, 'name="current_password"'));
    }
}
