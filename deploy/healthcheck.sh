#!/usr/bin/env bash
#
# MailPanel health check.
#
# Read-only: inspects services, ports, permissions, database and TLS, and prints
# what is wrong plus the command to fix it. Changes nothing.
#
# Usage:
#   bash deploy/healthcheck.sh
#   bash deploy/healthcheck.sh --quiet     Only report problems
#
# Exit code 0 when everything passes, 1 when any check fails.
#
set -uo pipefail

APP_ROOT="${APP_ROOT:-/opt/mailpanel}"
SHARED_ENV="${SHARED_ENV:-/etc/mailpanel/.env}"
WEB_USER="${WEB_USER:-www-data}"
AGENT_USER="${AGENT_USER:-mailpanel-agent}"
GENERATED_ROOT="${GENERATED_ROOT:-/var/lib/mailpanel/generated}"

QUIET=0
[[ "${1:-}" == "--quiet" ]] && QUIET=1

if [[ -t 1 ]]; then
  C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'; C_ERR=$'\033[0;31m'; C_INFO=$'\033[0;36m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_INFO=""; C_OFF=""
fi

FAILED=0
WARNED=0

section() { [[ "$QUIET" == "1" ]] || printf '\n%s%s%s\n' "$C_INFO" "$1" "$C_OFF"; }
pass()    { [[ "$QUIET" == "1" ]] || printf '  %s✓%s %s\n' "$C_OK" "$C_OFF" "$1"; }
fail()    { printf '  %s✗%s %s\n' "$C_ERR" "$C_OFF" "$1"; [[ -n "${2:-}" ]] && printf '      fix: %s\n' "$2"; FAILED=$((FAILED + 1)); }
warn()    { printf '  %s!%s %s\n' "$C_WARN" "$C_OFF" "$1"; [[ -n "${2:-}" ]] && printf '      %s\n' "$2"; WARNED=$((WARNED + 1)); }

# ---------------------------------------------------------------- services

section "Services"

for svc in nginx php8.3-fpm mariadb exim4 dovecot rspamd fail2ban; do
  if systemctl is-active --quiet "$svc" 2>/dev/null; then
    pass "$svc running"
  elif systemctl list-unit-files 2>/dev/null | grep -q "^${svc}"; then
    fail "$svc not running" "systemctl status $svc; journalctl -u $svc -n 50"
  else
    warn "$svc not installed"
  fi
done

if systemctl list-unit-files 2>/dev/null | grep -q '^clamav-daemon'; then
  systemctl is-active --quiet clamav-daemon \
    && pass "clamav-daemon running" \
    || warn "clamav-daemon not running" "It is memory-hungry; disable it if this box is small."
fi

# ------------------------------------------------------------------- ports

section "Listening ports"

port_check() {
  local port="$1" label="$2" required="${3:-yes}"
  if ss -lnt 2>/dev/null | awk '{print $4}' | grep -qE "[:.]${port}\$"; then
    pass "$port ($label)"
  elif [[ "$required" == "yes" ]]; then
    fail "$port ($label) not listening"
  else
    warn "$port ($label) not listening"
  fi
}

port_check 80  "http"
port_check 443 "https"
port_check 25  "smtp"
port_check 587 "submission"
port_check 993 "imaps"
port_check 465 "smtps" no
port_check 4190 "managesieve" no

# --------------------------------------------------------------- filesystem

section "Application"

if [[ -d "$APP_ROOT" ]]; then
  pass "$APP_ROOT exists"
else
  fail "$APP_ROOT missing" "Run deploy/install.sh"
fi

if [[ -f "$SHARED_ENV" ]]; then
  perms="$(stat -c '%a %U:%G' "$SHARED_ENV")"
  if [[ "$perms" == "640 root:$WEB_USER" ]]; then
    pass ".env permissions ($perms)"
  else
    fail ".env permissions are $perms, expected 640 root:$WEB_USER" \
         "chown root:$WEB_USER $SHARED_ENV && chmod 640 $SHARED_ENV"
  fi
else
  fail "$SHARED_ENV missing" "Run deploy/install.sh"
fi

# The web user renders config drafts here; the agent activates them. If this is
# not writable by both, config generation fails with a permission error.
if [[ -d "$GENERATED_ROOT" ]]; then
  if sudo -u "$WEB_USER" test -w "$GENERATED_ROOT" 2>/dev/null; then
    pass "$GENERATED_ROOT writable by $WEB_USER"
  else
    fail "$GENERATED_ROOT not writable by $WEB_USER" \
         "bash $APP_ROOT/deploy/install_agent.sh $APP_ROOT $AGENT_USER $WEB_USER"
  fi
else
  fail "$GENERATED_ROOT missing" "bash $APP_ROOT/deploy/install_agent.sh"
fi

for d in logs sessions cache generated rate_limits app_settings; do
  if [[ -d "$APP_ROOT/storage/$d" ]] && sudo -u "$WEB_USER" test -w "$APP_ROOT/storage/$d" 2>/dev/null; then
    pass "storage/$d writable"
  else
    fail "storage/$d not writable by $WEB_USER" \
         "install -d -m 0770 -o $WEB_USER -g $WEB_USER $APP_ROOT/storage/$d"
  fi
done

# Regression guard: this repository once carried the production root password in
# 223 committed files.
if grep -rlE '(password|passwd)[[:space:]]*=[[:space:]]*["'"'"'][^"'"'"']{6,}' \
     --include='*.py' --include='*.sh' \
     --exclude-dir=vendor --exclude-dir=tests \
     "$APP_ROOT" 2>/dev/null | head -1 | grep -q .; then
  fail "files with literal password assignments found under $APP_ROOT" \
       "grep -rlE '(password|passwd)[[:space:]]*=' --include='*.py' --include='*.sh' $APP_ROOT"
else
  pass "no committed credentials"
fi

# --------------------------------------------------------------- privileges

section "Privilege separation"

if [[ -f /usr/local/bin/mailpanel-system-wrapper ]]; then
  perms="$(stat -c '%a %U:%G' /usr/local/bin/mailpanel-system-wrapper)"
  [[ "$perms" == "755 root:root" ]] \
    && pass "wrapper $perms" \
    || fail "wrapper is $perms, expected 755 root:root"
else
  fail "mailpanel-system-wrapper missing" "bash $APP_ROOT/deploy/install_agent.sh"
fi

if pgrep -u root -f 'php-fpm.*pool www' >/dev/null 2>&1; then
  fail "a PHP-FPM worker is running as root" "Check the pool user in /etc/php/8.3/fpm/pool.d/www.conf"
else
  pass "PHP-FPM not running as root"
fi

if id -nG "$WEB_USER" 2>/dev/null | tr ' ' '\n' | grep -qx "$AGENT_USER"; then
  pass "$WEB_USER is in the $AGENT_USER group"
else
  fail "$WEB_USER is not in the $AGENT_USER group" \
       "usermod -aG $AGENT_USER $WEB_USER && systemctl restart php8.3-fpm"
fi

# ---------------------------------------------------------------- database

section "Database"

# getcwd(), not __DIR__: inside `php -r` the latter does not reliably resolve to
# the application root.
if [[ -f "$SHARED_ENV" ]] && (cd "$APP_ROOT" 2>/dev/null && php -r '
    $root = getcwd();
    require $root . "/vendor/autoload.php";
    MailPanel\Bootstrap\Environment::load($root);
    $c = require $root . "/config/database.php";
    (new MailPanel\Core\Database($c))->connection();
' >/dev/null 2>&1); then
  pass "database reachable"

  status_out="$(cd "$APP_ROOT" && php scripts/migrate.php --status 2>/dev/null || true)"
  if echo "$status_out" | grep -q '^Pending: 0'; then
    pass "no pending migrations"
  else
    fail "pending migrations" "cd $APP_ROOT && php scripts/migrate.php"
  fi

  if echo "$status_out" | grep -q 'Changed after apply'; then
    warn "a migration file changed after it was applied" \
         "Create a new migration instead of editing an applied one."
  fi
else
  fail "cannot connect to the database" "Check DB_PASSWORD in $SHARED_ENV"
fi

# --------------------------------------------------------------------- TLS

section "TLS"

HOSTNAME_CFG="$(grep -E '^NGINX_SERVER_NAME=' "$SHARED_ENV" 2>/dev/null | cut -d= -f2- || echo '')"
CERT="$(grep -E '^NGINX_TLS_CERTIFICATE=' "$SHARED_ENV" 2>/dev/null | cut -d= -f2- || echo '')"

if [[ -n "$CERT" && -f "$CERT" ]]; then
  issuer="$(openssl x509 -in "$CERT" -noout -issuer 2>/dev/null || echo '')"
  subject="$(openssl x509 -in "$CERT" -noout -subject 2>/dev/null || echo '')"

  if [[ "$issuer" == "$subject" ]]; then
    warn "certificate for $HOSTNAME_CFG is self-signed" \
         "certbot certonly --webroot -w /var/www/acme -d $HOSTNAME_CFG"
  else
    pass "certificate is CA-issued"
  fi

  if openssl x509 -in "$CERT" -noout -checkend 604800 >/dev/null 2>&1; then
    pass "certificate valid for at least 7 more days"
  else
    fail "certificate expires within 7 days" "Renew it: certbot renew"
  fi
else
  fail "certificate not found at $CERT"
fi

# ------------------------------------------------------------------- panel

section "Panel"

code="$(curl -fsS -k -o /dev/null -w '%{http_code}' "https://localhost/admin/login" 2>/dev/null || echo 000)"
case "$code" in
  200|302) pass "/admin/login responds $code" ;;
  000) fail "/admin/login unreachable" "systemctl status nginx php8.3-fpm; tail /var/log/nginx/error.log" ;;
  *) fail "/admin/login responds $code" "tail -50 /var/log/nginx/error.log" ;;
