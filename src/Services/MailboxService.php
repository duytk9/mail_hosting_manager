<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\MailStorageProvisioner;
use MailPanel\Contracts\MailStoragePurger;
use MailPanel\Contracts\MailboxPasswordManager;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Support\Validator;
use RuntimeException;
use Throwable;

final class MailboxService implements MailboxPasswordManager
{
    private const RESERVED_LOCAL_PARTS = ['root', 'postmaster', 'abuse'];

    public function __construct(
        private readonly MailboxRepository $mailboxes,
        private readonly DomainRepository $domains,
        private readonly TenantRepository $tenants,
        private readonly PackageRepository $packages,
        private readonly AuditLogService $auditLog,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly PasswordHashingService $passwordHasher,
        private readonly MailboxPasswordHistoryRepository $passwordHistory,
        private readonly ?TenantPurgeRepository $tenantPurge = null,
        private readonly ?MailStoragePurger $mailStorage = null,
        private readonly ?QuotaUsageRepository $quotaUsage = null,
        private readonly ?MailStorageProvisioner $mailStorageProvisioner = null,
        private readonly ?WebmailUserStorageService $webmailUserStorage = null,
    ) {
    }

    public function list(): array
    {
        return $this->decorateMailboxUsage($this->mailboxes->all());
    }

