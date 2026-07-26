<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Services\SystemCommandService;
use PHPUnit\Framework\TestCase;

final class SystemCommandServiceTest extends TestCase
{
    public function test_builds_allowlisted_command(): void
    {
        $service = new SystemCommandService();
        $plan = $service->build('service.reload', ['service' => 'dovecot']);

        $this->assertSame(['/usr/bin/systemctl', 'reload', 'dovecot'], $plan['command']);
        $this->assertTrue($plan['dry_run']);
    }

    public function test_rejects_non_allowlisted_command(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SystemCommandService())->build('bash.exec');
    }

    public function test_rejects_unsafe_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SystemCommandService())->build('service.reload', ['service' => 'dovecot;rm']);
    }
}
