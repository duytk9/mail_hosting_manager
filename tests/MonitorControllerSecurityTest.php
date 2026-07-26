<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Http\Controllers\MonitorController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class MonitorControllerSecurityTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $this->controller = (new ReflectionClass(MonitorController::class))->newInstanceWithoutConstructor();
    }

    public function test_sanitize_command_output_redacts_common_secret_shapes(): void
    {
        $method = $this->privateMethod('sanitizeCommandOutput');
        $output = $method->invoke(
            $this->controller,
            "password=SuperSecret token:abc123\nAuthorization: Bearer very-secret-token\n{\"private_key\":\"hidden\"}"
        );

        $this->assertStringContainsString('password=[redacted]', $output);
        $this->assertStringContainsString('token:[redacted]', $output);
        $this->assertStringContainsString('Authorization: Bearer [redacted]', $output);
        $this->assertStringContainsString('"private_key":"[redacted]"', $output);
        $this->assertStringNotContainsString('SuperSecret', $output);
        $this->assertStringNotContainsString('very-secret-token', $output);
        $this->assertStringNotContainsString('hidden', $output);
    }

    public function test_message_id_validation_matches_wrapper_allowlist(): void
    {
        $method = $this->privateMethod('isValidMessageId');

        $this->assertTrue($method->invoke($this->controller, '1abcDE-2fGhIJ-3k'));
        $this->assertFalse($method->invoke($this->controller, '../bad'));
        $this->assertFalse($method->invoke($this->controller, 'abc;rm-rf'));
        $this->assertFalse($method->invoke($this->controller, str_repeat('a', 33)));
    }

    public function test_log_keyword_is_stripped_and_limited_before_agent_call(): void
    {
        $method = $this->privateMethod('normalizeLogKeyword');
        $keyword = $method->invoke($this->controller, str_repeat('a', 200) . "\0\n\r\t ok");

        $this->assertSame(160, mb_strlen($keyword));
        $this->assertStringNotContainsString("\0", $keyword);
        $this->assertStringNotContainsString("\n", $keyword);
        $this->assertStringNotContainsString("\r", $keyword);
    }

    private function privateMethod(string $name): ReflectionMethod
    {
        return new ReflectionMethod(MonitorController::class, $name);
    }
}
