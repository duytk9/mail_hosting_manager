<?php

declare(strict_types=1);

namespace MailPanel\Contracts;

interface MailboxPasswordManager
{
    public function changePassword(int $mailboxId, string $newPassword): void;

    public function changePasswordWithCurrent(int $mailboxId, string $currentPassword, string $newPassword): void;
}
