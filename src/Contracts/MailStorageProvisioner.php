<?php

declare(strict_types=1);

namespace MailPanel\Contracts;

interface MailStorageProvisioner
{
    public function provisionMailboxDefaults(string $email): array;

    /**
     * @param array<int, string> $emails
     * @return array<int, array>
     */
    public function provisionMailboxDefaultsBulk(array $emails): array;
}
