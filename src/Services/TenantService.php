<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\MailStoragePurger;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Support\Validator;
use RuntimeException;
use Throwable;

final class TenantService
{
    public function __construct(
        private readonly TenantRepository $tenants,
        private readonly PackageRepository $packages,
        private readonly DomainRepository $domains,
        private readonly MailboxRepository $mailboxes,
        private readonly AliasRepository $aliases,
        private readonly ForwardRepository $forwards,
        private readonly AuditLogService $auditLog,
        private readonly ?TenantPurgeRepository $tenantPurge = null,
        private readonly ?MailStoragePurger $mailStorage = null,
        private readonly ?QuotaUsageRepository $quotaUsage = null,
        private readonly ?WebmailDomainConfigService $webmailDomains = null
    ) {
    }

    /**
     * @param int|null $tenantId Restricts the query to one tenant. Callers acting
     *                           for a tenant_admin MUST pass their tenant id so the
     *                           filter runs in SQL rather than in PHP.
     */
    public function list(?int $tenantId = null): array
    {
        $packages = $this->packageIndex();

        return array_map(
            fn (array $tenant): array => $this->decorateTenant($tenant, $packages[(int) ($tenant['package_id'] ?? 0)] ?? null),
            $this->tenants->all($tenantId)
        );
    }

    public function find(int $tenantId): ?array
    {
        $tenant = $this->tenants->find($tenantId);
        if ($tenant === null) {
            return null;
        }

        $packages = $this->packageIndex();

        return $this->decorateTenant($tenant, $packages[(int) ($tenant['package_id'] ?? 0)] ?? null);
    }

    public function create(array $data): array
    {
        Validator::required($data, ['name', 'slug', 'package_id']);
        $package = $this->packages->find((int) $data['package_id']);

        if ($package === null) {
            throw new InvalidArgumentException('Assigned package does not exist.');
        }

        $tenant = $this->tenants->create($this->buildTenantPayload($package, $data));

        $this->auditLog->log([
            'action' => 'tenant.created',
            'target_type' => 'tenant',
            'target_id' => $tenant['id'] ?? null,
            'new_values' => $this->find((int) ($tenant['id'] ?? 0)) ?? $tenant,
        ]);
        $this->recordLifecycleSnapshot((int) ($tenant['id'] ?? 0), null, $tenant, 'tenant.lifecycle_created');

        return $this->find((int) ($tenant['id'] ?? 0)) ?? $tenant;
    }

