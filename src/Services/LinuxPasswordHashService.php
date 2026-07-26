<?php

declare(strict_types=1);

namespace MailPanel\Services;

use RuntimeException;

final class LinuxPasswordHashService
{
    public function hash(string $password): string
    {
        $salt = sprintf('$6$rounds=656000$%s$', bin2hex(random_bytes(8)));
        $hash = crypt($password, $salt);

        if (!is_string($hash) || $hash === '' || $hash === '*0' || $hash === '*1') {
            throw new RuntimeException('Unable to generate Linux password hash.');
        }

        return $hash;
    }
}
