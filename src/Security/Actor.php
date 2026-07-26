<?php

declare(strict_types=1);

namespace MailPanel\Security;

final class Actor
{
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly ?int $tenantId = null,
        public readonly ?int $mailboxId = null
    ) {
    }

    public function isAdmin(): bool
    {
        return in_array($this->role, ['super_admin', 'tenant_admin', 'domain_admin', 'support_readonly'], true);
    }
}
