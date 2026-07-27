-- Migration 011: rate_limits table
--
-- NOTE: this file previously also re-added indexes that migration 010 already
-- creates, using different names (idx_aliases_tenant vs idx_aliases_tenant_id,
-- idx_users_role_deleted vs idx_users_role_deleted_at). On a fresh database that
-- produced two identical indexes per column, doubling write cost for no benefit.
-- Those duplicate ALTER statements were removed; migration 010 is now the single
-- source of truth for indexes and foreign keys.

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(64) NOT NULL PRIMARY KEY,
    attempts INT NOT NULL DEFAULT 0,
    expires_at INT NOT NULL,
    INDEX idx_rate_limits_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Composite index for the super admin login lookup.
-- Not covered by migration 010's index block.
ALTER TABLE users
    ADD INDEX idx_users_role_email (role, email);
