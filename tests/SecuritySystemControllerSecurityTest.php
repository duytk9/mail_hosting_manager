<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Http\Controllers\SecuritySystemController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

final class SecuritySystemControllerSecurityTest extends TestCase
{
    private object $controller;

    protected function setUp(): void
    {
        $this->controller = (new ReflectionClass(SecuritySystemController::class))->newInstanceWithoutConstructor();
    }

    public function test_fail2ban_jail_and_ip_validation_match_wrapper_allowlist(): void
    {
        $jail = $this->privateMethod('isValidFail2banJail');
        $ip = $this->privateMethod('isValidIpAddress');

        $this->assertTrue($jail->invoke($this->controller, 'roundcube-auth'));
        $this->assertTrue($jail->invoke($this->controller, 'dovecot_auth:submission'));
        $this->assertFalse($jail->invoke($this->controller, '../sshd'));
        $this->assertFalse($jail->invoke($this->controller, 'ssh;reboot'));

        $this->assertTrue($ip->invoke($this->controller, '203.0.113.10'));
        $this->assertTrue($ip->invoke($this->controller, '2001:db8::1'));
        $this->assertFalse($ip->invoke($this->controller, '203.0.113.10/24'));
        $this->assertFalse($ip->invoke($this->controller, 'bad-ip'));
    }

    public function test_rspamd_score_validation_rejects_non_numeric_and_out_of_range_values(): void
    {
        $score = $this->privateMethod('normalizeRspamdScore');

        $this->assertSame(6.25, $score->invoke($this->controller, '6.251', 'add_header'));

        $this->expectException(InvalidArgumentException::class);
        $score->invoke($this->controller, 'NaN', 'reject');
    }

    public function test_rspamd_map_list_normalizes_and_rejects_invalid_entries(): void
    {
        $maps = $this->privateMethod('normalizeRspamdMapList');

        $this->assertSame(
            "203.0.113.10\n2001:db8::/64",
            $maps->invoke($this->controller, " 203.0.113.10 \n2001:db8::/64\n203.0.113.10", 'ip')
        );
        $this->assertSame(
            "user@example.test\n@example.test",
            $maps->invoke($this->controller, " User@Example.test \n@Example.test", 'sender')
        );

        $this->expectException(InvalidArgumentException::class);
        $maps->invoke($this->controller, "user@example.test\nbad entry; action=accept", 'recipient');
    }

    public function test_system_output_and_error_are_redacted_before_rendering(): void
    {
        $sanitize = $this->privateMethod('sanitizeSystemOutput');
        $safeError = $this->privateMethod('safeSystemError');

        $output = $sanitize->invoke(
            $this->controller,
            "password=SuperSecret token:abc123 Authorization: Bearer hidden-token {\"private_key\":\"hidden\"}"
        );

        $this->assertStringContainsString('password=[redacted]', $output);
        $this->assertStringContainsString('token:[redacted]', $output);
        $this->assertStringContainsString('Authorization: Bearer [redacted]', $output);
        $this->assertStringContainsString('"private_key":"[redacted]"', $output);
        $this->assertStringNotContainsString('SuperSecret', $output);
        $this->assertStringNotContainsString('hidden-token', $output);
        $this->assertStringNotContainsString('"hidden"', $output);

        $error = $safeError->invoke($this->controller, new RuntimeException('db_password=secret'));
        $this->assertSame('db_password=[redacted]', $error);
    }

    public function test_fail2ban_status_parser_filters_invalid_jails_and_ips(): void
    {
        $parser = $this->privateMethod('parseFail2banStatus');
        $parsed = $parser->invoke(
            $this->controller,
            "Status for the jail: dovecot-auth\nBanned IP list: 203.0.113.10 bad-ip 2001:db8::1\nStatus for the jail: ../bad\nBanned IP list: 198.51.100.2"
        );

        $this->assertArrayHasKey('dovecot-auth', $parsed['jails']);
        $this->assertSame(['203.0.113.10', '2001:db8::1'], $parsed['jails']['dovecot-auth']);
        $this->assertArrayNotHasKey('../bad', $parsed['jails']);
    }

    private function privateMethod(string $name): ReflectionMethod
    {
        return new ReflectionMethod(SecuritySystemController::class, $name);
    }
}
