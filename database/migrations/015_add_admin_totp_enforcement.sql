ALTER TABLE users
  ADD COLUMN IF NOT EXISTS totp_grace_login_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_confirmed_at,
  ADD COLUMN IF NOT EXISTS security_locked_at TIMESTAMP NULL AFTER totp_grace_login_count,
  ADD COLUMN IF NOT EXISTS security_lock_reason VARCHAR(100) NULL AFTER security_locked_at,
  ADD INDEX IF NOT EXISTS idx_users_security_lock (security_locked_at);
