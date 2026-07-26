ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS admin_user_id BIGINT UNSIGNED NULL AFTER allow_external_forwarding;

CREATE TABLE IF NOT EXISTS mail_groups (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain_id BIGINT UNSIGNED NOT NULL,
  local_part VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  display_name VARCHAR(190) NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_mail_group_domain_local (domain_id, local_part),
  INDEX idx_mail_groups_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS mail_group_members (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  recipient_address VARCHAR(190) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_group_member (group_id, recipient_address)
);
