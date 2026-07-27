-- Migration 014: Optimize operational indexes for auth, tenant scoping, config history, and cleanup jobs.

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_linux_deleted_role (linux_username, deleted_at, role),
    ADD INDEX IF NOT EXISTS idx_users_tenant_deleted_id (tenant_id, deleted_at, id);

ALTER TABLE domains
    ADD INDEX IF NOT EXISTS idx_domains_tenant_deleted_domain (tenant_id, deleted_at, domain),
    ADD INDEX IF NOT EXISTS idx_domains_tenant_primary_deleted (tenant_id, is_primary, deleted_at, id);

ALTER TABLE mailboxes
    ADD INDEX IF NOT EXISTS idx_mailboxes_tenant_deleted_id (tenant_id, deleted_at, id),
    ADD INDEX IF NOT EXISTS idx_mailboxes_domain_deleted_id (domain_id, deleted_at, id);

ALTER TABLE aliases
    ADD INDEX IF NOT EXISTS idx_aliases_tenant_deleted_id (tenant_id, deleted_at, id),
    ADD INDEX IF NOT EXISTS idx_aliases_domain_deleted_id (domain_id, deleted_at, id);

ALTER TABLE forwards
    ADD INDEX IF NOT EXISTS idx_forwards_tenant_deleted_id (tenant_id, deleted_at, id),
    ADD INDEX IF NOT EXISTS idx_forwards_domain_deleted_id (domain_id, deleted_at, id);

ALTER TABLE mail_groups
    ADD INDEX IF NOT EXISTS idx_mail_groups_tenant_deleted_id (tenant_id, deleted_at, id),
    ADD INDEX IF NOT EXISTS idx_mail_groups_domain_deleted_id (domain_id, deleted_at, id);

ALTER TABLE quota_usage
    ADD INDEX IF NOT EXISTS idx_quota_usage_tenant_deleted_id (tenant_id, deleted_at, id);

ALTER TABLE config_versions
    ADD INDEX IF NOT EXISTS idx_config_versions_service_status_id (service, status, id),
    ADD INDEX IF NOT EXISTS idx_config_versions_status_id (status, id);

ALTER TABLE api_tokens
    ADD INDEX IF NOT EXISTS idx_api_tokens_user_active (user_id, revoked_at, expires_at, id),
    ADD INDEX IF NOT EXISTS idx_api_tokens_mailbox_active (mailbox_id, revoked_at, expires_at, id);

ALTER TABLE audit_logs
    ADD INDEX IF NOT EXISTS idx_audit_logs_tenant_created (tenant_id, created_at),
    ADD INDEX IF NOT EXISTS idx_audit_logs_action_created (action, created_at);

ALTER TABLE rate_limits
    ADD INDEX IF NOT EXISTS idx_rate_limits_expires_at (expires_at);

-- Keep replacement indexes in place before retiring the original migration-010
-- names. MariaDB refuses to drop the only index that supports a foreign key.
ALTER TABLE aliases
    ADD INDEX IF NOT EXISTS idx_aliases_destination_mailbox_id (destination_mailbox_id);

ALTER TABLE mailbox_password_history
    ADD INDEX IF NOT EXISTS idx_mph_mailbox_id (mailbox_id);

ALTER TABLE dkim_keys
    ADD INDEX IF NOT EXISTS idx_dkim_keys_domain_id (domain_id);

ALTER TABLE spam_policies
    ADD INDEX IF NOT EXISTS idx_spam_policies_tenant_id (tenant_id),
    ADD INDEX IF NOT EXISTS idx_spam_policies_domain_id (domain_id),
    ADD INDEX IF NOT EXISTS idx_spam_policies_mailbox_id (mailbox_id);

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_role_deleted_at (role, deleted_at);

ALTER TABLE aliases DROP INDEX IF EXISTS idx_aliases_dest_mailbox;
ALTER TABLE aliases DROP INDEX IF EXISTS idx_aliases_domain;
ALTER TABLE aliases DROP INDEX IF EXISTS idx_aliases_tenant;

ALTER TABLE forwards DROP INDEX IF EXISTS idx_forwards_domain;
ALTER TABLE forwards DROP INDEX IF EXISTS idx_forwards_tenant;

ALTER TABLE mailbox_password_history DROP INDEX IF EXISTS idx_mbx_pw_history_mailbox;
ALTER TABLE dkim_keys DROP INDEX IF EXISTS idx_dkim_keys_domain;

ALTER TABLE spam_policies DROP INDEX IF EXISTS idx_spam_policies_tenant;
ALTER TABLE spam_policies DROP INDEX IF EXISTS idx_spam_policies_domain;
ALTER TABLE spam_policies DROP INDEX IF EXISTS idx_spam_policies_mailbox;

ALTER TABLE users DROP INDEX IF EXISTS idx_users_role_deleted;
