# Admin Audit

## Scope
- Admin frontend shell and views
- Admin controllers and API routes
- Session, RBAC, CSRF, TOTP, rate limiting
- Live webmail drift between two different upstream targets

## Frontend Findings

### High
- `src/Views/admin/layout.php` still contains mojibake in multiple static labels
- Webmail menu previously still linked bằng nhãn cũ; nay đã chuyển sang nhãn generic

### Medium
- Admin shell is visually consistent but still mixes Vietnamese UTF-8 text with corrupted legacy strings
- Some admin pages likely still inherit corrupted titles/flash strings from controllers

## Backend Findings

### High
- `src/Http/Controllers/AdminWebController.php` is oversized and mixes rendering, orchestration, validation, and some direct SQL

### Medium
- Helper scripts and docs had stale legacy webmail assumptions; core config/renderers are now moved to generic webmail keys with backward compatibility
- Local workspace lacks dev dependencies needed to execute PHPUnit directly

## Security Findings

### Fixed in this pass
- Core webmail config is now generic: `WEBMAIL_*`
- Fail2ban now renders canonical `webmail-auth` with webmail-compatible auth regex
- Nginx now blocks private webmail `data/` paths in addition to existing sensitive directories
- Tenant admin edit/delete flows now go through `TenantAdminService` instead of direct SQL in the controller
- The webmail deploy script now disables the legacy upstream app, removes stale flattened deploy artifacts, and strips stray `public/info.php`

### Remaining Risks
- Broader mojibake cleanup is still needed in user-facing controller messages
- End-to-end live verification still requires pushing these source changes and reapplying config on the server

## Live Drift Root Cause
- The webmail app still exists on live at its configured root
- `/webmail` was being rewritten to the wrong upstream by live Nginx config
- Corrective direction is to:
  - keep `/webmail` mapped to the canonical webmail app
  - reapply generated Nginx config from MailPanel
  - enable webmail auth logging for Fail2ban

## Recommended Next Steps
1. Push the patched source to live
2. Run `deploy/install_webmail_stack.sh` against the configured webmail root
3. Reapply generated `nginx` and `fail2ban` versions
4. Verify `/webmail/` returns the intended webmail app
5. Refactor admin controller write paths into dedicated services
