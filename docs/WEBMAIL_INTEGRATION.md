# Webmail Integration

## Goal
- Use a dedicated webmail client for MVP and current live deployments
- Keep MailPanel responsible for tenant, domain, mailbox, package, and system configuration
- Keep the webmail client focused on mail access only

## Runtime Topology
- Webmail URL: `https://<host>/webmail/`
- IMAPS: `127.0.0.1:993`
- SMTP submission: `127.0.0.1:465` or `127.0.0.1:587`
- ManageSieve: `127.0.0.1:4190`
- Public symlink: `/opt/mailpanel/public/webmail -> /var/www/webmail`

## Security Boundary
- The webmail client must not manage tenants, packages, domains, or system config
- Webmail data directories must never be public
- Nginx must deny `config`, `data`, `temp`, `logs`, `installer`, `SQL`, `bin`, and `vendor`
- The webmail admin panel should stay disabled in production
- Auth logs should be enabled for Fail2ban

## Fail2ban
- Canonical jail: `webmail-auth`
- Canonical filter: `mailpanel-webmail-auth`
- Auth log path is controlled by `WEBMAIL_LOG_PATH`.
- Common auth log paths:
  - `/var/log/roundcube/userlogins.log`
  - `/var/www/webmail/data/_data_/_default_/logs/auth.log`
- After changing `WEBMAIL_LOG_PATH`, generate/apply a fresh `fail2ban` config version.

## Deployment
- Ensure the webmail client is already installed at `/var/www/webmail`
- Run:
  - `bash deploy/install_webmail_stack.sh /opt/mailpanel /var/www/webmail`
- The script rewires `.env`, re-points `/webmail`, syncs Roundcube runtime config plus MailPanel domains, and applies fresh `nginx` + `fail2ban` versions
- Manual drift fix:
  - `php scripts/sync_webmail_runtime.php`

## UX Defaults
- Branding defaults to `MailPanel Webmail`
- Personal contacts are enabled
- "Remember me" is enabled by default
- Inbox page size is increased to 50 messages
- Spellcheck and message threads are enabled
- Domain JSON config is auto-generated for MailPanel domains so full-email login and ManageSieve work consistently

## Admin Operations
- Use `GET /admin/webmail` to review Roundcube runtime health from the admin panel
- Use the `Resync Runtime` action there to re-apply `application.ini` defaults plus managed domain JSON
- The page also surfaces recent auth log lines and current Fail2ban jail state

## Password Change Bridge
- Webmail-safe endpoint: `POST /api/webmail/password-change`
- Required JSON body:
  - `email`
  - `current_password`
  - `new_password`
- The endpoint routes password changes through MailPanel policy + audit logging instead of allowing direct DB writes from webmail

## Live Rollback Rule
- If `/webmail/` returns or redirects to the wrong upstream app, treat it as Nginx drift
- Re-apply generated Nginx config from MailPanel after fixing `.env`
