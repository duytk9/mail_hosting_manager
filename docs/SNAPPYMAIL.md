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
- Default auth log path:
  - `/var/www/webmail/data/logs/auth.log`

## Deployment
- Ensure the webmail client is already installed at `/var/www/webmail`
- Run:
  - `bash deploy/install_webmail_stack.sh /opt/mailpanel /var/www/webmail`
- The script rewires `.env`, re-points `/webmail`, enables logging, and applies fresh `nginx` + `fail2ban` versions

## Live Rollback Rule
- If `/webmail/` returns or redirects to the wrong upstream app, treat it as Nginx drift
- Re-apply generated Nginx config from MailPanel after fixing `.env`