esac

# Anonymous access to the dashboard must redirect to the login page.
code="$(curl -fsS -k -o /dev/null -w '%{http_code}' "https://localhost/admin/dashboard" 2>/dev/null || echo 000)"
if [[ "$code" == "302" ]]; then
  pass "/admin/dashboard redirects when logged out"
else
  fail "/admin/dashboard returned $code without a session, expected 302"
fi

# --------------------------------------------------------------- mail queue

section "Mail"

if command -v exim >/dev/null; then
  queue="$(exim -bpc 2>/dev/null || echo 0)"
  if [[ "$queue" -lt 50 ]]; then
    pass "queue depth $queue"
  else
    warn "queue depth $queue" "exim -bp | head -20"
  fi
fi

if timeout 5 bash -c 'cat < /dev/null > /dev/tcp/gmail-smtp-in.l.google.com/25' 2>/dev/null; then
  pass "outbound port 25 reachable"
else
  warn "outbound port 25 blocked" "Ask your provider to unblock it, or mail will not leave this server."
fi

# ------------------------------------------------------------------ summary

printf '\n'
if [[ "$FAILED" -eq 0 && "$WARNED" -eq 0 ]]; then
  printf '%sAll checks passed.%s\n' "$C_OK" "$C_OFF"
elif [[ "$FAILED" -eq 0 ]]; then
  printf '%s%d warning(s), no failures.%s\n' "$C_WARN" "$WARNED" "$C_OFF"
else
  printf '%s%d check(s) failed, %d warning(s).%s\n' "$C_ERR" "$FAILED" "$WARNED" "$C_OFF"
fi

exit $(( FAILED > 0 ? 1 : 0 ))
