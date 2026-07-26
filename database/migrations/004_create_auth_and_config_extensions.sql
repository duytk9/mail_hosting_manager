CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  mailbox_id BIGINT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  token_hash VARCHAR(64) NOT NULL UNIQUE,
  scopes JSON NOT NULL,
  last_used_at TIMESTAMP NULL,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_api_tokens_user (user_id),
  INDEX idx_api_tokens_mailbox (mailbox_id)
);

ALTER TABLE config_versions
  ADD COLUMN IF NOT EXISTS active_path VARCHAR(255) NULL AFTER rendered_path,
  ADD COLUMN IF NOT EXISTS previous_version_id BIGINT UNSIGNED NULL AFTER created_by;
