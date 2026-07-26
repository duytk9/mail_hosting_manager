-- 1. Create tenant_limits_overrides
CREATE TABLE IF NOT EXISTS tenant_limits_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL UNIQUE,
    extra_domains INT NOT NULL DEFAULT 0,
    extra_mailboxes INT NOT NULL DEFAULT 0,
    extra_aliases INT NOT NULL DEFAULT 0,
    extra_forwarders INT NOT NULL DEFAULT 0,
    extra_total_quota_mb INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT fk_tenant_limits_overrides_tenant_id FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Migrate existing overrides from tenants to tenant_limits_overrides
INSERT INTO tenant_limits_overrides (tenant_id, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, created_at, updated_at)
SELECT id, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, NOW(), NOW()
FROM tenants
WHERE extra_domains > 0 OR extra_mailboxes > 0 OR extra_aliases > 0 OR extra_forwarders > 0 OR extra_total_quota_mb > 0;

-- 3. Drop columns from tenants
ALTER TABLE tenants
    DROP COLUMN extra_domains,
    DROP COLUMN extra_mailboxes,
    DROP COLUMN extra_aliases,
    DROP COLUMN extra_forwarders,
    DROP COLUMN extra_total_quota_mb;

