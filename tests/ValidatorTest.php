<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Support\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function test_accepts_valid_fqdn(): void
    {
        Validator::fqdn('example.test');
        $this->addToAssertionCount(1);
    }

    public function test_rejects_invalid_fqdn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::fqdn('bad_domain');
    }

    public function test_rejects_invalid_local_part(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Validator::localPart('bad part');
    }

    public function test_accepts_safe_linux_username(): void
    {
        Validator::linuxUsername('tenant-admin_01');
        $this->addToAssertionCount(1);
    }

    public function test_rejects_unsupported_linux_username_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported characters');

        Validator::linuxUsername('Bad User');
    }

    public function test_rejects_reserved_linux_usernames(): void
    {
        foreach (['root', 'www-data', 'vmail', 'mailpanel-agent'] as $username) {
            try {
                Validator::linuxUsername($username);
                $this->fail('Reserved Linux username was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('reserved', $exception->getMessage());
            }
        }
    }
}
