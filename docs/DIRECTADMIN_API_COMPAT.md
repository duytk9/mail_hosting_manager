# Friendly API Compatibility

## Purpose
- Keep the internal data model stable while exposing friendlier API nouns for operators.
- Preserve backward compatibility with the existing `/api/admin/*` endpoints already used by the panel and agents.

## Alias Endpoints
- `POST /api/admin/login-keys` → alias of scoped token issuance
- `GET /api/admin/users` → alias of `GET /api/admin/tenants`
- `POST /api/admin/users` → alias of `POST /api/admin/tenants`
- `GET /api/admin/mail-accounts` → alias of `GET /api/admin/mailboxes`
- `POST /api/admin/mail-accounts` → alias of `POST /api/admin/mailboxes`
- `GET /api/admin/config-revisions` → alias of `GET /api/admin/config-versions`
- `POST /api/admin/config-revisions/generate` → alias of `POST /api/admin/config-versions/generate`
- `POST /api/admin/config-revisions/apply` → alias of `POST /api/admin/config-versions/apply`
- `POST /api/admin/config-revisions/rollback` → alias of `POST /api/admin/config-versions/rollback`

## Security Notes
- Alias routes keep the exact same permission checks, tenant-scope enforcement, writable checks, and token scopes as the canonical endpoints.
- `Login Keys` remain scope-limited credentials; they do not bypass RBAC and should be preferred over sharing real admin passwords.
- Login Key/API token issuance is session-only and requires `current_password`; include `otp` when the admin has TOTP enabled.
- Existing Bearer tokens cannot mint new Login Keys/API tokens, even if they contain `tokens.write`.