    public function update(int $id, array $data): void
    {
        Validator::required($data, ['name', 'slug', 'package_id']);

        $current = $this->tenants->find($id);
        if ($current === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        $package = $this->packages->find((int) $data['package_id']);
        if ($package === null) {
            throw new InvalidArgumentException('Assigned package does not exist.');
        }

        $payload = $this->buildTenantPayload($package, $data, $current);
        $this->assertUsageWithinLimits($id, $payload);

        $this->tenants->update($id, $payload);

        $this->auditLog->log([
            'action' => 'tenant.updated',
            'target_type' => 'tenant',
            'target_id' => $id,
            'old_values' => $this->decorateTenant($current, $this->packages->find((int) ($current['package_id'] ?? 0))),
            'new_values' => $this->find($id),
        ]);
        $this->recordLifecycleSnapshot($id, $current, $payload, 'tenant.lifecycle_updated');
    }

    public function delete(int $id): void
    {
        $tenant = $this->tenantPurge?->findIncludingDeleted($id) ?? $this->tenants->find($id);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        if ($this->tenantPurge !== null) {
            $mailboxEmails = $this->tenantPurge->mailboxEmailsForTenant($id);
            $domainNames = $this->tenantPurge->domainNamesForTenant($id);
            $result = $this->tenantPurge->purgeTenant($id);
            $storageResults = [];
            $storageError = null;
            if ($this->mailStorage !== null) {
                try {
                    $storageResults = $this->mailStorage->purgeMailboxes($mailboxEmails);
                    foreach (array_values(array_unique($domainNames)) as $domainName) {
                        if (trim((string) $domainName) !== '') {
                            $storageResults[] = $this->mailStorage->purgeDomain((string) $domainName);
                        }
                    }
                } catch (Throwable) {
                    $storageError = 'Mail storage purge failed; check service logs.';
                }
            }
            $webmailSyncError = $this->syncWebmailDomains();
            $this->auditLog->log([
                'action' => 'tenant.deleted',
                'target_type' => 'tenant',
                'target_id' => $id,
                'old_values' => [
                    'tenant' => [
                        'id' => (int) ($tenant['id'] ?? $id),
                        'name' => $tenant['name'] ?? null,
                        'slug' => $tenant['slug'] ?? null,
                        'status' => $tenant['status'] ?? null,
                    ],
                ],
                'new_values' => [
                    'purged' => true,
                    'deleted_rows' => $result['deleted'] ?? [],
                    'mail_storage_purged' => $this->mailStorage !== null,
                    'mail_storage_targets' => count($storageResults),
                    'mail_storage_error' => $storageError,
                    'webmail_sync_error' => $webmailSyncError,
                ],
            ]);

            if ($storageError !== null) {
                throw new RuntimeException('Tenant DB was deleted, but mail storage purge failed.');
            }

            return;
        }

        $this->tenants->delete($id);
        $this->tenants->softDeleteTenantAdmins($id);
        $webmailSyncError = $this->syncWebmailDomains();
        $this->auditLog->log([
            'action' => 'tenant.deleted',
            'target_type' => 'tenant',
            'target_id' => $id,
            'new_values' => ['webmail_sync_error' => $webmailSyncError],
        ]);
    }

    public function syncPackageAssignment(int $packageId): int
    {
        $package = $this->packages->find($packageId);
        if ($package === null) {
            throw new InvalidArgumentException('Assigned package does not exist.');
        }

        $updates = [];
        foreach ($this->tenants->allByPackage($packageId) as $tenant) {
            $payload = $this->buildTenantPayload($package, $tenant, $tenant);
            try {
                $this->assertUsageWithinLimits((int) ($tenant['id'] ?? 0), $payload);
            } catch (\InvalidArgumentException $e) {
                // Tenant usage exceeds new package limits, auto-convert to custom limits
                $tenant['is_custom_limits'] = 1;
                $payload = $this->buildTenantPayload($package, $tenant, $tenant);
            }
            $updates[] = [
                'tenant' => $tenant,
                'payload' => $payload,
            ];
        }

        foreach ($updates as $update) {
            $tenant = $update['tenant'];
            $payload = $update['payload'];
            $tenantId = (int) ($tenant['id'] ?? 0);

            $this->tenants->update($tenantId, $payload);
            $this->auditLog->log([
                'action' => 'tenant.package_synced',
                'target_type' => 'tenant',
                'target_id' => $tenantId,
                'old_values' => [
                    'package_id' => (int) ($tenant['package_id'] ?? 0),
                    'max_domains' => (int) ($tenant['max_domains'] ?? 0),
                    'max_mailboxes' => (int) ($tenant['max_mailboxes'] ?? 0),
                    'max_aliases' => (int) ($tenant['max_aliases'] ?? 0),
                    'max_forwarders' => (int) ($tenant['max_forwarders'] ?? 0),
                    'max_total_quota_mb' => (int) ($tenant['max_total_quota_mb'] ?? 0),
                ],
                'new_values' => [
                    'package_id' => (int) ($payload['package_id'] ?? 0),
                    'max_domains' => (int) ($payload['max_domains'] ?? 0),
                    'max_mailboxes' => (int) ($payload['max_mailboxes'] ?? 0),
                    'max_aliases' => (int) ($payload['max_aliases'] ?? 0),
                    'max_forwarders' => (int) ($payload['max_forwarders'] ?? 0),
                    'max_total_quota_mb' => (int) ($payload['max_total_quota_mb'] ?? 0),
                ],
            ]);
        }

        return count($updates);
    }

    public function getUsage(int $tenantId): array
    {
        return [
            'domains' => $this->domains->countByTenant($tenantId),
            'mailboxes' => $this->mailboxes->countByTenant($tenantId),
            'quota_mb' => $this->tenantUsedQuotaMb($tenantId),
            'assigned_quota_mb' => $this->mailboxes->totalQuotaForTenant($tenantId),
            'aliases' => $this->aliases->countByTenant($tenantId),
            'forwards' => $this->forwards->countByTenant($tenantId),
        ];
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

    private function buildTenantPayload(array $package, array $data, ?array $current = null): array
    {
        $extraDomains = $this->readNonNegativeInt($data, 'extra_domains', (int) ($current['extra_domains'] ?? 0));
        $extraMailboxes = $this->readNonNegativeInt($data, 'extra_mailboxes', (int) ($current['extra_mailboxes'] ?? 0));
        $extraAliases = $this->readNonNegativeInt($data, 'extra_aliases', (int) ($current['extra_aliases'] ?? 0));
        $extraForwarders = $this->readNonNegativeInt($data, 'extra_forwarders', (int) ($current['extra_forwarders'] ?? 0));
        $extraTotalQuotaMb = $this->readNonNegativeInt($data, 'extra_total_quota_mb', (int) ($current['extra_total_quota_mb'] ?? 0));
        $isCustom = $this->readBooleanFlag($data, 'is_custom_limits', !empty($current['is_custom_limits']));

        $calculatedDomains = (int) ($package['max_domains'] ?? 0) + $extraDomains;
        $calculatedMailboxes = (int) ($package['max_mailboxes'] ?? 0) + $extraMailboxes;
        $calculatedAliases = (int) ($package['max_aliases'] ?? 0) + $extraAliases;
        $calculatedForwarders = (int) ($package['max_forwarders'] ?? 0) + $extraForwarders;
        $calculatedTotalQuotaMb = (int) ($package['max_total_quota_mb'] ?? 0) + $extraTotalQuotaMb;
        $startsAt = TenantLifecyclePolicy::sqlTimestamp($data['starts_at'] ?? ($current['starts_at'] ?? null));
        $expiresAt = TenantLifecyclePolicy::sqlTimestamp($data['expires_at'] ?? ($current['expires_at'] ?? null), true);
        $graceUntil = TenantLifecyclePolicy::sqlTimestamp($data['grace_until'] ?? ($current['grace_until'] ?? null), true);
        TenantLifecyclePolicy::assertGraceWindow($expiresAt, $graceUntil);

        return [
            'name' => trim((string) ($data['name'] ?? $current['name'] ?? '')),
            'slug' => trim((string) ($data['slug'] ?? $current['slug'] ?? '')),
            'status' => (string) ($data['status'] ?? $current['status'] ?? 'active'),
            'billing_status' => TenantLifecyclePolicy::normalizeStatus((string) ($data['billing_status'] ?? $current['billing_status'] ?? 'active')),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'grace_until' => $graceUntil,
            'suspended_at' => TenantLifecyclePolicy::sqlTimestamp($data['suspended_at'] ?? ($current['suspended_at'] ?? null)),
            'terminated_at' => TenantLifecyclePolicy::sqlTimestamp($data['terminated_at'] ?? ($current['terminated_at'] ?? null)),
            'package_id' => (int) ($data['package_id'] ?? $current['package_id'] ?? 0),
            'is_custom_limits' => $isCustom ? 1 : 0,
            'extra_domains' => $extraDomains,
            'extra_mailboxes' => $extraMailboxes,
            'extra_aliases' => $extraAliases,
            'extra_forwarders' => $extraForwarders,
            'extra_total_quota_mb' => $extraTotalQuotaMb,
            'max_domains' => $isCustom
                ? $this->readNonNegativeInt($data, 'max_domains', (int) ($current['max_domains'] ?? $calculatedDomains))
                : $calculatedDomains,
            'max_mailboxes' => $isCustom
                ? $this->readNonNegativeInt($data, 'max_mailboxes', (int) ($current['max_mailboxes'] ?? $calculatedMailboxes))
                : $calculatedMailboxes,
            'max_aliases' => $isCustom
                ? $this->readNonNegativeInt($data, 'max_aliases', (int) ($current['max_aliases'] ?? $calculatedAliases))
                : $calculatedAliases,
            'max_forwarders' => $isCustom
                ? $this->readNonNegativeInt($data, 'max_forwarders', (int) ($current['max_forwarders'] ?? $calculatedForwarders))
                : $calculatedForwarders,
            'max_total_quota_mb' => $isCustom
                ? $this->readNonNegativeInt($data, 'max_total_quota_mb', (int) ($current['max_total_quota_mb'] ?? $calculatedTotalQuotaMb))
                : $calculatedTotalQuotaMb,
            'default_mailbox_quota_mb' => (int) ($package['default_mailbox_quota_mb'] ?? 0),
            'allow_catchall' => (int) ($package['enable_catchall'] ?? 0),
            'allow_external_forwarding' => (int) ($package['enable_external_forwarding'] ?? 0),
            'note' => $this->normalizeNote($data['note'] ?? ($current['note'] ?? null)),
        ];
    }

    private function assertUsageWithinLimits(int $tenantId, array $payload): void
    {
        $currentDomains = $this->domains->countByTenant($tenantId);
        if ($currentDomains > (int) ($payload['max_domains'] ?? 0)) {
            throw new InvalidArgumentException(sprintf('Không thể cập nhật: Tenant hiện có %d domains, vượt quá giới hạn %d của gói mới.', $currentDomains, (int) ($payload['max_domains'] ?? 0)));
        }

        $currentMailboxes = $this->mailboxes->countByTenant($tenantId);
        if ($currentMailboxes > (int) ($payload['max_mailboxes'] ?? 0)) {
            throw new InvalidArgumentException(sprintf('Không thể cập nhật: Tenant hiện có %d mailboxes, vượt quá giới hạn %d của gói mới.', $currentMailboxes, (int) ($payload['max_mailboxes'] ?? 0)));
        }

        $currentQuotaMb = $this->tenantUsedQuotaMb($tenantId);
        if ($currentQuotaMb > (int) ($payload['max_total_quota_mb'] ?? 0)) {
            throw new InvalidArgumentException(sprintf('Không thể cập nhật: Tổng quota hòm thư hiện tại là %d MB, vượt quá giới hạn %d MB của gói mới.', $currentQuotaMb, (int) ($payload['max_total_quota_mb'] ?? 0)));
        }

        $currentAliases = $this->aliases->countByTenant($tenantId);
        if ($currentAliases > (int) ($payload['max_aliases'] ?? 0)) {
            throw new InvalidArgumentException(sprintf('Không thể cập nhật: Tenant hiện có %d aliases, vượt quá giới hạn %d của gói mới.', $currentAliases, (int) ($payload['max_aliases'] ?? 0)));
        }

        $currentForwards = $this->forwards->countByTenant($tenantId);
        if ($currentForwards > (int) ($payload['max_forwarders'] ?? 0)) {
            throw new InvalidArgumentException(sprintf('Không thể cập nhật: Tenant hiện có %d forwarders, vượt quá giới hạn %d của gói mới.', $currentForwards, (int) ($payload['max_forwarders'] ?? 0)));
        }
    }

    private function tenantUsedQuotaMb(int $tenantId): int
    {
        return max(0, (int) (($this->quotaUsage?->tenantTotals($tenantId) ?? [])['used_mb'] ?? 0));
    }

    private function decorateTenant(array $tenant, ?array $package): array
    {
        $packageId = (int) ($tenant['package_id'] ?? 0);
        $packageName = $package['name'] ?? null;
        $extraDomains = (int) ($tenant['extra_domains'] ?? 0);
        $extraMailboxes = (int) ($tenant['extra_mailboxes'] ?? 0);
        $extraAliases = (int) ($tenant['extra_aliases'] ?? 0);
        $extraForwarders = (int) ($tenant['extra_forwarders'] ?? 0);
        $extraTotalQuotaMb = (int) ($tenant['extra_total_quota_mb'] ?? 0);

        $tenant['package_name'] = $packageName;
        $tenant['package_label'] = $packageName ?? sprintf('Package #%d', $packageId);
        $tenant['package_description'] = $package['description'] ?? null;
        $tenant['package_base_domains'] = (int) ($package['max_domains'] ?? 0);
        $tenant['package_base_mailboxes'] = (int) ($package['max_mailboxes'] ?? 0);
        $tenant['package_base_aliases'] = (int) ($package['max_aliases'] ?? 0);
        $tenant['package_base_forwarders'] = (int) ($package['max_forwarders'] ?? 0);
        $tenant['package_base_total_quota_mb'] = (int) ($package['max_total_quota_mb'] ?? 0);
        $tenant['package_base_default_mailbox_quota_mb'] = (int) ($package['default_mailbox_quota_mb'] ?? 0);
        $tenant['package_base_max_mailbox_quota_mb'] = (int) ($package['max_mailbox_quota_mb'] ?? 0);
        $tenant['has_extra_allocations'] = ($extraDomains + $extraMailboxes + $extraAliases + $extraForwarders + $extraTotalQuotaMb) > 0;
        $tenant['extra_allocations_summary'] = $this->buildExtraAllocationSummary($tenant);
        $tenant['package_assignment_mode'] = !empty($tenant['is_custom_limits'])
            ? 'custom'
            : (!empty($tenant['has_extra_allocations']) ? 'package_plus_extra' : 'package');
        $tenant['effective_billing_status'] = TenantLifecyclePolicy::effectiveStatus($tenant);
        $tenant['can_provision_mail_resources'] = TenantLifecyclePolicy::canProvision($tenant);
        $tenant['can_use_mail_services'] = TenantLifecyclePolicy::canUseMail($tenant);
        $tenant['days_until_expiry'] = $this->daysUntil($tenant['expires_at'] ?? null);
        $tenant['days_until_grace_end'] = $this->daysUntil($tenant['grace_until'] ?? null);

        return $tenant;
    }

    private function recordLifecycleSnapshot(int $tenantId, ?array $oldTenant, array $newTenant, string $eventType): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $oldStatus = $oldTenant === null ? null : TenantLifecyclePolicy::effectiveStatus($oldTenant);
        $newStatus = TenantLifecyclePolicy::effectiveStatus($newTenant);
        $lifecycleChanged = $oldTenant === null
            || $oldStatus !== $newStatus
            || (string) ($oldTenant['starts_at'] ?? '') !== (string) ($newTenant['starts_at'] ?? '')
            || (string) ($oldTenant['expires_at'] ?? '') !== (string) ($newTenant['expires_at'] ?? '')
            || (string) ($oldTenant['grace_until'] ?? '') !== (string) ($newTenant['grace_until'] ?? '');

        if (!$lifecycleChanged) {
            return;
        }

        try {
            $this->tenants->recordSubscription([
                'tenant_id' => $tenantId,
                'package_id' => (int) ($newTenant['package_id'] ?? 0),
                'billing_status' => (string) ($newTenant['billing_status'] ?? 'active'),
                'starts_at' => $newTenant['starts_at'] ?? null,
                'expires_at' => $newTenant['expires_at'] ?? null,
                'grace_until' => $newTenant['grace_until'] ?? null,
                'note' => $newTenant['note'] ?? null,
                'created_by' => null,
            ]);
            $this->tenants->recordLifecycleEvent([
                'tenant_id' => $tenantId,
                'event_type' => $eventType,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'starts_at' => $newTenant['starts_at'] ?? null,
                'expires_at' => $newTenant['expires_at'] ?? null,
                'grace_until' => $newTenant['grace_until'] ?? null,
                'note' => $newTenant['note'] ?? null,
                'actor_id' => null,
            ]);
        } catch (\Throwable) {
        }
    }

    private function daysUntil(mixed $value): ?int
    {
        $timestamp = TenantLifecyclePolicy::timestamp($value);
        if ($timestamp === null) {
            return null;
        }

        return (int) floor(($timestamp - time()) / 86400);
    }

    private function buildExtraAllocationSummary(array $tenant): string
    {
        $parts = [];

        if ((int) ($tenant['extra_domains'] ?? 0) > 0) {
            $parts[] = '+' . (int) $tenant['extra_domains'] . ' domains';
        }
        if ((int) ($tenant['extra_mailboxes'] ?? 0) > 0) {
            $parts[] = '+' . (int) $tenant['extra_mailboxes'] . ' mailboxes';
        }
        if ((int) ($tenant['extra_aliases'] ?? 0) > 0) {
            $parts[] = '+' . (int) $tenant['extra_aliases'] . ' aliases';
        }
        if ((int) ($tenant['extra_forwarders'] ?? 0) > 0) {
            $parts[] = '+' . (int) $tenant['extra_forwarders'] . ' forwards';
        }
        if ((int) ($tenant['extra_total_quota_mb'] ?? 0) > 0) {
            $parts[] = '+' . (int) $tenant['extra_total_quota_mb'] . ' MB quota';
        }

        return implode(', ', $parts);
    }

    private function packageIndex(): array
    {
        $index = [];

        foreach ($this->packages->all() as $package) {
            $index[(int) ($package['id'] ?? 0)] = $package;
        }

        return $index;
    }

    private function readNonNegativeInt(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return max(0, $default);
        }

        if (is_string($value) && trim($value) === '') {
            return max(0, $default);
        }

        return max(0, (int) $value);
    }

    private function readBooleanFlag(array $data, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeNote(mixed $value): ?string
    {
        $note = trim((string) ($value ?? ''));
        if ($note === '') {
            return null;
        }

        if (strlen($note) > 2000) {
            throw new InvalidArgumentException('Tenant note must be 2000 characters or fewer.');
        }

        return $note;
    }
}
