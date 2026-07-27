<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\MailStoragePurger;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Support\Validator;
use MailPanel\Services\AgentClientService;
use RuntimeException;
use Throwable;

final class DomainService
{
    public function __construct(
        private readonly DomainRepository $domains,
        private readonly MailboxRepository $mailboxes,
        private readonly TenantRepository $tenants,
        private readonly AuditLogService $auditLog,
        private readonly AgentClientService $agentClient,
        private readonly \MailPanel\Core\Database $database,
        private readonly ?TenantPurgeRepository $tenantPurge = null,
        private readonly ?MailStoragePurger $mailStorage = null,
        private readonly ?WebmailDomainConfigService $webmailDomains = null
    ) {
    }

    public function list(): array
    {
        return $this->domains->all();
    }

    public function find(int $domainId): ?array
    {
        return $this->domains->find($domainId);
    }

    public function create(array $data): array
    {
        Validator::required($data, ['tenant_id', 'domain']);
        $domainName = strtolower(trim($data['domain']));
        Validator::fqdn($domainName);

        if ($this->domains->findByName($domainName) !== null) {
            throw new InvalidArgumentException('Domain already exists.');
        }
        $tenantId = (int) $data['tenant_id'];
        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }
        TenantLifecyclePolicy::assertCanProvision($tenant);
        if ($this->domains->countByTenant($tenantId) >= (int) ($tenant['max_domains'] ?? 0)) {
            throw new InvalidArgumentException('Tenant has reached the maximum number of domains.');
        }

        $isPrimary = (int) ($data['is_primary'] ?? 0);
        $domain = $this->domains->create($data + [
            'domain' => $domainName,
            'status' => $data['status'] ?? 'pending_dns',
            'is_primary' => $isPrimary,
            'catchall_mailbox_id' => $data['catchall_mailbox_id'] ?? null,
            'inbound_enabled' => (int) ($data['inbound_enabled'] ?? 1),
            'outbound_enabled' => (int) ($data['outbound_enabled'] ?? 1),
            'dkim_enabled' => (int) ($data['dkim_enabled'] ?? 1),
            'dmarc_policy_expected' => $data['dmarc_policy_expected'] ?? 'quarantine',
        ]);

        if ($isPrimary === 1) {
            $this->domains->clearPrimaryForTenant((int) $domain['tenant_id']);
            $this->domains->setPrimary((int) $domain['id']);
            $domain = $this->domains->find((int) $domain['id']) ?? $domain;
        }

        $webmailSyncError = $this->syncWebmailDomains();
        $this->auditLog->log([
            'action' => 'domain.created',
            'target_type' => 'domain',
            'target_id' => $domain['id'] ?? null,
            'tenant_id' => $domain['tenant_id'] ?? null,
            'new_values' => $domain + ['webmail_sync_error' => $webmailSyncError],
        ]);

