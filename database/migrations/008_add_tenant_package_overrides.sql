ALTER TABLE tenants
  ADD COLUMN IF NOT EXISTS is_custom_limits TINYINT(1) NOT NULL DEFAULT 0 AFTER package_id,
  ADD COLUMN IF NOT EXISTS max_aliases INT NOT NULL DEFAULT 0 AFTER max_mailboxes,
  ADD COLUMN IF NOT EXISTS max_forwarders INT NOT NULL DEFAULT 0 AFTER max_aliases,
  ADD COLUMN IF NOT EXISTS extra_domains INT NOT NULL DEFAULT 0 AFTER is_custom_limits,
  ADD COLUMN IF NOT EXISTS extra_mailboxes INT NOT NULL DEFAULT 0 AFTER extra_domains,
  ADD COLUMN IF NOT EXISTS extra_aliases INT NOT NULL DEFAULT 0 AFTER extra_mailboxes,
  ADD COLUMN IF NOT EXISTS extra_forwarders INT NOT NULL DEFAULT 0 AFTER extra_aliases,
  ADD COLUMN IF NOT EXISTS extra_total_quota_mb INT NOT NULL DEFAULT 0 AFTER extra_forwarders,
  ADD COLUMN IF NOT EXISTS note TEXT NULL AFTER admin_user_id;

UPDATE tenants t
LEFT JOIN packages p ON p.id = t.package_id AND p.deleted_at IS NULL
SET
  t.max_aliases = CASE
    WHEN COALESCE(t.max_aliases, 0) = 0 AND p.id IS NOT NULL THEN COALESCE(p.max_aliases, 0)
    ELSE COALESCE(t.max_aliases, 0)
  END,
  t.max_forwarders = CASE
    WHEN COALESCE(t.max_forwarders, 0) = 0 AND p.id IS NOT NULL THEN COALESCE(p.max_forwarders, 0)
    ELSE COALESCE(t.max_forwarders, 0)
  END,
  t.extra_domains = CASE
    WHEN p.id IS NOT NULL THEN GREATEST(COALESCE(t.max_domains, 0) - COALESCE(p.max_domains, 0), 0)
    ELSE COALESCE(t.extra_domains, 0)
  END,
  t.extra_mailboxes = CASE
    WHEN p.id IS NOT NULL THEN GREATEST(COALESCE(t.max_mailboxes, 0) - COALESCE(p.max_mailboxes, 0), 0)
    ELSE COALESCE(t.extra_mailboxes, 0)
  END,
  t.extra_aliases = CASE
    WHEN p.id IS NOT NULL THEN GREATEST(COALESCE(t.max_aliases, 0) - COALESCE(p.max_aliases, 0), 0)
    ELSE COALESCE(t.extra_aliases, 0)
  END,
  t.extra_forwarders = CASE
    WHEN p.id IS NOT NULL THEN GREATEST(COALESCE(t.max_forwarders, 0) - COALESCE(p.max_forwarders, 0), 0)
    ELSE COALESCE(t.extra_forwarders, 0)
  END,
  t.extra_total_quota_mb = CASE
    WHEN p.id IS NOT NULL THEN GREATEST(COALESCE(t.max_total_quota_mb, 0) - COALESCE(p.max_total_quota_mb, 0), 0)
    ELSE COALESCE(t.extra_total_quota_mb, 0)
  END;
