#!/usr/bin/env bash
#
# MailPanel — full server bootstrap for a clean Ubuntu 22.04 / 24.04 host.
#
# Installs and wires: nginx, PHP-FPM, MariaDB, Exim, Dovecot, Rspamd, ClamAV,
# Fail2ban, Roundcube webmail, the privileged agent, the database, and the first
# super admin account.
#
# Designed to be re-runnable: every step checks whether it is already done.
#
# Usage:
#   sudo bash deploy/install.sh                    Interactive
#   sudo bash deploy/install.sh --unattended       Read deploy/install.conf, ask nothing
#   sudo bash deploy/install.sh --check            Preflight only, change nothing
#   sudo bash deploy/install.sh --skip-webmail     Leave Roundcube out
#   sudo bash deploy/install.sh --skip-clamav      Leave ClamAV out (saves ~1GB RAM)
#
# TLS: a self-signed certificate is installed so the panel is reachable
# immediately. Request a real certificate afterwards — the script prints the
# exact command at the end.
#
set -euo pipefail

# ----------------------------------------------------------------- constants

APP_ROOT_DEFAULT=/opt/mailpanel
SHARED_ENV=/etc/mailpanel/.env
VMAIL_UID=2000
VMAIL_GID=2000
WEB_USER=www-data
AGENT_USER=mailpanel-agent
GENERATED_ROOT=/var/lib/mailpanel/generated
TLS_SNI_ROOT=/etc/mailpanel/tls/sni
ACME_WEBROOT=/var/www/acme
WEBMAIL_ROOT=/var/www/webmail
ROUNDCUBE_VERSION="${ROUNDCUBE_VERSION:-1.6.9}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_FILE=/var/log/mailpanel-install.log

# ------------------------------------------------------------------- options

UNATTENDED=0
CHECK_ONLY=0
WITH_WEBMAIL=1
WITH_CLAMAV=1

while [[ $# -gt 0 ]]; do
  case "$1" in
    --unattended) UNATTENDED=1; shift ;;
    --check) CHECK_ONLY=1; shift ;;
    --skip-webmail) WITH_WEBMAIL=0; shift ;;
    --skip-clamav) WITH_CLAMAV=0; shift ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

# shellcheck source=/dev/null
[[ -f "$SCRIPT_DIR/install.conf" ]] && source "$SCRIPT_DIR/install.conf"

# -------------------------------------------------------------------- output

