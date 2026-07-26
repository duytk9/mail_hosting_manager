# Deployment Runbook

Deployment target: Ubuntu 22.04 / 24.04, `/opt/mailpanel`.

This replaces the ad-hoc workflow that used ~288 one-off Python scripts at the
repo root, 223 of which contained the production root password in plaintext.
Nothing here stores a credential.

---

## Quick start — fresh server

On a clean Ubuntu box, one command does everything in sections 0–5 below:

```bash
git clone <your-repo-url> /tmp/mailpanel && cd /tmp/mailpanel
sudo bash deploy/install.sh
```

It installs nginx, PHP 8.3, MariaDB, Exim, Dovecot, Rspamd, ClamAV, Fail2ban and
Roundcube; creates the `vmail` user and the database; generates every secret;
installs a self-signed certificate so the panel is reachable straight away; sets
up the privileged agent; runs migrations; creates the first super admin; renders
and applies the mail service configuration; and opens the firewall.

```bash
sudo bash deploy/install.sh --check          # preflight only, changes nothing
sudo bash deploy/install.sh --skip-clamav    # small VPS (ClamAV needs ~1GB)
sudo bash deploy/install.sh --skip-webmail   # no Roundcube
sudo bash deploy/install.sh --unattended     # reads deploy/install.conf
```

It is re-runnable: each step checks whether it is already done.

The admin password is printed once and written to
`/root/mailpanel-credentials.txt`. Move it to a password manager and delete that
file. Every other secret lives in `/etc/mailpanel/.env`.

Afterwards:

```bash
sudo bash deploy/healthcheck.sh
```

Checks services, ports, permissions, privilege separation, database, migrations,
TLS expiry and that `/admin/dashboard` redirects when logged out. Every failure
prints the command that fixes it.

The rest of this document covers the manual path, ongoing deployment, and the
optional admin hostname split.

---

## 0. One-time server hardening (do this first)

The old workflow leaked the root password. Before anything else:

```bash
# On the server, as root
passwd root                       # rotate, then never use it for deployment

adduser --disabled-password --gecos "" deploy
mkdir -p /home/deploy/.ssh && chmod 700 /home/deploy/.ssh
# paste your public key:
nano /home/deploy/.ssh/authorized_keys
chmod 600 /home/deploy/.ssh/authorized_keys
chown -R deploy:deploy /home/deploy/.ssh
```

Give `deploy` only the commands it needs:

```bash
cat >/etc/sudoers.d/mailpanel-deploy <<'EOF'
deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.3-fpm, \
                            /usr/bin/systemctl reload nginx, \
                            /usr/bin/systemctl is-active *
EOF
chmod 0440 /etc/sudoers.d/mailpanel-deploy
```

Disable password SSH:

```bash
# /etc/ssh/sshd_config
PermitRootLogin prohibit-password
PasswordAuthentication no
```

```bash
sshd -t && systemctl reload ssh
```

> Keep a second SSH session open while doing this, so a mistake doesn't lock you out.

---

## 1. First-time application setup

```bash
# On the server
install -d -m 0755 /opt/mailpanel /opt/mailpanel-releases
cd /opt/mailpanel

cp .env.example .env
chmod 0640 .env && chown root:www-data .env
nano .env
```

`.env` values that must be set before the first deploy:

| Key | Notes |
|---|---|
| `APP_KEY` | `base64:` + 32 random bytes. Used for TOTP encryption and the admin password proof. |
| `DB_PASSWORD` | The MariaDB password. Never commit it. |
| `APP_URL` | Public URL of the panel. |
| `NGINX_SERVER_NAME` | Hostname on the TLS certificate. |
| `ACME_EMAIL` | Let's Encrypt registration address. |
| `TOTP_ENCRYPTION_KEY` | Optional; falls back to `APP_KEY`. |

Generate a key:

```bash
echo "base64:$(openssl rand -base64 32)"
```

Then install the privileged agent:

```bash
bash deploy/install_agent.sh /opt/mailpanel mailpanel-agent www-data
```

---

## 2. Database

### Fresh database

```bash
php scripts/migrate.php
php scripts/seed.php
```

### Database that was migrated by the OLD script

The previous `scripts/migrate.php` had no tracking table and re-ran every `.sql`
file on every invocation. Adopt tracking without re-running anything:

```bash
php scripts/migrate.php --status     # inspect first
php scripts/migrate.php --baseline   # record all as applied, execute nothing
```

Then verify the schema actually matches — in particular that foreign keys exist:

