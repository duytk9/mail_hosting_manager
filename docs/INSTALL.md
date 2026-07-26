# MailPanel Installation

## System Requirements
- Ubuntu `22.04+`
- PHP `8.3+` with `pdo_mysql`, `mbstring`, `xml`, `curl`, `intl`, `gd`, `zip`
- MariaDB/MySQL `10.6+`
- Nginx + PHP-FPM
- Exim, Dovecot, Rspamd, ClamAV, Fail2ban

## Core Packages
- `nginx`
- `php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-intl php8.3-gd php8.3-zip`
- `mariadb-server`
- `exim4`
- `dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve dovecot-managesieved dovecot-mysql`
- `rspamd`
- `clamav clamav-daemon`
- `fail2ban`

## Service Layout
- App root: `/opt/mailpanel`
- Mail storage: `/var/vmail`
- Generated config root: `/var/lib/mailpanel/generated`
- Agent log: `/var/log/mailpanel/agent.log`
- Virtual mail user: `vmail:vmail` with fixed `uid/gid`, typically `2000:2000`
- Web app must not run as `root`
- System agent should run as dedicated user `mailpanel-agent`

## Bootstrap
- `composer install`
- `php scripts/migrate.php`
- `php scripts/seed.php`
- `php vendor/bin/phpunit --testdox`

## Agent / Allowlist
- Install the agent with `bash deploy/install_agent.sh /opt/mailpanel mailpanel-agent www-data`
- Web controllers should only execute `/usr/local/bin/mailpanel-web-agent`
- Root-level orchestration stays in `agent/mailpanel-system-wrapper`
- No controller should execute raw shell commands directly

## Config Deployment
- Generate drafts: `POST /api/admin/config-versions/generate`
- List versions: `GET /api/admin/config-versions`
- Apply version: `POST /api/admin/config-versions/apply`
- Roll back version: `POST /api/admin/config-versions/rollback`
- Friendly API aliases are also available:
  - `POST /api/admin/config-revisions/generate`
  - `GET /api/admin/config-revisions`
  - `POST /api/admin/config-revisions/apply`
  - `POST /api/admin/config-revisions/rollback`

## Login Keys / API
- Legacy token route: `POST /api/admin/tokens`
- Friendly alias: `POST /api/admin/login-keys`
- Issuance requires an active admin web session, CSRF protection, `current_password`, and `otp` when TOTP is enabled.
- Existing Bearer tokens cannot mint new Login Keys/API tokens, even with `tokens.write`.
- Friendly object aliases:
  - `GET|POST /api/admin/users`
  - `GET|POST /api/admin/mail-accounts`

## Webmail Integration
- Canonical webmail client is mounted at `/webmail`
- Existing webmail root is expected at `/var/www/webmail`
- Apply the integration with:
  - `bash deploy/manage_acme_tls.sh bootstrap /opt/mailpanel`
  - `bash deploy/install_webmail_stack.sh /opt/mailpanel /var/www/webmail`
- The integration script:
  - points `public/webmail` to the webmail root
  - enables private auth logging for Fail2ban
  - stops and disables the legacy webmail service if it still exists
  - removes stale deploy artifacts accidentally flattened into `/opt/mailpanel`
  - removes stray public `public/info.php` from production roots
  - applies fresh `nginx` and `fail2ban` config versions via the agent
  - verifies `/webmail/` no longer serves the wrong upstream content

## Important Environment Variables
- `WEBMAIL_ENABLED=1`
- `WEBMAIL_DRIVER=webmail`
- `WEBMAIL_PUBLIC_ROOT=/var/www/webmail`
- `WEBMAIL_LOG_PATH=/var/www/webmail/data/logs/auth.log`
- `WEBMAIL_DISPLAY_NAME=Webmail`
- `WEBMAIL_PATH=/webmail`
- `ACME_WEBROOT=/var/www/acme`
- `TLS_SNI_ROOT=/etc/mailpanel/tls/sni`

## Session / Security
- `SESSION_TIMEOUT_SECONDS`
- `SESSION_COOKIE_SECURE`
- `PASSWORD_MIN_LENGTH`
- `PASSWORD_ENFORCE_HISTORY`
- `APP_KEY` or `TOTP_ENCRYPTION_KEY` for encrypted TOTP secret storage. Use a stable 32-byte random value and never rotate it without decrypting/re-encrypting existing TOTP secrets.
- `ADMIN_AUTH_MODE`
- `SUPER_ADMIN_IP_ALLOWLIST_ENABLED`
- `SUPER_ADMIN_IP_ALLOWLIST`
- Super admin và tenant owner đăng nhập bằng `username` hệ thống; email chỉ dùng cho khôi phục và thông báo
- `ADMIN_AUTH_MODE=hybrid` ưu tiên hash trong panel, chỉ fallback sang Linux một lần để tự đồng bộ lại hash cũ khi đang migration.
- Sau khi xác nhận toàn bộ admin đã đồng bộ xong, chuyển sang `ADMIN_AUTH_MODE=panel`.
- Super admin có thể cập nhật IP allowlist trực tiếp trong trang `Super Admins`
- Mẫu mở ban đầu an toàn cho rollout: `SUPER_ADMIN_IP_ALLOWLIST=0.0.0.0/0`
- Super admins should enable TOTP immediately after first login

## Production Checklist
- Replace snakeoil certificates with real TLS certificates
- Verify these ports are listening: `25`, `443`, `465`, `587`, `993`, `995`, `4190`, `8686`
- Confirm Exim does not advertise SMTP AUTH on plaintext connections
- Confirm `/webmail/` serves the intended webmail app
- Confirm Fail2ban loads `webmail-auth`
- Re-run tenant isolation tests after each deployment
