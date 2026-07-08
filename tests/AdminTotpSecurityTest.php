<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AdminTotpSecurityTest extends TestCase
{
    public function test_totp_actions_require_current_password_in_service_controller_and_view(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../src/Services/AdminSecurityService.php');
        $controller = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminSecurityController.php');
        $view = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/security.php');

        $this->assertMatchesRegularExpression(
            '/function startTotpEnrollment\\(int \\$userId, string \\$currentPassword, \\?string \\$otp = null, \\?string \\$label = null\\)/',
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
        $this->assertStringContainsString('totpPortalLabel', $controller);
        $this->assertStringContainsString('configuredPortalHost', $controller);
        $this->assertStringContainsString('$this->appConfig[\'base_url\']', $controller);
        $this->assertStringNotContainsString('X-Forwarded-Host', $controller);
        $this->assertMatchesRegularExpression('/confirmTotpEnrollment\\([\\s\\S]*current_password/', $controller);
        $this->assertMatchesRegularExpression('/disableTotp\\([\\s\\S]*current_password/', $controller);
        $this->assertStringContainsString("unset(\$securityUser['password_hash'], \$securityUser['totp_secret'], \$securityUser['totp_pending_secret'])", $controller);
        $this->assertStringContainsString('replaceIdentity($securityUser)', $controller);
        $this->assertStringContainsString(". ' - ' .", $controller);
        $this->assertStringContainsString("str_replace(':', ' - ', \$label)", $service);
        $this->assertStringContainsString('refreshAdminSessionIdentity($userId, $identity)', $controller);
        $this->assertStringContainsString('replaceIdentity($freshUser)', $controller);
        $this->assertGreaterThanOrEqual(3, substr_count($view, 'name="current_password"'));
        $this->assertStringContainsString('qr_data_uri', $service);
        $this->assertStringContainsString('totp-enrollment__qr', $view);
        $this->assertStringContainsString('OTP', $view);
        $this->assertStringContainsString('totp-status-strip', $view);
        $this->assertStringContainsString('security-step-card', $view);
        $this->assertStringContainsString('otp-input', $view);
    }

    public function test_admin_layout_warns_about_remaining_totp_grace_logins(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../src/Views/admin/layout.php');
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/admin.css');

        $this->assertStringContainsString('$totpGraceRemaining', $layout);
        $this->assertStringContainsString('$showTotpGraceWarning', $layout);
        $this->assertStringContainsString('OTP', $layout);
        $this->assertStringContainsString('/admin/security#totp-setup', $layout);
        $this->assertStringContainsString('.flash.warning', $css);
    }
}
