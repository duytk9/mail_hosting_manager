CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  role VARCHAR(50) NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS packages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  description TEXT NULL,
  max_domains INT NOT NULL,
  max_mailboxes INT NOT NULL,
  max_aliases INT NOT NULL DEFAULT 0,
  max_forwarders INT NOT NULL DEFAULT 0,
  max_total_quota_mb INT NOT NULL,
  default_mailbox_quota_mb INT NOT NULL,
  max_mailbox_quota_mb INT NOT NULL,
  max_message_size_mb INT NOT NULL,
  outbound_per_hour INT NOT NULL,
  outbound_per_day INT NOT NULL,
  enable_pop3 TINYINT(1) NOT NULL DEFAULT 1,
  enable_imap TINYINT(1) NOT NULL DEFAULT 1,
  enable_managesieve TINYINT(1) NOT NULL DEFAULT 1,
  enable_catchall TINYINT(1) NOT NULL DEFAULT 0,
  enable_external_forwarding TINYINT(1) NOT NULL DEFAULT 0,
  spam_level_default VARCHAR(20) NOT NULL DEFAULT 'normal',
  quarantine_enabled TINYINT(1) NOT NULL DEFAULT 1,
  antivirus_enabled TINYINT(1) NOT NULL DEFAULT 1,
  dkim_enabled TINYINT(1) NOT NULL DEFAULT 1,
  custom_smtp_banner_allowed TINYINT(1) NOT NULL DEFAULT 0,
  retention_days INT NOT NULL DEFAULT 30,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(150) NOT NULL UNIQUE,
  status VARCHAR(50) NOT NULL DEFAULT 'active',
  package_id BIGINT UNSIGNED NOT NULL,
  max_domains INT NOT NULL,
  max_mailboxes INT NOT NULL,
  max_total_quota_mb INT NOT NULL,
  default_mailbox_quota_mb INT NOT NULL,
  allow_catchall TINYINT(1) NOT NULL DEFAULT 0,
  allow_external_forwarding TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  INDEX idx_tenants_package (package_id)
);

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_id BIGINT UNSIGNED NULL,
  actor_role VARCHAR(50) NOT NULL,
  tenant_id BIGINT UNSIGNED NULL,
  action VARCHAR(190) NOT NULL,
  target_type VARCHAR(100) NOT NULL,
  target_id BIGINT UNSIGNED NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_audit_target (target_type, target_id),
  INDEX idx_audit_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS config_versions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service VARCHAR(50) NOT NULL,
  version VARCHAR(50) NOT NULL,
  rendered_path VARCHAR(255) NOT NULL,
  checksum VARCHAR(64) NOT NULL,
  status VARCHAR(50) NOT NULL,
  error_message TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  applied_at TIMESTAMP NULL,
  INDEX idx_config_service (service, version)
);
