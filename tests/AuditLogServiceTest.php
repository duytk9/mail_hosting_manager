<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\AuditLogService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuditLogServiceTest extends TestCase
{
    public function test_sanitize_redacts_password_token_and_otp_fields_recursively(): void
    {
        $service = (new ReflectionClass(AuditLogService::class))->newInstanceWithoutConstructor();
        $sanitized = $service->sanitize([
            'action' => 'auth.test',
            'target_type' => 'user',
            'new_values' => [
                'current_password' => 'CurrentSecret123!',
                'new_password' => 'NewSecret123!',
                'generated_password' => 'GeneratedSecret123!',
                'temporary_password' => 'TemporarySecret123!',
                'admin_password' => 'AdminSecret123!',
                'mailbox_password' => 'MailboxSecret123!',
                'otp' => '123456',
                'api_key' => 'api-secret',
                'nested' => [
                    'plain_text_login_key' => 'login-key-secret',
                ],
            ],
        ]);

        $this->assertSame('[REDACTED]', $sanitized['new_values']['current_password']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['new_password']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['generated_password']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['temporary_password']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['admin_password']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['mailbox_password']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['otp']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['api_key']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['nested']['plain_text_login_key']);
    }

    public function test_sanitize_redacts_sensitive_patterns_inside_string_values(): void
    {
        $service = (new ReflectionClass(AuditLogService::class))->newInstanceWithoutConstructor();
        $sanitized = $service->sanitize([
            'action' => 'system.test',
            'target_type' => 'config',
            'new_values' => [
                'stderr' => 'failed password=Secret123 token:abc123 api_secret=hidden {"private_key":"pem-data"}',
                'raw' => "Authorization: Bearer very-secret-token\n-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----",
                'nested' => [
                    'apiToken' => 'should-redact-by-key',
                    'message' => 'normal password policy text should stay readable',
                ],
            ],
            'user_agent' => 'Mozilla token=browser-secret',
        ]);

        $this->assertSame(
            'failed password=[REDACTED] token:[REDACTED] api_secret=[REDACTED] {"private_key":"[REDACTED]"}',
            $sanitized['new_values']['stderr']
        );
        $this->assertStringContainsString('Authorization: Bearer [REDACTED]', $sanitized['new_values']['raw']);
        $this->assertStringContainsString('[REDACTED_PRIVATE_KEY]', $sanitized['new_values']['raw']);
        $this->assertSame('[REDACTED]', $sanitized['new_values']['nested']['apiToken']);
        $this->assertSame('normal password policy text should stay readable', $sanitized['new_values']['nested']['message']);
        $this->assertSame('Mozilla token=[REDACTED]', $sanitized['user_agent']);
    }

    public function test_sanitize_truncates_after_redaction(): void
    {
        $service = (new ReflectionClass(AuditLogService::class))->newInstanceWithoutConstructor();
        $sanitized = $service->sanitize([
            'action' => 'system.test',
            'target_type' => 'config',
            'new_values' => [
                'raw' => 'password=Secret123 ' . str_repeat('x', 5000),
            ],
        ]);

        $this->assertStringStartsWith('password=[REDACTED] ', $sanitized['new_values']['raw']);
        $this->assertSame(4000, strlen($sanitized['new_values']['raw']));
        $this->assertStringNotContainsString('Secret123', $sanitized['new_values']['raw']);
    }

    public function test_sanitize_strips_control_characters_from_logged_strings(): void
    {
        $service = (new ReflectionClass(AuditLogService::class))->newInstanceWithoutConstructor();
        $sanitized = $service->sanitize([
            'action' => 'system.test',
            'target_type' => 'config',
            'new_values' => [
                'message' => "first line\r\nsecond line\0 password=Secret123",
            ],
            'user_agent' => "Browser\r\nInjected: header token=browser-secret",
        ]);

        $this->assertSame('first linesecond line password=[REDACTED]', $sanitized['new_values']['message']);
        $this->assertSame('BrowserInjected: header token=[REDACTED]', $sanitized['user_agent']);
        $this->assertStringNotContainsString("\r", $sanitized['new_values']['message']);
        $this->assertStringNotContainsString("\n", $sanitized['user_agent']);
        $this->assertStringNotContainsString("\0", $sanitized['new_values']['message']);
    }
}