    public function find(int $mailboxId): ?array
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        return $mailbox === null ? null : $this->decorateMailboxUsage([$mailbox])[0];
    }

    private function decorateMailboxUsage(array $mailboxes): array
    {
        $mailboxIds = array_map(static fn (array $mailbox): int => (int) ($mailbox['id'] ?? 0), $mailboxes);
        $usageMap = $this->quotaUsage?->mailboxUsageMap($mailboxIds) ?? [];

        return array_map(static function (array $mailbox) use ($usageMap): array {
            $quotaMb = max(0, (int) ($mailbox['quota_mb'] ?? 0));
            $usedMb = max(0, (int) ($usageMap[(int) ($mailbox['id'] ?? 0)] ?? 0));

            $mailbox['used_mb'] = $usedMb;
            $mailbox['usage_percent'] = (int) floor(($usedMb / max($quotaMb, 1)) * 100);

            return $mailbox;
        }, $mailboxes);
    }

    public function resolveTenantIdForCreate(array $data): int
    {
        $domainId = (int) ($data['domain_id'] ?? 0);
        $domain = $this->domains->find($domainId);

        if ($domain === null) {
            throw new InvalidArgumentException('Domain not found.');
        }

        return (int) $domain['tenant_id'];
    }

    public function create(array $data): array
    {
        Validator::required($data, ['domain_id', 'local_part', 'password', 'display_name']);
        $localPart = strtolower(trim($data['local_part']));
        Validator::localPart($localPart);

        if (in_array($localPart, self::RESERVED_LOCAL_PARTS, true) && !($data['allow_reserved'] ?? false)) {
            throw new InvalidArgumentException('local_part is reserved.');
        }

        $domain = $this->domains->find((int) $data['domain_id']);
        $tenant = $domain !== null ? $this->tenants->find((int) $domain['tenant_id']) : null;

        if ($tenant === null || $domain === null) {
            throw new InvalidArgumentException('Tenant or domain not found.');
        }
        TenantLifecyclePolicy::assertCanProvision($tenant);

        if (isset($data['tenant_id']) && (int) $data['tenant_id'] !== (int) $tenant['id']) {
            throw new InvalidArgumentException('Selected domain does not belong to the chosen tenant.');
        }

        $package = $this->packages->find((int) $tenant['package_id']);
        $quotaProfile = $this->tenantQuotaProfile((int) $tenant['id'], $tenant, $package);
        $quotaMb = (int) ($data['quota_mb'] ?? ($quotaProfile['recommended_quota_mb'] ?? $tenant['default_mailbox_quota_mb']));
        $email = sprintf('%s@%s', $localPart, $domain['domain']);

        if ($this->mailboxes->findByEmail($email) !== null) {
            throw new InvalidArgumentException('Mailbox already exists.');
        }

        if ($this->mailboxes->countByTenant((int) $tenant['id']) >= (int) ($tenant['max_mailboxes'] ?? 0)) {
            throw new InvalidArgumentException('Tenant has reached the maximum number of mailboxes.');
        }

        if ($quotaMb <= 0) {
            throw new InvalidArgumentException('Mailbox quota must be greater than 0 MB.');
        }

        $this->assertQuotaAllowed($quotaMb, $quotaProfile);

        $this->passwordPolicy->assertStrong((string) $data['password']);
        $passwordHash = $this->passwordHasher->hash((string) $data['password']);

        $mailbox = $this->mailboxes->create([
            'tenant_id' => (int) $tenant['id'],
            'domain_id' => (int) $domain['id'],
            'local_part' => $localPart,
            'email' => $email,
            'password_hash' => $passwordHash,
            'display_name' => trim((string) $data['display_name']),
            'quota_mb' => $quotaMb,
            'status' => $data['status'] ?? 'active',
            'force_password_change' => (int) ($data['force_password_change'] ?? 0),
            'imap_enabled' => (int) ($data['imap_enabled'] ?? 1),
            // $package can be null when the tenant's package row is missing; fall back to
            // the service defaults instead of silently disabling POP3/ManageSieve.
            'pop3_enabled' => (int) ($data['pop3_enabled'] ?? $package['enable_pop3'] ?? 1),
            'smtp_enabled' => (int) ($data['smtp_enabled'] ?? 1),
            'managesieve_enabled' => (int) ($data['managesieve_enabled'] ?? $package['enable_managesieve'] ?? 1),
        ]);
        $this->passwordHistory->store((int) $mailbox['id'], (int) $tenant['id'], $passwordHash);
        $storageProvisionError = null;
        if ($this->mailStorageProvisioner !== null) {
            try {
                $this->mailStorageProvisioner->provisionMailboxDefaults($email);
            } catch (Throwable) {
                $storageProvisionError = 'Mail storage bootstrap failed; check service logs.';
                if ($this->tenantPurge !== null) {
                    $this->tenantPurge->purgeMailbox((int) $mailbox['id']);
                } else {
                    $this->mailboxes->softDelete((int) $mailbox['id']);
                }
            }
        }

        $webmailBootstrapError = null;
        $webmailBootstrapResult = null;
        if ($this->webmailUserStorage !== null) {
            try {
                $webmailBootstrapResult = $this->webmailUserStorage->bootstrapMailbox(
                    $email,
                    (string) ($mailbox['display_name'] ?? $data['display_name'] ?? '')
                );
            } catch (Throwable) {
                $webmailBootstrapError = 'Webmail mailbox bootstrap failed; check service logs.';
            }
        }

        $this->auditLog->log([
            'action' => 'mailbox.created',
            'target_type' => 'mailbox',
            'target_id' => $mailbox['id'] ?? null,
            'tenant_id' => $mailbox['tenant_id'] ?? null,
            'new_values' => array_diff_key($mailbox, ['password_hash' => true]) + [
                'mail_storage_bootstrapped' => $storageProvisionError === null,
                'mail_storage_bootstrap_error' => $storageProvisionError,
                'webmail_user_bootstrapped' => $webmailBootstrapError === null && $webmailBootstrapResult !== null,
                'webmail_user_bootstrap_error' => $webmailBootstrapError,
            ],
        ]);

        if ($storageProvisionError !== null) {
            throw new RuntimeException('Mailbox DB was created, but mail storage bootstrap failed.');
        }

        return $mailbox;
    }

    public function tenantQuotaProfile(int $tenantId, ?array $tenant = null, ?array $package = null): array
    {
        $tenant ??= $this->tenants->find($tenantId);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        $package ??= $this->packages->find((int) ($tenant['package_id'] ?? 0));

        $allocatedQuotaMb = max(0, (int) ($tenant['max_total_quota_mb'] ?? 0));
        $assignedQuotaMb = max(0, $this->mailboxes->totalQuotaForTenant($tenantId));
        $actualUsedQuotaMb = max(0, (int) (($this->quotaUsage?->tenantTotals($tenantId) ?? [])['used_mb'] ?? 0));
        $remainingQuotaMb = max(0, $allocatedQuotaMb - $actualUsedQuotaMb);
        $maxMailboxes = max(0, (int) ($tenant['max_mailboxes'] ?? 0));
        $currentMailboxes = max(0, $this->mailboxes->countByTenant($tenantId));
        $remainingMailboxSlots = max(0, $maxMailboxes - $currentMailboxes);
        $defaultMailboxQuotaMb = max(
            1,
            (int) ($tenant['default_mailbox_quota_mb'] ?? ($package['default_mailbox_quota_mb'] ?? 1024))
        );
        $packageMaxMailboxQuotaMb = (int) ($package['max_mailbox_quota_mb'] ?? 0);
        $fallbackMaxMailboxQuotaMb = $allocatedQuotaMb > 0 ? $allocatedQuotaMb : $defaultMailboxQuotaMb;
        $maxSingleMailboxQuotaMb = max(
            1,
            $packageMaxMailboxQuotaMb > 0 ? $packageMaxMailboxQuotaMb : $fallbackMaxMailboxQuotaMb
        );
        $recommendedQuotaMb = min($defaultMailboxQuotaMb, $maxSingleMailboxQuotaMb);

        return [
            'tenant_id' => $tenantId,
            'tenant_name' => (string) ($tenant['name'] ?? ('Tenant #' . $tenantId)),
            'allocated_quota_mb' => $allocatedQuotaMb,
            'assigned_quota_mb' => $assignedQuotaMb,
            'assigned_overage_mb' => max(0, $assignedQuotaMb - $allocatedQuotaMb),
            'used_quota_mb' => $actualUsedQuotaMb,
            'remaining_quota_mb' => $remainingQuotaMb,
            'max_single_mailbox_quota_mb' => $maxSingleMailboxQuotaMb,
            'default_mailbox_quota_mb' => $defaultMailboxQuotaMb,
            'package_soft_mailbox_quota_mb' => $maxSingleMailboxQuotaMb,
            'recommended_quota_mb' => max(1, $recommendedQuotaMb),
            'max_mailboxes' => $maxMailboxes,
            'current_mailboxes' => $currentMailboxes,
            'remaining_mailbox_slots' => $remainingMailboxSlots,
        ];
    }

    public function updateQuota(int $mailboxId, int $quotaMb): array
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        $tenant = $this->tenants->find((int) $mailbox['tenant_id']);
        if ($tenant === null) {
            throw new InvalidArgumentException('Tenant not found.');
        }

        $package = $this->packages->find((int) ($tenant['package_id'] ?? 0));
        $quotaProfile = $this->tenantQuotaProfile((int) $tenant['id'], $tenant, $package);
        $oldQuotaMb = (int) ($mailbox['quota_mb'] ?? 0);
        $this->assertQuotaAllowed($quotaMb, $quotaProfile, $oldQuotaMb);

        $this->mailboxes->updateQuota($mailboxId, $quotaMb);
        $updated = $this->mailboxes->find($mailboxId) ?? ($mailbox + ['quota_mb' => $quotaMb]);

        $this->auditLog->log([
            'action' => 'mailbox.quota_updated',
            'target_type' => 'mailbox',
            'target_id' => $mailboxId,
            'tenant_id' => $mailbox['tenant_id'] ?? null,
            'old_values' => ['quota_mb' => $oldQuotaMb],
            'new_values' => ['quota_mb' => $quotaMb],
        ]);

        return $updated;
    }

    public function changePassword(int $mailboxId, string $newPassword): void
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        $this->passwordPolicy->assertStrong($newPassword);
        $this->passwordPolicy->assertNotReused(
            $newPassword,
            $this->passwordHistory->recentHashesForMailbox($mailboxId, $this->passwordPolicy->historyCount())
        );

        $hash = $this->passwordHasher->hash($newPassword);
        $this->mailboxes->updatePassword($mailboxId, $hash);
        $this->passwordHistory->store($mailboxId, (int) $mailbox['tenant_id'], $hash);
        $this->auditLog->log([
            'action' => 'mailbox.password_changed',
            'target_type' => 'mailbox',
            'target_id' => $mailboxId,
            'tenant_id' => $mailbox['tenant_id'] ?? null,
        ]);
    }

    public function changePasswordWithCurrent(int $mailboxId, string $currentPassword, string $newPassword): void
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        if ($currentPassword === '') {
            throw new InvalidArgumentException('Current password is required.');
        }

        if (!$this->passwordHasher->verify($currentPassword, (string) ($mailbox['password_hash'] ?? ''))) {
            throw new InvalidArgumentException('Current password is invalid.');
        }

        if ($this->passwordHasher->verify($newPassword, (string) ($mailbox['password_hash'] ?? ''))) {
            throw new InvalidArgumentException('New password must be different from the current password.');
        }

        $this->changePassword($mailboxId, $newPassword);
    }

    public function setStatus(int $mailboxId, string $status): void
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        $allowed = ['active', 'suspended', 'disabled'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid mailbox status.');
        }

        $this->mailboxes->updateStatus($mailboxId, $status);
        $this->auditLog->log([
            'action' => 'mailbox.status_updated',
            'target_type' => 'mailbox',
            'target_id' => $mailboxId,
            'tenant_id' => $mailbox['tenant_id'] ?? null,
            'new_values' => ['status' => $status],
        ]);
    }

    private function assertQuotaAllowed(int $quotaMb, array $quotaProfile, int $oldQuotaMb = 0): void
    {
        if ($quotaMb <= 0) {
            throw new InvalidArgumentException('Mailbox quota must be greater than 0 MB.');
        }

        $maxSingleMailboxQuotaMb = max(1, (int) ($quotaProfile['max_single_mailbox_quota_mb'] ?? 0));
        if ($quotaMb > $maxSingleMailboxQuotaMb) {
            throw new InvalidArgumentException(sprintf(
                'Mailbox quota exceeds the package per-account limit of %d MB.',
                $maxSingleMailboxQuotaMb
            ));
        }

        $allocatedQuotaMb = (int) ($quotaProfile['allocated_quota_mb'] ?? 0);
        $assignedQuotaMb = (int) ($quotaProfile['assigned_quota_mb'] ?? 0);
        $newAssignedTotal = max(0, $assignedQuotaMb - $oldQuotaMb + $quotaMb);

        if ($allocatedQuotaMb > 0 && $newAssignedTotal > $allocatedQuotaMb) {
            $this->auditLog->log([
                'action' => 'mailbox.quota_overassigned',
                'target_type' => 'tenant',
                'target_id' => $quotaProfile['tenant_id'] ?? null,
                'tenant_id' => $quotaProfile['tenant_id'] ?? null,
                'new_values' => [
                    'requested_quota_mb' => $quotaMb,
                    'assigned_quota_mb' => $newAssignedTotal,
                    'allocated_quota_mb' => $allocatedQuotaMb,
                ],
            ]);
        }
    }

    public function delete(int $mailboxId): void
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if ($mailbox === null) {
            throw new InvalidArgumentException('Mailbox not found.');
        }

        $storageResult = null;
        if ($this->tenantPurge !== null) {
            $this->tenantPurge->purgeMailbox($mailboxId);
        } else {
            $this->mailboxes->softDelete($mailboxId);
        }

        $storageError = null;
        if ($this->mailStorage !== null) {
            try {
                $storageResult = $this->mailStorage->purgeMailbox((string) $mailbox['email']);
            } catch (Throwable) {
                $storageError = 'Mail storage purge failed; check service logs.';
            }
        }

        $webmailStorageError = null;
        $webmailStorageResult = null;
        if ($this->webmailUserStorage !== null) {
            try {
                $webmailStorageResult = $this->webmailUserStorage->purgeMailbox((string) $mailbox['email']);
            } catch (Throwable) {
                $webmailStorageError = 'Webmail mailbox storage purge failed; check service logs.';
            }
        }

        $this->auditLog->log([
            'action' => 'mailbox.deleted',
            'target_type' => 'mailbox',
            'target_id' => $mailboxId,
            'tenant_id' => $mailbox['tenant_id'] ?? null,
            'new_values' => [
                'mail_storage_purged' => $storageResult !== null,
                'mail_storage_error' => $storageError,
                'webmail_user_storage_purged' => $webmailStorageResult !== null,
                'webmail_user_storage_error' => $webmailStorageError,
            ],
        ]);

        if ($storageError !== null) {
            throw new RuntimeException('Mailbox DB was deleted, but mail storage purge failed.');
        }
    }
}