if [[ -t 1 ]]; then
  C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'; C_ERR=$'\033[0;31m'; C_INFO=$'\033[0;36m'; C_DIM=$'\033[0;90m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_INFO=""; C_DIM=""; C_OFF=""
fi

STEP_NO=0
step() { STEP_NO=$((STEP_NO + 1)); printf '\n%s[%02d]%s %s\n' "$C_INFO" "$STEP_NO" "$C_OFF" "$1" | tee -a "$LOG_FILE"; }
ok()   { printf '%s   ok%s %s\n' "$C_OK" "$C_OFF" "$1" | tee -a "$LOG_FILE"; }
skip() { printf '%s skip%s %s\n' "$C_DIM" "$C_OFF" "$1" | tee -a "$LOG_FILE"; }
warn() { printf '%s warn%s %s\n' "$C_WARN" "$C_OFF" "$1" | tee -a "$LOG_FILE"; }
die()  { printf '%s FAIL%s %s\n' "$C_ERR" "$C_OFF" "$1" | tee -a "$LOG_FILE" >&2; exit 1; }

run() { echo "+ $*" >>"$LOG_FILE"; "$@" >>"$LOG_FILE" 2>&1; }

# ----------------------------------------------------------------- preflight

[[ "$(id -u)" == "0" ]] || die "Run as root:  sudo bash deploy/install.sh"

mkdir -p "$(dirname "$LOG_FILE")"
: >"$LOG_FILE"
chmod 0600 "$LOG_FILE"

step "Preflight"

. /etc/os-release 2>/dev/null || die "Cannot read /etc/os-release; this installer targets Ubuntu."
[[ "${ID:-}" == "ubuntu" ]] || warn "Tested on Ubuntu; found ${PRETTY_NAME:-unknown}. Continuing."
case "${VERSION_ID:-}" in
  22.04|24.04) ok "Ubuntu $VERSION_ID" ;;
  *) warn "Ubuntu $VERSION_ID is untested (expected 22.04 or 24.04)." ;;
esac

TOTAL_MB=$(awk '/MemTotal/ {printf "%d", $2/1024}' /proc/meminfo)
if [[ "$TOTAL_MB" -lt 1800 ]]; then
  warn "Only ${TOTAL_MB}MB RAM detected."
  if [[ "$WITH_CLAMAV" == "1" ]]; then
    warn "ClamAV needs roughly 1GB on its own. Re-run with --skip-clamav on a small VPS."
  fi
else
  ok "${TOTAL_MB}MB RAM"
fi

FREE_GB=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
[[ "$FREE_GB" -ge 5 ]] || die "Need at least 5GB free on /, found ${FREE_GB}GB."
ok "${FREE_GB}GB free on /"

# Port 25 is blocked outbound by most cloud providers by default. Better to say
# so now than to debug undelivered mail later.
if command -v timeout >/dev/null && ! timeout 5 bash -c 'cat < /dev/null > /dev/tcp/gmail-smtp-in.l.google.com/25' 2>/dev/null; then
  warn "Outbound port 25 appears blocked. Most providers block it until you ask them to unblock."
fi

for svc in apache2 postfix; do
  if systemctl is-active --quiet "$svc" 2>/dev/null; then
    die "$svc is running and will conflict. Remove or stop it first: systemctl disable --now $svc"
  fi
done

if [[ "$CHECK_ONLY" == "1" ]]; then
  ok "Preflight passed. Nothing was changed."
  exit 0
fi

# ------------------------------------------------------------------- prompts

ask() {
  local var="$1" prompt="$2" default="${3:-}" answer=""

  if [[ -n "${!var:-}" ]]; then
    ok "$prompt: ${!var} (from install.conf)"
    return
  fi

  if [[ "$UNATTENDED" == "1" ]]; then
    [[ -n "$default" ]] || die "$var is required in --unattended mode. Set it in deploy/install.conf."
    printf -v "$var" '%s' "$default"
    return
  fi

  if [[ -n "$default" ]]; then
    read -r -p "  $prompt [$default]: " answer
    answer="${answer:-$default}"
  else
    while [[ -z "$answer" ]]; do read -r -p "  $prompt: " answer; done
  fi

  printf -v "$var" '%s' "$answer"
}

step "Configuration"

ask PANEL_HOSTNAME "Panel hostname (tenant admins log in here)" "$(hostname -f 2>/dev/null || hostname)"
ask ADMIN_EMAIL    "Super admin email"
ask ADMIN_USERNAME "Super admin login username" "opsadmin"
ask ACME_EMAIL     "Email for Let's Encrypt notices" "$ADMIN_EMAIL"
ask APP_ROOT       "Install directory" "$APP_ROOT_DEFAULT"

# Validate before anything is written.
[[ "$PANEL_HOSTNAME" =~ ^[a-zA-Z0-9]([a-zA-Z0-9.-]*[a-zA-Z0-9])?$ ]] || die "Invalid hostname: $PANEL_HOSTNAME"
[[ "$ADMIN_EMAIL" =~ ^[^@[:space:]]+@[^@[:space:]]+\.[a-zA-Z]{2,}$ ]] || die "Invalid email: $ADMIN_EMAIL"
[[ "$ADMIN_USERNAME" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid username: $ADMIN_USERNAME"
[[ "$APP_ROOT" == /* ]] || die "Install directory must be an absolute path."

# Secrets are generated, never prompted, and never echoed.
DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
APP_KEY="base64:$(openssl rand -base64 32)"
ADMIN_PASSWORD="$(openssl rand -base64 18 | tr -d '/+=' | head -c 20)Aa1!"

ok "Panel hostname : $PANEL_HOSTNAME"
ok "Super admin    : $ADMIN_EMAIL ($ADMIN_USERNAME)"
ok "Install dir    : $APP_ROOT"
ok "Webmail        : $([[ "$WITH_WEBMAIL" == "1" ]] && echo yes || echo no)"
ok "ClamAV         : $([[ "$WITH_CLAMAV" == "1" ]] && echo yes || echo no)"

if [[ "$UNATTENDED" == "0" ]]; then
  echo
  read -r -p "  Proceed? [y/N]: " confirm
  [[ "$confirm" =~ ^[Yy]$ ]] || { echo "Aborted."; exit 1; }
fi

# ------------------------------------------------------------------ packages

step "Installing packages (this takes a few minutes)"

export DEBIAN_FRONTEND=noninteractive

# Pre-seed Exim so its package scripts do not open an interactive dialog.
echo "exim4-config exim4/dc_eximconfig_configtype select internet site; mail is sent and received directly using SMTP" | debconf-set-selections
echo "exim4-config exim4/mailname string $PANEL_HOSTNAME" | debconf-set-selections

run apt-get update

PACKAGES=(
  nginx
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl
  php8.3-intl php8.3-gd php8.3-zip php8.3-bcmath
  mariadb-server
  exim4-daemon-heavy
  dovecot-core dovecot-imapd dovecot-pop3d dovecot-lmtpd dovecot-sieve
  dovecot-managesieved dovecot-mysql
  rspamd redis-server
  fail2ban
  certbot
  git curl unzip openssl ca-certificates acl rsync sudo
)

[[ "$WITH_CLAMAV" == "1" ]] && PACKAGES+=(clamav clamav-daemon clamav-freshclam)

# PHP 8.3 is not in the 22.04 archive; add ondrej/php only when needed.
if ! apt-cache show php8.3-fpm >/dev/null 2>&1; then
  warn "php8.3 not in the archive; adding ppa:ondrej/php"
  run apt-get install -y software-properties-common
  run add-apt-repository -y ppa:ondrej/php
  run apt-get update
fi

run apt-get install -y "${PACKAGES[@]}"
ok "packages installed"

if ! command -v composer >/dev/null; then
  step "Installing Composer"
  run bash -c 'curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php'
  run php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
  ok "composer installed"
else
  skip "composer already present"
fi

# --------------------------------------------------------------- system users

step "Creating system users and directories"

if ! getent group vmail >/dev/null; then
  run groupadd -g "$VMAIL_GID" vmail
fi
if ! id -u vmail >/dev/null 2>&1; then
  run useradd -r -u "$VMAIL_UID" -g vmail -d /var/vmail -s /usr/sbin/nologin vmail
fi
install -d -m 0750 -o vmail -g vmail /var/vmail
ok "vmail user and /var/vmail"

install -d -m 0755 /etc/mailpanel "$TLS_SNI_ROOT" "$ACME_WEBROOT"
install -d -m 0755 "$(dirname "$APP_ROOT")"
ok "base directories"

# -------------------------------------------------------------- application

step "Installing the application into $APP_ROOT"

if [[ "$REPO_ROOT" != "$APP_ROOT" ]]; then
  install -d -m 0755 "$APP_ROOT"
  # -a preserves modes; --exclude keeps local dev artefacts out.
  run rsync -a --delete \
    --exclude '.git/' --exclude '.env' --exclude 'vendor/' \
    --exclude 'storage/logs/*' --exclude 'storage/sessions/*' \
    --exclude 'storage/cache/*' --exclude 'storage/generated/*' \
    "$REPO_ROOT/" "$APP_ROOT/"
  ok "source copied"
else
  skip "already running from $APP_ROOT"
fi

cd "$APP_ROOT"
run composer install --no-dev --optimize-autoloader --no-interaction --no-progress
ok "PHP dependencies installed"

# --------------------------------------------------------------- database

step "Configuring MariaDB"

systemctl is-active --quiet mariadb || run systemctl start mariadb
run systemctl enable mariadb

if mysql -N -B -e "SELECT 1 FROM mysql.user WHERE user='mailpanel' AND host='127.0.0.1'" 2>/dev/null | grep -q 1; then
  if [[ -f "$SHARED_ENV" ]]; then
    skip "database user already exists; keeping the password from $SHARED_ENV"
    DB_PASSWORD=""
  else
    # The user exists but the file holding its password is gone, so the password
    # is unrecoverable. Rotate it rather than writing an empty one into .env.
    warn "database user exists but $SHARED_ENV is missing; rotating the password"
    mysql -e "ALTER USER 'mailpanel'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}'; FLUSH PRIVILEGES;"
    ok "database password rotated"
  fi
else
  mysql <<SQL
CREATE DATABASE IF NOT EXISTS mailpanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'mailpanel'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON mailpanel.* TO 'mailpanel'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  ok "database and user created"
fi

# Dovecot needs its own read-only account: it authenticates mail users and must
# not be able to modify the panel's tables.
DOVECOT_DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
if mysql -N -B -e "SELECT 1 FROM mysql.user WHERE user='mailpanel_dovecot' AND host='127.0.0.1'" 2>/dev/null | grep -q 1; then
  skip "dovecot database user already exists"
  DOVECOT_DB_PASSWORD=""
else
  mysql <<SQL
CREATE USER IF NOT EXISTS 'mailpanel_dovecot'@'127.0.0.1' IDENTIFIED BY '${DOVECOT_DB_PASSWORD}';
GRANT SELECT ON mailpanel.mailboxes TO 'mailpanel_dovecot'@'127.0.0.1';
GRANT SELECT ON mailpanel.domains TO 'mailpanel_dovecot'@'127.0.0.1';
GRANT SELECT ON mailpanel.tenants TO 'mailpanel_dovecot'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
  ok "dovecot read-only database user created"
fi

# ------------------------------------------------------------------ .env

step "Writing $SHARED_ENV"

if [[ -f "$SHARED_ENV" ]]; then
  skip "$SHARED_ENV already exists; leaving it alone"
  # Reuse the existing secrets so migrations and the admin step still work.
  # shellcheck disable=SC1090
  DB_PASSWORD="$(grep -E '^DB_PASSWORD=' "$SHARED_ENV" | cut -d= -f2- || true)"
else
  cat >"$SHARED_ENV" <<ENV
# Generated by deploy/install.sh on $(date -u +%FT%TZ)
# This file holds every runtime secret. Never commit it.

APP_ENV=production
APP_DEBUG=false
APP_URL=https://${PANEL_HOSTNAME}
APP_KEY=${APP_KEY}

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mailpanel
DB_USERNAME=mailpanel
DB_PASSWORD=${DB_PASSWORD}

DOVECOT_DB_USERNAME=mailpanel_dovecot
DOVECOT_DB_PASSWORD=${DOVECOT_DB_PASSWORD}

APP_ROOT=${APP_ROOT}
GENERATED_ROOT=${GENERATED_ROOT}
VMAIL_ROOT=/var/vmail
VMAIL_UID=${VMAIL_UID}
VMAIL_GID=${VMAIL_GID}

AGENT_BINARY=/usr/local/bin/mailpanel-agent
WEB_AGENT_BINARY=/usr/local/bin/mailpanel-web-agent
SUDO_BINARY=sudo
AGENT_TIMEOUT_SECONDS=60

NGINX_ROOT=${APP_ROOT}/public
NGINX_SERVER_NAME=${PANEL_HOSTNAME}
NGINX_TLS_CERTIFICATE=${TLS_SNI_ROOT}/${PANEL_HOSTNAME}.pem
NGINX_TLS_PRIVATEKEY=${TLS_SNI_ROOT}/${PANEL_HOSTNAME}.key
NGINX_PHP_FPM_SOCKET=/run/php/php8.3-fpm.sock

WEBMAIL_ENABLED=$([[ "$WITH_WEBMAIL" == "1" ]] && echo true || echo false)
WEBMAIL_PATH=/webmail
WEBMAIL_PUBLIC_ROOT=${WEBMAIL_ROOT}

ACME_EMAIL=${ACME_EMAIL}
ACME_WEBROOT=${ACME_WEBROOT}
ACME_AUTO_ISSUE_ON_DOMAIN_CREATE=true
ACME_DEFAULT_PROFILE=mail_only
TLS_SNI_ROOT=${TLS_SNI_ROOT}

# Separate admin hostname is OFF by default. See docs/DEPLOY_RUNBOOK.md 2b.
# Leave SESSION_COOKIE_DOMAIN empty if you enable it.
ADMIN_HOSTNAME=
PANEL_HOSTNAME=${PANEL_HOSTNAME}
ADMIN_HTTPS_PORT=443
ADMIN_IP_ALLOWLIST=
ADMIN_IP_ALLOWLIST_ENFORCED=true
SESSION_COOKIE_DOMAIN=

SUPER_ADMIN_IP_ALLOWLIST_ENABLED=false
SUPER_ADMIN_IP_ALLOWLIST=0.0.0.0/0
TOTP_ENCRYPTION_KEY=

TENANT_EXPIRY_WARNING_DAYS=14
TENANT_DEFAULT_GRACE_DAYS=7
TENANT_EXPIRED_INBOUND_MODE=accept

RATE_LIMIT_API_MAX_ATTEMPTS=120
RATE_LIMIT_API_WINDOW_SECONDS=60
RATE_LIMIT_ADMIN_LOGIN_MAX_ATTEMPTS=5
RATE_LIMIT_ADMIN_LOGIN_WINDOW_SECONDS=900
RATE_LIMIT_MAILBOX_LOGIN_MAX_ATTEMPTS=5
RATE_LIMIT_MAILBOX_LOGIN_WINDOW_SECONDS=900
RATE_LIMIT_MAILBOX_PASSWORD_CHANGE_MAX_ATTEMPTS=5
RATE_LIMIT_MAILBOX_PASSWORD_CHANGE_WINDOW_SECONDS=900
ENV
  ok "$SHARED_ENV written"
fi

chmod 0640 "$SHARED_ENV"
chown root:"$WEB_USER" "$SHARED_ENV"
install -m 0640 -o root -g "$WEB_USER" "$SHARED_ENV" "$APP_ROOT/.env"
ok "permissions set (root:$WEB_USER 0640)"

# ------------------------------------------------------------------- TLS

step "Installing a self-signed certificate"

CERT="$TLS_SNI_ROOT/${PANEL_HOSTNAME}.pem"
KEY="$TLS_SNI_ROOT/${PANEL_HOSTNAME}.key"

if [[ -f "$CERT" && -f "$KEY" ]]; then
  skip "certificate for $PANEL_HOSTNAME already present"
else
  run openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout "$KEY" -out "$CERT" \
    -subj "/CN=${PANEL_HOSTNAME}" \
    -addext "subjectAltName=DNS:${PANEL_HOSTNAME}"
  chmod 0644 "$CERT"
  chmod 0640 "$KEY"
  chgrp "$WEB_USER" "$KEY"
  ok "self-signed certificate created (browsers will warn until you get a real one)"
fi

# ------------------------------------------------------------------ agent

step "Installing the privileged agent"

run bash "$APP_ROOT/deploy/install_agent.sh" "$APP_ROOT" "$AGENT_USER" "$WEB_USER"
ok "agent, wrapper and sudoers rules installed"

# --------------------------------------------------------------- migrations

step "Running database migrations"

cd "$APP_ROOT"
if ! php scripts/migrate.php 2>&1 | tee -a "$LOG_FILE" | grep -qiE 'applied|up to date'; then
  die "Migrations failed. See $LOG_FILE"
fi
ok "schema up to date"

# ------------------------------------------------------------- admin account

step "Creating the first super admin"

if php bin/admin_account.php status --email="$ADMIN_EMAIL" >/dev/null 2>&1; then
  skip "an account already exists for $ADMIN_EMAIL"
  ADMIN_PASSWORD=""
else
  # Piped on stdin so the password never appears in the process list.
  printf '%s\n' "$ADMIN_PASSWORD" | php bin/admin_account.php create \
    --email="$ADMIN_EMAIL" \
    --username="$ADMIN_USERNAME" \
    --name="Super Admin" \
    --role=super_admin \
    --password-stdin >>"$LOG_FILE" 2>&1 \
    || die "Could not create the super admin. See $LOG_FILE"
  ok "super admin created"
fi

# --------------------------------------------------------- file permissions

step "Setting application permissions"

chown -R root:"$WEB_USER" "$APP_ROOT"
find "$APP_ROOT" -type d -exec chmod 0750 {} +
find "$APP_ROOT" -type f -exec chmod 0640 {} +
chmod 0750 "$APP_ROOT"/deploy/*.sh 2>/dev/null || true

for d in logs sessions cache generated rate_limits app_settings; do
  install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$APP_ROOT/storage/$d"
done
ok "app owned by root:$WEB_USER, storage writable by $WEB_USER"

# --------------------------------------------------------------- webmail

if [[ "$WITH_WEBMAIL" == "1" ]]; then
  step "Installing Roundcube $ROUNDCUBE_VERSION"

  if [[ -f "$WEBMAIL_ROOT/index.php" ]]; then
    skip "webmail already installed at $WEBMAIL_ROOT"
  else
    TARBALL="/tmp/roundcube-${ROUNDCUBE_VERSION}.tar.gz"
    run curl -fsSL -o "$TARBALL" \
      "https://github.com/roundcube/roundcubemail/releases/download/${ROUNDCUBE_VERSION}/roundcubemail-${ROUNDCUBE_VERSION}-complete.tar.gz"

    install -d -m 0755 "$WEBMAIL_ROOT"
    run tar -xzf "$TARBALL" -C "$WEBMAIL_ROOT" --strip-components=1
    rm -f "$TARBALL"

    RC_DB_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | head -c 32)"
    mysql <<SQL
CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'roundcube'@'127.0.0.1' IDENTIFIED BY '${RC_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
    run mysql roundcube -e "source ${WEBMAIL_ROOT}/SQL/mysql.initial.sql"

    RC_DES_KEY="$(openssl rand -base64 24 | head -c 24)"
    cat >"$WEBMAIL_ROOT/config/config.inc.php" <<RC
<?php
// Generated by MailPanel deploy/install.sh
\$config['db_dsnw'] = 'mysql://roundcube:${RC_DB_PASSWORD}@127.0.0.1/roundcube';
\$config['imap_host'] = 'localhost:143';
\$config['smtp_host'] = 'localhost:587';
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';
\$config['support_url'] = '';
\$config['des_key'] = '${RC_DES_KEY}';
\$config['product_name'] = 'Webmail';
\$config['plugins'] = ['archive', 'zipdownload', 'managesieve'];
\$config['skin'] = 'elastic';
\$config['enable_installer'] = false;
\$config['session_lifetime'] = 30;
\$config['log_driver'] = 'file';
RC

    chown -R root:"$WEB_USER" "$WEBMAIL_ROOT"
    find "$WEBMAIL_ROOT" -type d -exec chmod 0750 {} +
    find "$WEBMAIL_ROOT" -type f -exec chmod 0640 {} +
    chown -R "$WEB_USER":"$WEB_USER" "$WEBMAIL_ROOT/temp" "$WEBMAIL_ROOT/logs"
    chmod 0770 "$WEBMAIL_ROOT/temp" "$WEBMAIL_ROOT/logs"
    # The installer directory is a remote-configuration surface; remove it.
    rm -rf "$WEBMAIL_ROOT/installer"
    ok "roundcube installed and locked down"
  fi
fi

# --------------------------------------------------------- generate configs

step "Generating and applying service configuration"

# The panel owns the service configs: it renders them, the agent validates in a
# temp copy, then activates and reloads. Doing it here means the box is working
# before anyone logs in.
cd "$APP_ROOT"

# Written to a file rather than passed to `php -r`, because __DIR__ inside -r
# does not resolve to the application root.
APPLY_SCRIPT="$(mktemp /tmp/mailpanel-apply-XXXXXX.php)"
cat >"$APPLY_SCRIPT" <<'PHP'
<?php
$root = $argv[1];
require $root . '/vendor/autoload.php';
MailPanel\Bootstrap\Environment::load($root);
$app = MailPanel\Bootstrap\ApplicationFactory::create($root);
$svc = $app->resolve(MailPanel\Services\ConfigDeploymentService::class);

$failed = 0;
foreach ($svc->generateDrafts(0) as $draft) {
    $result = $svc->applyVersion((int) $draft['id'], false);
    $stage = $result['stage'] ?? 'unknown';
    printf("  %-10s %s\n", $draft['service'], $stage);
    if ($stage !== 'reload') {
        $failed++;
    }
}
exit($failed === 0 ? 0 : 1);
PHP
chmod 0644 "$APPLY_SCRIPT"

# Pipe into tee only after capturing the real exit status; `cmd | tee` reports
# tee's status, which is always 0.
set +e
sudo -u "$WEB_USER" php "$APPLY_SCRIPT" "$APP_ROOT" >"$APPLY_SCRIPT.out" 2>&1
APPLY_STATUS=$?
set -e
cat "$APPLY_SCRIPT.out" | tee -a "$LOG_FILE"
rm -f "$APPLY_SCRIPT" "$APPLY_SCRIPT.out"

if [[ "$APPLY_STATUS" == "0" ]]; then
  ok "service configuration generated and applied"
else
  warn "config generation did not fully succeed; generate and apply it from the panel after logging in"
fi

# ------------------------------------------------------------------ services

step "Enabling and restarting services"

SERVICES=(nginx php8.3-fpm mariadb exim4 dovecot rspamd redis-server fail2ban)
[[ "$WITH_CLAMAV" == "1" ]] && SERVICES+=(clamav-daemon clamav-freshclam)

for svc in "${SERVICES[@]}"; do
  run systemctl enable "$svc" || true
  if run systemctl restart "$svc"; then
    ok "$svc"
  else
    warn "$svc did not start — journalctl -u $svc"
  fi
done

# ------------------------------------------------------------------ firewall

step "Configuring the firewall"

if command -v ufw >/dev/null; then
  run ufw --force enable || true
  for port in 22 25 80 443 465 587 993 995 4190; do
    run ufw allow "$port"/tcp || true
  done
  ok "ufw allows 22,25,80,443,465,587,993,995,4190"
  warn "Port 22 is open. If SSH runs elsewhere, allow that port before closing 22."
else
  skip "ufw not installed; configure your firewall manually"
fi

# -------------------------------------------------------------------- report

CRED_FILE=/root/mailpanel-credentials.txt
umask 077
{
  echo "MailPanel installation — $(date -u +%FT%TZ)"
  echo
  echo "Panel URL     : https://${PANEL_HOSTNAME}/admin/login"
  echo "Login username: ${ADMIN_USERNAME}"
  echo "Email         : ${ADMIN_EMAIL}"
  [[ -n "$ADMIN_PASSWORD" ]] && echo "Password      : ${ADMIN_PASSWORD}"
  echo
  echo "Secrets live in ${SHARED_ENV}"
  echo "Install log     ${LOG_FILE}"
  echo
  echo "Delete this file once the password is in your password manager."
} >"$CRED_FILE"
chmod 0600 "$CRED_FILE"

printf '\n%s────────────────────────────────────────────────────────────%s\n' "$C_OK" "$C_OFF"
printf '%s MailPanel is installed%s\n' "$C_OK" "$C_OFF"
printf '%s────────────────────────────────────────────────────────────%s\n\n' "$C_OK" "$C_OFF"

printf '  Panel     https://%s/admin/login\n' "$PANEL_HOSTNAME"
printf '  Username  %s\n' "$ADMIN_USERNAME"
if [[ -n "$ADMIN_PASSWORD" ]]; then
  printf '  Password  %s\n' "$ADMIN_PASSWORD"
else
  printf '  Password  (unchanged — the account already existed)\n'
fi
printf '\n  Also saved to %s\n' "$CRED_FILE"

cat <<NEXT

  Next steps
  ──────────
  1. Point DNS at this server:
       ${PANEL_HOSTNAME}.  A    $(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || echo '<this server IP>')

  2. Replace the self-signed certificate once DNS resolves:
       certbot certonly --webroot -w ${ACME_WEBROOT} -d ${PANEL_HOSTNAME} \\
         --email ${ACME_EMAIL} --agree-tos --non-interactive
       cp /etc/letsencrypt/live/${PANEL_HOSTNAME}/fullchain.pem ${CERT}
       cp /etc/letsencrypt/live/${PANEL_HOSTNAME}/privkey.pem  ${KEY}
       chgrp ${WEB_USER} ${KEY} && chmod 0640 ${KEY}
       systemctl reload nginx

  3. Log in, change the password, and enable TOTP.

  4. Ask your provider to unblock outbound port 25 if they have not already.

  5. Add your first domain in the panel, then set its DNS:
       MX, SPF, DKIM and DMARC records are shown on the DNS Checks page.

  Health check:  bash ${APP_ROOT}/deploy/healthcheck.sh

NEXT

if [[ -n "$ADMIN_PASSWORD" ]]; then
  warn "The password above is shown once. Store it now."
fi
