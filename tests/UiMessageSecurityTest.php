<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Support\UiMessage;
use PDOException;
use MailPanel\Tests\Support\ControllerSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UiMessageSecurityTest extends TestCase
{
    public function test_public_validation_errors_are_preserved_but_redacted(): void
    {
        $message = UiMessage::exception(new InvalidArgumentException(
            'Invalid input password=Secret123 Authorization: Bearer hidden-token'
        ));

        $this->assertStringContainsString('Invalid input password=[REDACTED]', $message);
        $this->assertStringContainsString('Authorization: Bearer [REDACTED]', $message);
        $this->assertStringNotContainsString('Secret123', $message);
        $this->assertStringNotContainsString('hidden-token', $message);
    }

    public function test_internal_errors_use_generic_message(): void
    {
        $message = UiMessage::exception(new PDOException(
            'SQLSTATE[HY000] password=secret db_password=secret /var/www/panel'
        ));

        $this->assertSame('Có lỗi xảy ra. Vui lòng thử lại hoặc kiểm tra log hệ thống.', $message);
    }

    public function test_custom_fallback_is_redacted(): void
    {
        $message = UiMessage::exception(
            new RuntimeException('ignored internal detail'),
            'Không thể xử lý password=Secret123'
        );

        $this->assertSame('Không thể xử lý password=[REDACTED]', $message);
    }

    public function test_web_controllers_do_not_flash_raw_exception_messages(): void
    {
        foreach (ControllerSource::controllerPaths() as $path) {
            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString("flash('error', \$exception->getMessage())", $source);
            $this->assertStringNotContainsString("flash('error', \$e->getMessage())", $source);
            $this->assertStringNotContainsString('$error = $e->getMessage();', $source);
        }
    }

    public function test_domain_and_tenant_services_do_not_audit_raw_sync_exceptions(): void
    {
        foreach ([
            __DIR__ . '/../src/Services/DomainService.php',
            __DIR__ . '/../src/Services/TenantService.php',
            __DIR__ . '/../src/Services/AcmeTlsService.php',
            __DIR__ . '/../src/Services/MailboxService.php',
        ] as $path) {
            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString('return $exception->getMessage();', $source);
            $this->assertStringNotContainsString("'error' => \$exception->getMessage()", $source);
            $this->assertStringNotContainsString('$storageError = $exception->getMessage();', $source);
            $this->assertStringNotContainsString('$storageProvisionError = $exception->getMessage();', $source);
            $this->assertStringNotContainsString('$webmailBootstrapError = $exception->getMessage();', $source);
            $this->assertStringNotContainsString('$webmailStorageError = $exception->getMessage();', $source);
        }
    }
}