        return $domain;
    }

    public function setStatus(int $domainId, string $status): void
    {
        $domain = $this->domains->find($domainId);

        if ($domain === null) {
            throw new InvalidArgumentException('Domain not found.');
        }

        $allowed = ['pending_dns', 'active', 'suspended', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid domain status.');
        }

        $this->domains->updateStatus($domainId, $status);
        $this->auditLog->log([
            'action' => 'domain.status_updated',
            'target_type' => 'domain',
            'target_id' => $domainId,
            'tenant_id' => $domain['tenant_id'] ?? null,
            'new_values' => ['status' => $status],
        ]);
    }

    public function setPrimary(int $domainId): void
    {
        $domain = $this->domains->find($domainId);

        if ($domain === null) {
            throw new InvalidArgumentException('Domain not found.');
        }

        $this->domains->clearPrimaryForTenant((int) $domain['tenant_id']);
        $this->domains->setPrimary($domainId);
        $this->auditLog->log([
            'action' => 'domain.primary_updated',
            'target_type' => 'domain',
            'target_id' => $domainId,
            'tenant_id' => $domain['tenant_id'] ?? null,
            'new_values' => ['is_primary' => 1],
        ]);
    }

    public function delete(int $domainId): void
    {
        $domain = $this->domains->find($domainId);

        if ($domain === null) {
            throw new InvalidArgumentException('Domain not found.');
        }

        if ($this->mailboxes->countByDomain($domainId) > 0) {
            throw new InvalidArgumentException('Cannot delete domain while mailboxes still exist.');
        }

        $storageResult = null;
        if ($this->tenantPurge !== null) {
            $this->tenantPurge->purgeDomain($domainId);
        } else {
            $this->domains->softDelete($domainId);
        }

        $storageError = null;
        if ($this->mailStorage !== null) {
            try {
                $storageResult = $this->mailStorage->purgeDomain((string) $domain['domain']);
            } catch (Throwable) {
                $storageError = 'Mail storage purge failed; check service logs.';
            }
        }

        $webmailSyncError = $this->syncWebmailDomains();
        $this->auditLog->log([
            'action' => 'domain.deleted',
            'target_type' => 'domain',
            'target_id' => $domainId,
            'tenant_id' => $domain['tenant_id'] ?? null,
            'new_values' => [
                'mail_storage_purged' => $storageResult !== null,
                'mail_storage_error' => $storageError,
                'webmail_sync_error' => $webmailSyncError,
            ],
        ]);

        if ($storageError !== null) {
            throw new RuntimeException('Domain DB was deleted, but mail storage purge failed.');
        }
    }

    public function renameDomain(int $domainId, string $newDomainName): void
    {
        $domain = $this->domains->find($domainId);
        if ($domain === null) {
            throw new InvalidArgumentException('Domain not found.');
        }

        $newDomainName = strtolower(trim($newDomainName));
        Validator::fqdn($newDomainName);

        if ($domain['domain'] === $newDomainName) {
            return;
        }

        if ($this->domains->findByName($newDomainName) !== null) {
            throw new InvalidArgumentException('Domain already exists.');
        }

        $oldDomainName = $domain['domain'];

        $db = $this->database->connection();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE domains SET domain = :new_domain, updated_at = NOW() WHERE id = :domain_id')
                ->execute(['new_domain' => $newDomainName, 'domain_id' => $domainId]);

            $db->prepare("UPDATE mailboxes SET email = CONCAT(local_part, '@', :new_domain), updated_at = NOW() WHERE domain_id = :domain_id")
                ->execute(['new_domain' => $newDomainName, 'domain_id' => $domainId]);

            $db->prepare("UPDATE aliases SET source_address = REPLACE(source_address, :old_at_domain, :new_at_domain), updated_at = NOW() WHERE domain_id = :domain_id")
                ->execute(['old_at_domain' => '@' . $oldDomainName, 'new_at_domain' => '@' . $newDomainName, 'domain_id' => $domainId]);

            $db->prepare("UPDATE forwards SET source_address = REPLACE(source_address, :old_at_domain, :new_at_domain), destination_address = REPLACE(destination_address, :old_at_domain, :new_at_domain), updated_at = NOW() WHERE domain_id = :domain_id")
                ->execute(['old_at_domain' => '@' . $oldDomainName, 'new_at_domain' => '@' . $newDomainName, 'domain_id' => $domainId]);

            $db->prepare("UPDATE users SET email = REPLACE(email, :old_at_domain, :new_at_domain), updated_at = NOW() WHERE tenant_id = :tenant_id AND email LIKE :like_old_domain")
                ->execute([
                    'old_at_domain' => '@' . $oldDomainName,
                    'new_at_domain' => '@' . $newDomainName,
                    'tenant_id' => $domain['tenant_id'],
                    'like_old_domain' => '%@' . $oldDomainName
                ]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            throw $e;
        }

        $storageRenameError = null;
        try {
            $agentRes = $this->agentClient->renameDomain([
                'action' => 'rename',
                'old_domain' => $oldDomainName,
                'new_domain' => $newDomainName
            ]);

            if ((int) ($agentRes['result']['returncode'] ?? 1) !== 0) {
                $storageRenameError = 'Mail storage rename failed; check service logs.';
            }
        } catch (Throwable) {
            $storageRenameError = 'Mail storage rename failed; check service logs.';
        }

        $webmailSyncError = $this->syncWebmailDomains();
        $this->auditLog->log([
            'action' => 'domain.renamed',
            'target_type' => 'domain',
            'target_id' => $domainId,
            'tenant_id' => $domain['tenant_id'] ?? null,
            'new_values' => [
                'domain' => $newDomainName,
                'webmail_sync_error' => $webmailSyncError,
                'mail_storage_rename_error' => $storageRenameError,
            ],
            'old_values' => ['domain' => $oldDomainName],
        ]);

        if ($storageRenameError !== null) {
            // The DB now points at the new name while /var/vmail still holds the old
            // directory: surfacing this is required, otherwise mail silently disappears.
            throw new RuntimeException('Domain was renamed in the database, but the mail storage directory rename failed.');
        }
    }

    private function syncWebmailDomains(): ?string
    {
        if ($this->webmailDomains === null) {
            return null;
        }

        try {
            $this->webmailDomains->syncManagedDomains($this->domains->all());

            return null;
        } catch (Throwable) {
            return 'Webmail domain sync failed; check service logs.';
        }
    }
}
