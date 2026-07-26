<?php

declare(strict_types=1);

namespace MailPanel\Contracts;

interface MailStoragePurger
{
    public function purgeMailbox(string $email): array;

    public function purgeDomain(string $domain): array;

    /**
     * @param array<int, string> $emails
     * @return array<int, array>
     */
    public function purgeMailboxes(array $emails): array;
}
