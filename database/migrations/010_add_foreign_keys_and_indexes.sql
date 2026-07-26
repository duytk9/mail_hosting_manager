-- Migration 010: Add Foreign Key constraints and missing indexes
-- Fixes: C3 (No Foreign Keys), H4 (Missing Indexes)
-- Date: 2026-07-06

-- ============================================================
-- PART 1: Missing Indexes (H4)
-- ============================================================

-- aliases: missing indexes on tenant_id, domain_id, destination_mailbox_id
ALTER TABLE aliases
  ADD INDEX IF NOT EXISTS idx_aliases_tenant (tenant_id),
  ADD INDEX IF NOT EXISTS idx_aliases_domain (domain_id),
  ADD INDEX IF NOT EXISTS idx_aliases_dest_mailbox (destination_mailbox_id);

-- forwards: missing indexes on tenant_id, domain_id
ALTER TABLE forwards
  ADD INDEX IF NOT EXISTS idx_forwards_tenant (tenant_id),
  ADD INDEX IF NOT EXISTS idx_forwards_domain (domain_id);

-- mailbox_password_history: missing index on mailbox_id
ALTER TABLE mailbox_password_history
  ADD INDEX IF NOT EXISTS idx_mbx_pw_history_mailbox (mailbox_id);

-- dkim_keys: missing index on domain_id
ALTER TABLE dkim_keys
  ADD INDEX IF NOT EXISTS idx_dkim_keys_domain (domain_id);

-- spam_policies: missing indexes on tenant_id, domain_id, mailbox_id
ALTER TABLE spam_policies
  ADD INDEX IF NOT EXISTS idx_spam_policies_tenant (tenant_id),
  ADD INDEX IF NOT EXISTS idx_spam_policies_domain (domain_id),
  ADD INDEX IF NOT EXISTS idx_spam_policies_mailbox (mailbox_id);

-- users: composite index for super admin listing
ALTER TABLE users
  ADD INDEX IF NOT EXISTS idx_users_role_deleted (role, deleted_at);

-- ============================================================
-- PART 2: Foreign Key Constraints (C3)
-- ============================================================

-- tenants -> packages
ALTER TABLE tenants
  ADD CONSTRAINT fk_tenants_package
  FOREIGN KEY (package_id) REFERENCES packages (id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- tenants -> users (admin_user_id)
ALTER TABLE tenants
  ADD CONSTRAINT fk_tenants_admin_user
  FOREIGN KEY (admin_user_id) REFERENCES users (id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- domains -> tenants
ALTER TABLE domains
  ADD CONSTRAINT fk_domains_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mailboxes -> tenants
ALTER TABLE mailboxes
  ADD CONSTRAINT fk_mailboxes_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mailboxes -> domains
ALTER TABLE mailboxes
  ADD CONSTRAINT fk_mailboxes_domain
  FOREIGN KEY (domain_id) REFERENCES domains (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- aliases -> tenants
ALTER TABLE aliases
  ADD CONSTRAINT fk_aliases_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- aliases -> domains
ALTER TABLE aliases
  ADD CONSTRAINT fk_aliases_domain
  FOREIGN KEY (domain_id) REFERENCES domains (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- aliases -> mailboxes (destination)
ALTER TABLE aliases
  ADD CONSTRAINT fk_aliases_dest_mailbox
  FOREIGN KEY (destination_mailbox_id) REFERENCES mailboxes (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- forwards -> tenants
ALTER TABLE forwards
  ADD CONSTRAINT fk_forwards_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- forwards -> domains
ALTER TABLE forwards
  ADD CONSTRAINT fk_forwards_domain
  FOREIGN KEY (domain_id) REFERENCES domains (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- quota_usage -> tenants
ALTER TABLE quota_usage
  ADD CONSTRAINT fk_quota_usage_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- quota_usage -> mailboxes
ALTER TABLE quota_usage
  ADD CONSTRAINT fk_quota_usage_mailbox
  FOREIGN KEY (mailbox_id) REFERENCES mailboxes (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mailbox_password_history -> tenants
ALTER TABLE mailbox_password_history
  ADD CONSTRAINT fk_mbx_pw_history_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mailbox_password_history -> mailboxes
ALTER TABLE mailbox_password_history
  ADD CONSTRAINT fk_mbx_pw_history_mailbox
  FOREIGN KEY (mailbox_id) REFERENCES mailboxes (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mail_groups -> tenants
ALTER TABLE mail_groups
  ADD CONSTRAINT fk_mail_groups_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mail_groups -> domains
ALTER TABLE mail_groups
  ADD CONSTRAINT fk_mail_groups_domain
  FOREIGN KEY (domain_id) REFERENCES domains (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- mail_group_members -> mail_groups
ALTER TABLE mail_group_members
  ADD CONSTRAINT fk_mail_group_members_group
  FOREIGN KEY (group_id) REFERENCES mail_groups (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- api_tokens -> users
ALTER TABLE api_tokens
  ADD CONSTRAINT fk_api_tokens_user
  FOREIGN KEY (user_id) REFERENCES users (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- user_password_history -> users
ALTER TABLE user_password_history
  ADD CONSTRAINT fk_user_pw_history_user
  FOREIGN KEY (user_id) REFERENCES users (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- dns_checks -> domains
ALTER TABLE dns_checks
  ADD CONSTRAINT fk_dns_checks_domain
  FOREIGN KEY (domain_id) REFERENCES domains (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- dkim_keys -> tenants
ALTER TABLE dkim_keys
  ADD CONSTRAINT fk_dkim_keys_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- dkim_keys -> domains
ALTER TABLE dkim_keys
  ADD CONSTRAINT fk_dkim_keys_domain
  FOREIGN KEY (domain_id) REFERENCES domains (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- tenant_subscriptions -> tenants
ALTER TABLE tenant_subscriptions
  ADD CONSTRAINT fk_tenant_subs_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- tenant_subscriptions -> packages
ALTER TABLE tenant_subscriptions
  ADD CONSTRAINT fk_tenant_subs_package
  FOREIGN KEY (package_id) REFERENCES packages (id)
  ON DELETE RESTRICT ON UPDATE CASCADE;

-- tenant_lifecycle_events -> tenants
ALTER TABLE tenant_lifecycle_events
  ADD CONSTRAINT fk_tenant_lifecycle_tenant
  FOREIGN KEY (tenant_id) REFERENCES tenants (id)
  ON DELETE CASCADE ON UPDATE CASCADE;

