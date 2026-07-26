<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

final class AdminRepository extends AbstractPdoRepository
{
    public function systemStats(): array
    {
        $quota = $this->fetchOne('SELECT COALESCE(SUM(used_mb), 0) AS used_mb FROM quota_usage');
        $latestConfig = $this->fetchAll('SELECT service, version, status, applied_at FROM config_versions ORDER BY id DESC LIMIT 10');

        return [
            'tenants' => (int) ($this->fetchOne('SELECT COUNT(*) AS total FROM tenants WHERE deleted_at IS NULL')['total'] ?? 0),
            'domains' => (int) ($this->fetchOne('SELECT COUNT(*) AS total FROM domains WHERE deleted_at IS NULL')['total'] ?? 0),
            'mailboxes' => (int) ($this->fetchOne('SELECT COUNT(*) AS total FROM mailboxes WHERE deleted_at IS NULL')['total'] ?? 0),
            'aliases' => (int) ($this->fetchOne('SELECT COUNT(*) AS total FROM aliases WHERE deleted_at IS NULL')['total'] ?? 0),
            'forwards' => (int) ($this->fetchOne('SELECT COUNT(*) AS total FROM forwards WHERE deleted_at IS NULL')['total'] ?? 0),
            'queue_size' => 0,
            'quota_used_mb' => (int) ($quota['used_mb'] ?? 0),
            'latest_config_versions' => $latestConfig,
        ];
    }
}
