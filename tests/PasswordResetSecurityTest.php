<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Services\AppSecuritySettingsService;
use MailPanel\Services\AuditLogService;
use MailPanel\Support\AppSecuritySettingsStore;
use PHPUnit\Framework\TestCase;

final class PasswordResetSecurityTest extends TestCase
{
    public function test_password_reset_uses_hashed_one_hour_single_use_tokens(): void
    {
        $migration = (string) file_get_contents(__DIR__ . '/../database/migrations/016_create_password_reset_tokens.sql');
        $repository = (string) file_get_contents(__DIR__ . '/../src/Repositories/Pdo/PasswordResetTokenRepository.php');
        $service = (string) file_get_contents(__DIR__ . '/../src/Services/PasswordResetService.php');

        $this->assertStringContainsString('password_reset_tokens', $migration);
        $this->assertStringContainsString('token_hash CHAR(64) NOT NULL', $migration);
        $this->assertStringContainsString('UNIQUE KEY uq_password_reset_tokens_hash', $migration);
        $this->assertStringNotContainsString('plain', strtolower($migration));
        $this->assertStringContainsString('hash_hmac(\'sha256\'', $service);
        $this->assertStringContainsString('APP_KEY must be at least 32 characters for password reset tokens.', $service);
        $this->assertStringNotContainsString('mailpanel-local-password-reset-key', $service);
        $this->assertStringContainsString('random_bytes(32)', $service);
        $this->assertStringContainsString('max(300, min(3600', $service);
        $this->assertStringContainsString('markUsed', $repository);
        $this->assertStringContainsString('invalidateActiveForUser', $service);
    }

    public function test_password_reset_does_not_enumerate_or_log_tokens(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../src/Services/PasswordResetService.php');
        $mailer = (string) file_get_contents(__DIR__ . '/../src/Services/PasswordResetMailService.php');

        $this->assertStringContainsString('GENERIC_REQUEST_MESSAGE', $service);
        $this->assertStringContainsString('requested_noop', $service);
        $this->assertStringContainsString('login_fingerprint', $service);
        $this->assertStringNotContainsString("'token' =>", $service);
        $this->assertStringNotContainsString('plain_text_token', $service);
        $this->assertStringContainsString('preg_replace(\'/[\\r\\n\\x00-\\x1F\\x7F]+/\'', $mailer);
        $this->assertStringContainsString('FILTER_VALIDATE_EMAIL', $mailer);
    }

    public function test_password_reset_routes_views_and_config_are_wired(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../routes/web.php');
        $controller = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminAuthController.php');
        $factory = (string) file_get_contents(__DIR__ . '/../src/Bootstrap/ApplicationFactory.php');
        $login = (string) file_get_contents(__DIR__ . '/../src/Views/admin/login.php');
        $forgot = (string) file_get_contents(__DIR__ . '/../src/Views/admin/forgot_password.php');
        $reset = (string) file_get_contents(__DIR__ . '/../src/Views/admin/reset_password.php');
        $config = (string) file_get_contents(__DIR__ . '/../config/app.php');

        $this->assertStringContainsString('/admin/forgot-password', $routes);
        $this->assertStringContainsString('/admin/reset-password', $routes);
        $this->assertStringContainsString('forgotPassword', $controller);
        $this->assertStringContainsString('resetPassword', $controller);
        $this->assertStringContainsString('PasswordResetService::class', $factory);
        $this->assertStringContainsString('PasswordResetTokenRepository::class', $factory);
        $this->assertStringContainsString('Quên mật khẩu?', $login);
        $this->assertStringContainsString('name="_csrf"', $forgot);
        $this->assertStringContainsString('name="_csrf"', $reset);
        $this->assertStringContainsString('PASSWORD_RESET_TTL_SECONDS', $config);
        $this->assertStringContainsString('RATE_LIMIT_PASSWORD_RESET_MAX_ATTEMPTS', $config);
        $this->assertStringContainsString('safeConfiguredBaseUrl', $controller);
        $this->assertStringContainsString('safeRequestHost', $controller);
        $this->assertStringNotContainsString('X-Forwarded-Host', $controller);
    }

    public function test_portal_settings_can_override_password_reset_mail_config(): void
    {
        $routes = (string) file_get_contents(__DIR__ . '/../routes/web.php');
        $controller = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminSecurityController.php');
        $store = (string) file_get_contents(__DIR__ . '/../src/Support/AppSecuritySettingsStore.php');
        $service = (string) file_get_contents(__DIR__ . '/../src/Services/AppSecuritySettingsService.php');
        $view = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/portal_settings.php');
        $config = (string) file_get_contents(__DIR__ . '/../config/app.php');

        $this->assertStringContainsString('/admin/portal-settings', $routes);
        $this->assertStringContainsString('portalSettings', $controller);
        $this->assertStringContainsString('handleUpdatePortalSettings', $controller);
        $this->assertStringContainsString('password_reset', $store);
        $this->assertStringContainsString('updatePortalSettings', $service);
        $this->assertStringContainsString('FILTER_VALIDATE_EMAIL', $service);
        $this->assertStringContainsString('password_reset_from_email', $view);
        $this->assertStringContainsString('password_reset_transport', $view);
        $this->assertStringContainsString('password_reset_smtp_host', $view);
        $this->assertStringContainsString('password_reset_smtp_password', $view);
        $this->assertStringContainsString('current_password', $view);
        $this->assertStringContainsString('$runtimePasswordReset', $config);
    }

