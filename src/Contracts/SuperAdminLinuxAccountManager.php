<?php

declare(strict_types=1);

namespace MailPanel\Contracts;

interface SuperAdminLinuxAccountManager
{
    public function syncAccount(
        string $linuxUsername,
        bool $sshEnabled,
        bool $sshSudoEnabled,
        ?string $sshPublicKey = null,
        ?string $password = null
    ): void;

    public function revoke(string $linuxUsername): void;

    public function purge(string $linuxUsername): void;

    public function verifyPassword(string $linuxUsername, string $password): bool;
}
