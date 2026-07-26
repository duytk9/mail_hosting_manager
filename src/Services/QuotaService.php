<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;

final class QuotaService
{
    public function __construct(
        private readonly QuotaUsageRepository $quotaUsage,
        private readonly MailboxRepository $mailboxes,
        private readonly AuditLogService $auditLog
    ) {
    }

    public function recordUsage(int $mailboxId, int $usedMb, string $source = 'agent'): array
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        if (!in_array($source, ['agent', 'system'], true)) {
            throw new InvalidArgumentException('Invalid quota usage source.');
        }

        $usedMb = max(0, $usedMb);

        $this->quotaUsage->upsert((int) $mailbox['tenant_id'], $mailboxId, $usedMb);
        $entry = $this->quotaUsage->findByMailbox($mailboxId) ?? [];

        $this->auditLog->log([
            'tenant_id' => $mailbox['tenant_id'],
            'action' => 'quota.updated',
            'target_type' => 'quota_usage',
            'target_id' => $entry['id'] ?? null,
            'new_values' => $entry + ['source' => $source],
        ]);

        return $entry;
    }

    public function mailboxQuota(int $mailboxId): array
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        $usage = $this->quotaUsage->findByMailbox($mailboxId) ?? ['used_mb' => 0];

        return [
            'mailbox_id' => $mailboxId,
            'email' => $mailbox['email'],
            'used_mb' => (int) ($usage['used_mb'] ?? 0),
            'quota_mb' => (int) $mailbox['quota_mb'],
            'percent' => (int) floor(((int) ($usage['used_mb'] ?? 0) / max((int) $mailbox['quota_mb'], 1)) * 100),
        ];
    }
}
