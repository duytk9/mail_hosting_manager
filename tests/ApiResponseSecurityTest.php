<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Support\ApiResponse;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ApiResponseSecurityTest extends TestCase
{
    public function test_exception_response_keeps_validation_messages_public(): void
    {
        $payload = $this->payload(ApiResponse::exception(
            new InvalidArgumentException('Forward destination address must be a valid email.'),
            422,
            'Request failed.'
        ));

        $this->assertFalse($payload['success']);
        $this->assertSame('Forward destination address must be a valid email.', $payload['error']['message']);
    }

    public function test_exception_response_hides_pdo_exception_messages(): void
    {
        $payload = $this->payload(ApiResponse::exception(
            new PDOException('SQLSTATE[HY000] password=secret db_password=secret'),
            422,
            'Admin API request failed.',
            [InvalidArgumentException::class, RuntimeException::class]
        ));

        $this->assertFalse($payload['success']);
        $this->assertSame('Admin API request failed.', $payload['error']['message']);
    }

    public function test_public_exception_messages_are_redacted_before_returned(): void
    {
        $payload = $this->payload(ApiResponse::exception(
            new InvalidArgumentException('Invalid input password=Secret123 Authorization: Bearer hidden-token {"private_key":"pem"}'),
            422,
            'Request failed.'
        ));

        $this->assertFalse($payload['success']);
        $this->assertSame(
            'Invalid input password=[REDACTED] Authorization: Bearer [REDACTED] {"private_key":"[REDACTED]"}',
            $payload['error']['message']
        );
        $this->assertStringNotContainsString('Secret123', $payload['error']['message']);
        $this->assertStringNotContainsString('hidden-token', $payload['error']['message']);
        $this->assertStringNotContainsString('pem', $payload['error']['message']);
    }

    public function test_error_response_strips_control_characters_and_truncates(): void
    {
        $payload = $this->payload(ApiResponse::error("Failure\0 " . str_repeat('x', 1200)));

        $this->assertFalse($payload['success']);
        $this->assertStringNotContainsString("\0", $payload['error']['message']);
        $this->assertSame(1000, strlen($payload['error']['message']));
    }

    public function test_controllers_use_safe_exception_wrapper_for_api_catches(): void
    {
        foreach ([
            'src/Http/Controllers/AdminController.php',
            'src/Http/Controllers/AuthController.php',
            'src/Http/Controllers/UserController.php',
        ] as $path) {
            $source = file_get_contents(dirname(__DIR__) . '/' . $path);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('ApiResponse::error($exception->getMessage()', $source);
            $this->assertStringContainsString('ApiResponse::exception(', $source);
        }
    }

    private function payload(object $response): array
    {
        ob_start();
        $response->send();
        $json = (string) ob_get_clean();

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
