<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Repositories\Pdo\AdminRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;

final class DashboardService
{
    public function __construct(
        private readonly AdminRepository $adminRepository,
        private readonly QuotaUsageRepository $quotaUsageRepository
    )
    {
    }

    public function systemOverview(): array
    {
        return $this->adminRepository->systemStats();
    }

    public function tenantOverview(int $tenantId): array
    {
        $quota = $this->quotaUsageRepository->tenantTotals($tenantId);

        return [
            'tenant_id' => $tenantId,
            'quota_used_mb' => (int) ($quota['used_mb'] ?? 0),
            'mailboxes_with_usage' => (int) ($quota['mailbox_count'] ?? 0),
        ];
    }
}
