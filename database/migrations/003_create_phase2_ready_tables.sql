CREATE TABLE IF NOT EXISTS spam_policies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  domain_id BIGINT UNSIGNED NULL,
  mailbox_id BIGINT UNSIGNED NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'normal',
  reject_score DECIMAL(5,2) NULL,
  add_header_score DECIMAL(5,2) NULL,
  greylist_score DECIMAL(5,2) NULL,
  quarantine_score DECIMAL(5,2) NULL,
  subject_prefix VARCHAR(100) NULL,
  allowlist_senders JSON NULL,
  blocklist_senders JSON NULL,
  allowlist_domains JSON NULL,
  blocklist_domains JSON NULL,
  antivirus_enabled TINYINT(1) NOT NULL DEFAULT 1,
  dkim_check_enabled TINYINT(1) NOT NULL DEFAULT 1,
  spf_check_enabled TINYINT(1) NOT NULL DEFAULT 1,
  dmarc_check_enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS dns_checks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  domain_id BIGINT UNSIGNED NOT NULL,
  record_type VARCHAR(20) NOT NULL,
  expected_value TEXT NULL,
  observed_value TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  checked_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS dkim_keys (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain_id BIGINT UNSIGNED NOT NULL,
  selector_name VARCHAR(100) NOT NULL DEFAULT 'mail',
  private_key_path VARCHAR(255) NOT NULL,
  public_key_txt TEXT NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL
);