    public function test_external_smtp_password_reset_config_is_secret_safe(): void
    {
        $settings = (string) file_get_contents(__DIR__ . '/../src/Services/AppSecuritySettingsService.php');
        $store = (string) file_get_contents(__DIR__ . '/../src/Support/AppSecuritySettingsStore.php');
        $mailer = (string) file_get_contents(__DIR__ . '/../src/Services/PasswordResetMailService.php');
        $factory = (string) file_get_contents(__DIR__ . '/../src/Bootstrap/ApplicationFactory.php');
        $config = (string) file_get_contents(__DIR__ . '/../config/app.php');

        $this->assertStringContainsString('aes-256-gcm', $settings);
        $this->assertStringContainsString('password_encrypted', $store);
        $this->assertStringContainsString('redactPortalSettingsForAudit', $settings);
        $this->assertStringContainsString('unset($smtp[\'password_encrypted\'])', $settings);
        $this->assertStringContainsString('stream_socket_client', $mailer);
        $this->assertStringContainsString('STARTTLS', $mailer);
        $this->assertStringContainsString('AUTH LOGIN', $mailer);
        $this->assertStringContainsString('Password reset SMTP command failed.', $mailer);
        $this->assertStringContainsString('app.password_reset.mail', $factory);
        $this->assertStringContainsString('app.key', $factory);
        $this->assertStringContainsString('PASSWORD_RESET_MAIL_TRANSPORT', $config);
        $this->assertStringNotContainsString('smtp_password\' =>', $settings);
        $this->assertStringNotContainsString('password_reset_smtp_password\' =>', $settings);
    }

    public function test_external_smtp_password_reset_rejects_plaintext_auth(): void
    {
        $service = $this->portalSettingsService(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-reset-smtp-' . bin2hex(random_bytes(4)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SMTP AUTH không được phép');

        $service->updatePortalSettings(
            'portal.example.test',
            'no-reply@example.test',
            'MailPanel',
            'Đặt lại mật khẩu MailPanel',
            3600,
            'smtp',
            'smtp.example.test',
            587,
            'none',
            'mailer@example.test',
            'Secret123!',
            false,
            15
        );
    }

    public function test_external_smtp_password_reset_drops_secret_when_username_is_removed(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-reset-smtp-' . bin2hex(random_bytes(4));
        $service = $this->portalSettingsService($root);

        try {
            $service->updatePortalSettings(
                'portal.example.test',
                'no-reply@example.test',
                'MailPanel',
                'Đặt lại mật khẩu MailPanel',
                3600,
                'smtp',
                'smtp.example.test',
                587,
                'starttls',
                'mailer@example.test',
                'Secret123!',
                false,
                15
            );
            $stored = AppSecuritySettingsStore::load($root);
            $smtp = $stored['password_reset']['mail']['smtp'] ?? [];
            $this->assertNotSame('', (string) ($smtp['password_encrypted'] ?? ''));

            $service->updatePortalSettings(
                'portal.example.test',
                'no-reply@example.test',
                'MailPanel',
                'Đặt lại mật khẩu MailPanel',
                3600,
                'smtp',
                'smtp.example.test',
                587,
                'starttls',
                '',
                '',
                false,
                15
            );
            $stored = AppSecuritySettingsStore::load($root);
            $smtp = $stored['password_reset']['mail']['smtp'] ?? [];

            $this->assertSame('', (string) ($smtp['username'] ?? ''));
            $this->assertSame('', (string) ($smtp['password_encrypted'] ?? ''));
        } finally {
            $this->removeSettingsRoot($root);
        }
    }

    private function portalSettingsService(string $root): AppSecuritySettingsService
    {
        return new AppSecuritySettingsService(
            $root,
            ['key' => str_repeat('a', 32)],
            new class extends AuditLogService {
                public function __construct()
                {
                }

                public function log(array $entry): void
                {
                }
            }
        );
    }

    private function removeSettingsRoot(string $root): void
    {
        $path = AppSecuritySettingsStore::path($root);
        foreach ([$path, $path . '.tmp'] as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        @rmdir(dirname($path));
        @rmdir(dirname(dirname($path)));
        @rmdir($root);
    }
}
