<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class TenantRepository extends AbstractPdoRepository
{
    /**
     * @param int|null $tenantId When set, returns only that tenant. A tenant_admin
     *                           may only ever see their own tenant row, so the
     *                           filter is on t.id rather than a tenant_id column.
     */
    public function all(?int $tenantId = null): array
    {
        $sql = 'SELECT t.*, o.extra_domains, o.extra_mailboxes, o.extra_aliases, o.extra_forwarders, o.extra_total_quota_mb'
            . ' FROM tenants t LEFT JOIN tenant_limits_overrides o ON t.id = o.tenant_id'
            . ' WHERE t.deleted_at IS NULL';

        if ($tenantId === null) {
            return $this->fetchAll($sql . ' ORDER BY t.id DESC');
        }

        return $this->fetchAll($sql . ' AND t.id = :tenant_id ORDER BY t.id DESC', ['tenant_id' => $tenantId]);
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT t.*, o.extra_domains, o.extra_mailboxes, o.extra_aliases, o.extra_forwarders, o.extra_total_quota_mb FROM tenants t LEFT JOIN tenant_limits_overrides o ON t.id = o.tenant_id WHERE t.id = :id AND t.deleted_at IS NULL', ['id' => $id]);
    }

    public function countByPackage(int $packageId): int
    {
        $row = $this->fetchOne(
            'SELECT COUNT(t.id) AS aggregate_count FROM tenants t WHERE t.package_id = :package_id AND t.deleted_at IS NULL',
            ['package_id' => $packageId]
        );

        return (int) ($row['aggregate_count'] ?? 0);
    }

    public function allByPackage(int $packageId): array
    {
        return $this->fetchAll(
            'SELECT t.*, o.extra_domains, o.extra_mailboxes, o.extra_aliases, o.extra_forwarders, o.extra_total_quota_mb FROM tenants t LEFT JOIN tenant_limits_overrides o ON t.id = o.tenant_id WHERE t.package_id = :package_id AND t.deleted_at IS NULL ORDER BY t.id ASC',
            ['package_id' => $packageId]
        );
    }

    public function create(array $data): array
    {
        $tenantData = $data;
        unset($tenantData['extra_domains'], $tenantData['extra_mailboxes'], $tenantData['extra_aliases'], $tenantData['extra_forwarders'], $tenantData['extra_total_quota_mb']);
        
        $this->execute(
            'INSERT INTO tenants (name, slug, status, billing_status, starts_at, expires_at, grace_until, suspended_at, terminated_at, package_id, is_custom_limits, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, note, created_at, updated_at) VALUES (:name, :slug, :status, :billing_status, :starts_at, :expires_at, :grace_until, :suspended_at, :terminated_at, :package_id, :is_custom_limits, :max_domains, :max_mailboxes, :max_aliases, :max_forwarders, :max_total_quota_mb, :default_mailbox_quota_mb, :allow_catchall, :allow_external_forwarding, :note, NOW(), NOW())',
            $tenantData
        );
        $tenantId = $this->lastInsertId();
        
        $this->execute(
            'INSERT INTO tenant_limits_overrides (tenant_id, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, created_at, updated_at) VALUES (:tenant_id, :extra_domains, :extra_mailboxes, :extra_aliases, :extra_forwarders, :extra_total_quota_mb, NOW(), NOW())',
            [
                'tenant_id' => $tenantId,
                'extra_domains' => $data['extra_domains'] ?? 0,
                'extra_mailboxes' => $data['extra_mailboxes'] ?? 0,
                'extra_aliases' => $data['extra_aliases'] ?? 0,
                'extra_forwarders' => $data['extra_forwarders'] ?? 0,
                'extra_total_quota_mb' => $data['extra_total_quota_mb'] ?? 0,
            ]
        );

        return $this->find($this->lastInsertId()) ?? [];
    }

    public function updateAdminUser(int $tenantId, ?int $userId): void
    {
        $this->execute(
            'UPDATE tenants SET admin_user_id = :admin_user_id, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $tenantId, 'admin_user_id' => $userId]
        );
    }

    public function softDeleteTenantAdmins(int $tenantId): void
    {
        $this->execute(
            'DELETE FROM users WHERE tenant_id = :tenant_id AND role = :role',
            ['tenant_id' => $tenantId, 'role' => 'tenant_admin']
        );
    }

    public function update(int $id, array $data): void
    {
        $tenantData = $data + ['id' => $id];
        unset($tenantData['extra_domains'], $tenantData['extra_mailboxes'], $tenantData['extra_aliases'], $tenantData['extra_forwarders'], $tenantData['extra_total_quota_mb']);
        
        $this->execute(
            'UPDATE tenants SET name = :name, slug = :slug, status = :status, billing_status = :billing_status, starts_at = :starts_at, expires_at = :expires_at, grace_until = :grace_until, suspended_at = :suspended_at, terminated_at = :terminated_at, package_id = :package_id, is_custom_limits = :is_custom_limits, max_domains = :max_domains, max_mailboxes = :max_mailboxes, max_aliases = :max_aliases, max_forwarders = :max_forwarders, max_total_quota_mb = :max_total_quota_mb, default_mailbox_quota_mb = :default_mailbox_quota_mb, allow_catchall = :allow_catchall, allow_external_forwarding = :allow_external_forwarding, note = :note, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            $tenantData
        );
        
        $this->execute(
            'INSERT INTO tenant_limits_overrides (tenant_id, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, created_at, updated_at) VALUES (:tenant_id, :extra_domains, :extra_mailboxes, :extra_aliases, :extra_forwarders, :extra_total_quota_mb, NOW(), NOW()) ON DUPLICATE KEY UPDATE extra_domains = VALUES(extra_domains), extra_mailboxes = VALUES(extra_mailboxes), extra_aliases = VALUES(extra_aliases), extra_forwarders = VALUES(extra_forwarders), extra_total_quota_mb = VALUES(extra_total_quota_mb), updated_at = NOW()',
            [
                'tenant_id' => $id,
                'extra_domains' => $data['extra_domains'] ?? 0,
                'extra_mailboxes' => $data['extra_mailboxes'] ?? 0,
                'extra_aliases' => $data['extra_aliases'] ?? 0,
                'extra_forwarders' => $data['extra_forwarders'] ?? 0,
                'extra_total_quota_mb' => $data['extra_total_quota_mb'] ?? 0,
            ]
        );
    }

    public function recordSubscription(array $data): void
    {
        $this->execute(
            'INSERT INTO tenant_subscriptions (tenant_id, package_id, billing_status, starts_at, expires_at, grace_until, note, created_by, created_at, updated_at) VALUES (:tenant_id, :package_id, :billing_status, :starts_at, :expires_at, :grace_until, :note, :created_by, NOW(), NOW())',
            $data
        );
    }

    public function recordLifecycleEvent(array $data): void
    {
        $this->execute(
            'INSERT INTO tenant_lifecycle_events (tenant_id, event_type, old_status, new_status, starts_at, expires_at, grace_until, note, actor_id, created_at) VALUES (:tenant_id, :event_type, :old_status, :new_status, :starts_at, :expires_at, :grace_until, :note, :actor_id, NOW())',
            $data
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM tenants WHERE id = :id', ['id' => $id]);
    }
}
