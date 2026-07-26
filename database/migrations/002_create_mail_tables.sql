CREATE TABLE IF NOT EXISTS domains (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain VARCHAR(190) NOT NULL UNIQUE,
  status VARCHAR(50) NOT NULL DEFAULT 'pending_dns',
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  catchall_mailbox_id BIGINT UNSIGNED NULL,
  inbound_enabled TINYINT(1) NOT NULL DEFAULT 1,
  outbound_enabled TINYINT(1) NOT NULL DEFAULT 1,
  dkim_enabled TINYINT(1) NOT NULL DEFAULT 1,
  dmarc_policy_expected VARCHAR(50) NOT NULL DEFAULT 'quarantine',
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  INDEX idx_domains_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS mailboxes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain_id BIGINT UNSIGNED NOT NULL,
  local_part VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(190) NOT NULL,
  quota_mb INT NOT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'active',
  force_password_change TINYINT(1) NOT NULL DEFAULT 0,
  imap_enabled TINYINT(1) NOT NULL DEFAULT 1,
  pop3_enabled TINYINT(1) NOT NULL DEFAULT 1,
  smtp_enabled TINYINT(1) NOT NULL DEFAULT 1,
  managesieve_enabled TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL,
  UNIQUE KEY uniq_mailbox_domain_local (domain_id, local_part),
  INDEX idx_mailboxes_tenant (tenant_id)
);

CREATE TABLE IF NOT EXISTS aliases (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain_id BIGINT UNSIGNED NOT NULL,
  source_address VARCHAR(190) NOT NULL UNIQUE,
  destination_mailbox_id BIGINT UNSIGNED NOT NULL,
  keep_copy TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS forwards (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain_id BIGINT UNSIGNED NOT NULL,
  source_address VARCHAR(190) NOT NULL UNIQUE,
  destination_address VARCHAR(190) NOT NULL,
  keep_copy TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  deleted_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS quota_usage (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  mailbox_id BIGINT UNSIGNED NOT NULL,
  used_mb INT NOT NULL DEFAULT 0,
  calculated_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  UNIQUE KEY uniq_quota_mailbox (mailbox_id)
);

CREATE TABLE IF NOT EXISTS mailbox_password_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  mailbox_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);
