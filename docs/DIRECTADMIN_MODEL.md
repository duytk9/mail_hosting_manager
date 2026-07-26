# Username-first Admin Model

## Account Rules
- Admin portal login is `username-first`; `linux_username` is the canonical identity.
- `email` is secondary and kept for recovery, notifications, and future password reset flows.
- `super_admin` accounts always map to a Linux user.
- `tenant_admin` accounts also log in by `linux_username`, but their mailbox identity stays tied to the tenant primary domain.
- Mailbox users stay separate from Linux users and must never reach the admin portal.
- Panel authentication is stored in the MailPanel database; Linux passwords are synchronized for SSH/sudo access instead of being the primary web auth source.

## Access Rules
- `super_admin` may be provisioned with SSH and optional password-based sudo.
- `tenant_admin` is panel-first by default; SSH and sudo are not granted automatically.
- Sudo cannot be enabled unless SSH is enabled first.
- All privileged Linux account changes must go through `mailpanel-web-agent` / allowlisted system wrappers.
- `super_admin` can impersonate `tenant_admin` / scoped ops accounts to inspect the exact tenant-level experience without sharing credentials.

## Automation Rules
- External automation should prefer `Login Keys` style credentials instead of sharing the real admin password.
- `POST /api/admin/login-keys` is the friendly alias of the existing scoped token issuance flow and must be called from an active admin web session with `current_password`.
- If TOTP is enabled for the admin, Login Key issuance also requires a valid `otp`.
- Existing Bearer tokens cannot mint new Login Keys/API tokens; credential issuance is session-only.
- `Login Keys` keep scope-limited access and can still coexist with the legacy `/api/admin/tokens` endpoint for backward compatibility.

## Operational Notes
- Renaming an admin `linux_username` requires password rotation so Linux and panel credentials stay in sync.
- Default `ADMIN_AUTH_MODE=hybrid` prefers the panel password hash, then performs one safe Linux-backed fallback login to re-sync stale panel hashes during migration.
- `ADMIN_AUTH_MODE=panel` disables Linux fallback completely once migration is finished.
- Legacy `ADMIN_AUTH_MODE=linux` remains available only for break-glass compatibility.
- Starting or stopping impersonation always leaves an audit-log trail.
- Recovery email should never be treated as a login identifier.
