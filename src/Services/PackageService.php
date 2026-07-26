<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Support\Validator;

final class PackageService
{
    public function __construct(
        private readonly PackageRepository $packages,
        private readonly TenantRepository $tenants,
        private readonly TenantService $tenantService,
        private readonly Database $database,
        private readonly AuditLogService $auditLog,
        private readonly ?TenantPurgeRepository $tenantPurge = null
    ) {
    }

    public function list(): array
    {
        return $this->packages->all();
    }

    public function create(array $data): array
    {
        Validator::required($data, ['name', 'max_domains', 'max_mailboxes', 'max_total_quota_mb', 'default_mailbox_quota_mb', 'max_mailbox_quota_mb', 'max_message_size_mb', 'outbound_per_hour', 'outbound_per_day', 'retention_days']);
        $package = $this->packages->create($data + [
            'description' => $data['description'] ?? null,
            'max_aliases' => $data['max_aliases'] ?? 0,
            'max_forwarders' => $data['max_forwarders'] ?? 0,
            'enable_pop3' => (int) ($data['enable_pop3'] ?? 1),
            'enable_imap' => (int) ($data['enable_imap'] ?? 1),
            'enable_managesieve' => (int) ($data['enable_managesieve'] ?? 1),
            'enable_catchall' => (int) ($data['enable_catchall'] ?? 0),
            'enable_external_forwarding' => (int) ($data['enable_external_forwarding'] ?? 0),
            'spam_level_default' => $data['spam_level_default'] ?? 'normal',
            'quarantine_enabled' => (int) ($data['quarantine_enabled'] ?? 1),
            'antivirus_enabled' => (int) ($data['antivirus_enabled'] ?? 1),
            'dkim_enabled' => (int) ($data['dkim_enabled'] ?? 1),
            'custom_smtp_banner_allowed' => (int) ($data['custom_smtp_banner_allowed'] ?? 0),
        ]);

        $this->auditLog->log([
            'action' => 'package.created',
            'target_type' => 'package',
            'target_id' => $package['id'] ?? null,
            'new_values' => $package,
        ]);

        return $package;
    }
    public function find(int $id): ?array
    {
        return $this->packages->find($id);
    }

    public function update(int $id, array $data): void
    {
        \MailPanel\Support\Validator::required($data, ['name', 'max_domains', 'max_mailboxes', 'max_total_quota_mb', 'default_mailbox_quota_mb', 'max_mailbox_quota_mb', 'max_message_size_mb', 'outbound_per_hour', 'outbound_per_day', 'retention_days']);
        $payload = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'max_domains' => (int)$data['max_domains'],
            'max_mailboxes' => (int)$data['max_mailboxes'],
            'max_aliases' => (int)($data['max_aliases'] ?? 0),
            'max_forwarders' => (int)($data['max_forwarders'] ?? 0),
            'max_total_quota_mb' => (int)$data['max_total_quota_mb'],
            'default_mailbox_quota_mb' => (int)$data['default_mailbox_quota_mb'],
            'max_mailbox_quota_mb' => (int)$data['max_mailbox_quota_mb'],
            'max_message_size_mb' => (int)$data['max_message_size_mb'],
            'outbound_per_hour' => (int)$data['outbound_per_hour'],
            'outbound_per_day' => (int)$data['outbound_per_day'],
            'enable_pop3' => (int) ($data['enable_pop3'] ?? 1),
            'enable_imap' => (int) ($data['enable_imap'] ?? 1),
            'enable_managesieve' => (int) ($data['enable_managesieve'] ?? 1),
            'enable_catchall' => (int) ($data['enable_catchall'] ?? 0),
            'enable_external_forwarding' => (int) ($data['enable_external_forwarding'] ?? 0),
            'spam_level_default' => $data['spam_level_default'] ?? 'normal',
            'quarantine_enabled' => (int) ($data['quarantine_enabled'] ?? 1),
            'antivirus_enabled' => (int) ($data['antivirus_enabled'] ?? 1),
            'dkim_enabled' => (int) ($data['dkim_enabled'] ?? 1),
            'custom_smtp_banner_allowed' => (int) ($data['custom_smtp_banner_allowed'] ?? 0),
            'retention_days' => (int)$data['retention_days'],
        ];

        $connection = $this->database->connection();
        $connection->beginTransaction();

        try {
            $this->packages->update($id, $payload);
            $syncedTenants = $this->tenantService->syncPackageAssignment($id);
            $this->auditLog->log([
                'action' => 'package.updated',
                'target_type' => 'package',
                'target_id' => $id,
                'new_values' => $payload + ['synced_tenants' => $syncedTenants],
            ]);
            $connection->commit();
        } catch (\Throwable $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $assignedTenants = $this->tenants->countByPackage($id);
        if ($assignedTenants > 0) {
            throw new InvalidArgumentException(sprintf('Cannot delete package while it is assigned to %d tenant(s).', $assignedTenants));
        }

        if ($this->tenantPurge !== null) {
            $this->tenantPurge->purgePackage($id);
        } else {
            $this->packages->delete($id);
        }

        $this->auditLog->log(['action' => 'package.deleted', 'target_type' => 'package', 'target_id' => $id]);
    }
}
