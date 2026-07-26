ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS billing_status VARCHAR(50) NOT NULL DEFAULT 'active' AFTER status,
  ADD COLUMN IF NOT EXISTS starts_at TIMESTAMP NULL AFTER billing_status,
  ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP NULL AFTER starts_at,
  ADD COLUMN IF NOT EXISTS grace_until TIMESTAMP NULL AFTER expires_at,
  ADD COLUMN IF NOT EXISTS suspended_at TIMESTAMP NULL AFTER grace_until,
  ADD COLUMN IF NOT EXISTS terminated_at TIMESTAMP NULL AFTER suspended_at;

CREATE TABLE IF NOT EXISTS tenant_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  package_id BIGINT UNSIGNED NOT NULL,
  billing_status VARCHAR(50) NOT NULL DEFAULT 'active',
  starts_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  grace_until TIMESTAMP NULL,
  note TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_tenant_subscriptions_tenant (tenant_id),
  INDEX idx_tenant_subscriptions_package (package_id),
  INDEX idx_tenant_subscriptions_expires (expires_at)
);

CREATE TABLE IF NOT EXISTS tenant_lifecycle_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(80) NOT NULL,
  old_status VARCHAR(50) NULL,
  new_status VARCHAR(50) NULL,
  starts_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  grace_until TIMESTAMP NULL,
  note TEXT NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  INDEX idx_tenant_lifecycle_events_tenant (tenant_id),
  INDEX idx_tenant_lifecycle_events_type (event_type)
);

UPDATE tenants
SET billing_status = CASE
    WHEN status = 'suspended' THEN 'suspended'
    WHEN status = 'archived' THEN 'terminated'
    ELSE COALESCE(NULLIF(billing_status, ''), 'active')
  END
WHERE billing_status IS NULL OR billing_status = '' OR billing_status = 'active';