```bash
mysql -u mailpanel -p mailpanel -e "
  SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
  WHERE CONSTRAINT_SCHEMA='mailpanel' AND REFERENCED_TABLE_NAME IS NOT NULL;"
```

> **Never** run `--baseline` on a database that has not actually been migrated.
> It records state; it does not create anything.

### Orphaned rows blocking foreign keys

Migration `010` adds foreign keys and will fail if orphaned rows exist. The row
cleanup used to sit inside that migration, which meant it silently re-deleted
data on every run. It now lives in a separate script that reports by default:

```bash
php scripts/cleanup_orphans.php            # report only
php scripts/cleanup_orphans.php --apply    # delete, after typing DELETE
```

Take a database backup first:

```bash
mysqldump -u root -p --single-transaction mailpanel > /root/mailpanel-$(date +%F).sql
```

---

## 2b. Separate hostnames for super admin and tenant admin (optional)

Disabled by default. When enabled, `super_admin` may only use the admin hostname
and `tenant_admin` / `domain_admin` / `support_readonly` may only use the tenant
panel hostname.

### Why hostnames, not ports

Cookies are **not scoped by port** (RFC 6265 §8.5). Putting the admin console on
`mail.example.com:8443` and tenants on `mail.example.com:443` means both share one
session — a tenant admin can type the port and walk in. Only a different hostname
separates the cookie.

A non-standard port can be added on top (`ADMIN_HTTPS_PORT`), but it is
obfuscation. The hostname and the IP allowlist are what actually restrict access.

### Setup

1. DNS: point `admin.example.com` at the server.
2. `.env`:

```ini
ADMIN_HOSTNAME=admin.example.com
PANEL_HOSTNAME=panel.example.com
ADMIN_IP_ALLOWLIST=203.0.113.5,198.51.100.0/24
ADMIN_IP_ALLOWLIST_ENFORCED=true
SESSION_COOKIE_DOMAIN=
```

> `SESSION_COOKIE_DOMAIN` **must stay empty**. A parent-domain cookie
> (`.example.com`) would be valid on both hosts and defeat the split entirely.
> The app refuses to boot in that combination rather than run a control that
> looks configured but isn't.

3. Issue a certificate for the admin hostname (Admin UI → Domains → Issue SSL, or
   the ACME API).
4. Regenerate and apply the nginx config (Admin UI → Config Versions).

### Enforcement layers

| Layer | What it does | If it fails |
|---|---|---|
| nginx `allow`/`deny` on the admin vhost | Blocks by source IP at the edge | App still blocks |
| `AdminHostPolicy` in the router | Refuses a `super_admin` session on any host but the admin hostname, and a tenant role on the admin hostname | Request denied with 403 |
| Host-only session cookie | A session issued on one host is not sent to the other | — |
| `SUPER_ADMIN_IP_ALLOWLIST` | Existing login-time IP check | Login refused |

The admin vhost also returns 404 for `/webmail` and `/qa/`, and sets
`X-Frame-Options: DENY` with `frame-ancestors 'none'`.

### Verify after enabling

```bash
# super admin session on the tenant hostname -> 403
curl -k -I https://panel.example.com/admin/dashboard -b "mailpanel_session=<super_admin_session>"

# tenant admin on the admin hostname -> 403
curl -k -I https://admin.example.com/admin/dashboard -b "mailpanel_session=<tenant_session>"

# from outside the allowlist -> 403 at nginx
curl -k -I https://admin.example.com/admin/login
```

---

## 3. Deployment: choose push or pull

Two supported models. Pick one per install; do not mix them.

| | `deploy/deploy.sh` (push) | `deploy/deploy-from-git.sh` (pull) |
|---|---|---|
| Runs on | Your workstation | The server |
| Source | Your working tree, via rsync | `git clone/fetch` on the server |
| Needs | SSH access from your machine | A read-only deploy key on the server |
| Best for | Solo work, quick iteration | CI, audit trail, several people deploying |

The pull model is the better default: the deployed revision is a git SHA recorded
in `$APP_ROOT/REVISION`, so what is running is always identifiable.

### Pull model (recommended)

On the server, once:

```bash
sudo install -d -m 0755 /etc/mailpanel
sudo install -m 0644 deploy/deploy-from-git.conf.example /etc/mailpanel/deploy.conf
sudo nano /etc/mailpanel/deploy.conf      # set GIT_REMOTE, GIT_REF

ssh-keygen -t ed25519 -C 'mailpanel-deploy' -f ~/.ssh/id_ed25519 -N ''
deploy/deploy-from-git.sh --bootstrap     # prints the public key to register
```

