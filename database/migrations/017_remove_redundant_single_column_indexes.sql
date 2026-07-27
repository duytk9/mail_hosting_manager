-- Migration 017: remove redundant hotfix indexes left on existing installs.
-- Migration 014's composite indexes cover these leading columns. Fresh installs
-- never create these legacy names, so IF EXISTS keeps the migration idempotent.

ALTER TABLE aliases
    DROP INDEX IF EXISTS idx_aliases_tenant_id,
    DROP INDEX IF EXISTS idx_aliases_domain_id;

ALTER TABLE forwards
    DROP INDEX IF EXISTS idx_forwards_tenant_id,
    DROP INDEX IF EXISTS idx_forwards_domain_id;
