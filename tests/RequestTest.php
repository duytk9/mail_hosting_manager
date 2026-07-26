<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_header_reads_explicit_headers_case_insensitively(): void
    {
        $request = new Request(
            'GET',
            '/',
            [],
            [],
            [],
            ['x-csrf-token' => 'csrf-value', 'Authorization' => 'Bearer token-value']
        );

        $this->assertSame('csrf-value', $request->header('X-CSRF-Token'));
        $this->assertSame('Bearer token-value', $request->header('authorization'));
    }

    public function test_server_header_takes_precedence_over_explicit_headers(): void
    {
        $request = new Request(
            'GET',
            '/',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer server-token'],
            ['Authorization' => 'Bearer explicit-token']
        );

        $this->assertSame('Bearer server-token', $request->header('Authorization'));
    }
}
