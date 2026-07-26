ALTER TABLE users
  ADD COLUMN IF NOT EXISTS force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash,
  ADD COLUMN IF NOT EXISTS password_changed_at TIMESTAMP NULL AFTER force_password_change,
  ADD COLUMN IF NOT EXISTS last_login_at TIMESTAMP NULL AFTER password_changed_at,
  ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(255) NULL AFTER last_login_at,
  ADD COLUMN IF NOT EXISTS totp_pending_secret VARCHAR(255) NULL AFTER totp_secret,
  ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER totp_pending_secret,
  ADD COLUMN IF NOT EXISTS totp_confirmed_at TIMESTAMP NULL AFTER totp_enabled;

ALTER TABLE api_tokens
  ADD COLUMN IF NOT EXISTS actor_role VARCHAR(50) NOT NULL DEFAULT 'super_admin' AFTER mailbox_id,
  ADD COLUMN IF NOT EXISTS revoked_at TIMESTAMP NULL AFTER expires_at;

CREATE TABLE IF NOT EXISTS user_password_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  INDEX idx_user_password_history_user (user_id)
);