Add that public key to the repository as a **read-only** deploy key. Then:

```bash
deploy/deploy-from-git.sh --dry-run
deploy/deploy-from-git.sh
deploy/deploy-from-git.sh --ref v1.4.0    # deploy a tag
deploy/deploy-from-git.sh --status
deploy/deploy-from-git.sh --rollback
```

Secrets live in `/etc/mailpanel/.env`, outside every release directory, so a
deploy never overwrites them and a rollback never reverts them.

### Push model

On your machine:

```bash
cp deploy/deploy.env.example deploy/deploy.env
nano deploy/deploy.env        # set SSH_HOST, SSH_USER, SSH_PORT
ssh-add ~/.ssh/id_ed25519     # load your key
```

`deploy/deploy.env` is gitignored and contains no password.

---

## 4. Deploy

```bash
deploy/deploy.sh --dry-run   # always start here
deploy/deploy.sh
```

What it does, in order:

1. Refuses to run if any file in the repo contains a literal password assignment
2. Creates `/opt/mailpanel-releases/<timestamp>` seeded from the current release
3. rsyncs the code (excluding `.env`, `tests/`, `storage/*`, `*.py`)
4. Copies the server's existing `.env` into the new release
5. `composer install --no-dev --optimize-autoloader`
6. Sets ownership `root:www-data`, dirs `0750`, files `0640`
7. Runs migrations — **on failure it stops and the live site is untouched**
8. Atomically repoints the `/opt/mailpanel` symlink and reloads PHP-FPM
9. Health-checks `/admin/login`
10. Prunes old releases, keeping the last 5

Other modes:

```bash
deploy/deploy.sh --status         # releases, service state, pending migrations
deploy/deploy.sh --skip-migrate   # code only
deploy/deploy.sh --rollback       # repoint to the previous release
```

---

## 5. Mail service configuration

Application config for Exim/Dovecot/Rspamd/Fail2ban/Nginx is **not** deployed by
`deploy.sh`. It is generated, validated and applied through the panel so every
change is versioned and rollback-able:

1. Admin UI → **Config Versions** → Generate
2. Review the draft
3. Apply — the agent validates first, activates, reloads, and rolls back on failure

Or via API:

```bash
curl -X POST https://mail.example.com/api/admin/config-versions/generate \
  -H "Authorization: Bearer $TOKEN"
curl -X POST https://mail.example.com/api/admin/config-versions/apply \
  -H "Authorization: Bearer $TOKEN" -d '{"version_id":123,"simulate":false}'
```

`simulate: true` (the default) performs a dry run.

---

## 6. Post-deploy verification

```bash
deploy/deploy.sh --status

# On the server
curl -I -k https://localhost/admin/login          # expect 200 or 302
systemctl status nginx php8.3-fpm exim4 dovecot rspamd fail2ban
tail -50 /var/log/mailpanel/agent.log
tail -50 /var/log/nginx/error.log
exim -bpc                                          # queue depth
```

Check the security posture is intact after any deploy:

- `/admin/login` is reachable without a session
- `/admin/dashboard` redirects to `/admin/login` when logged out
- `/admin/queue` redirects a tenant_admin to the dashboard, not a bare 403
- A tenant_admin sees only their own domains and mailboxes

---

## 7. Rollback

Code:

```bash
deploy/deploy.sh --rollback
```

Mail service config: Admin UI → Config Versions → Rollback (or the rollback API).

Database: migrations are forward-only. Restore from the dump taken in step 2.

---

## Troubleshooting

**"Cannot connect ... BatchMode"** — the script never accepts a password. Load
your key: `ssh-add -l` should list it, and `ssh -p PORT deploy@HOST true` should
succeed silently.

**"Refusing to deploy: files containing literal password assignments"** — working
as intended. Move the value into the server's `.env`.

**Migration failed** — the release was not activated; the live site is unchanged.
Read the error (it names the exact statement), fix the migration, redeploy.

**Duplicate migration number** — two files share a numeric prefix, so execution
order would depend on filename sorting. Renumber one.

**500 after deploy** — check `/var/log/nginx/error.log` and the PHP-FPM log.
Most common cause is `.env` permissions: it must be readable by `www-data`
(`chown root:www-data .env && chmod 0640 .env`).

**Config apply fails validation** — expected and safe; the agent validates in a
temp copy before touching `/etc`. Read the validator output in the UI.
