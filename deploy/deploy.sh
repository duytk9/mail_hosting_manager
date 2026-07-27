#!/usr/bin/env bash
#
# MailPanel deployment.
#
# Replaces the ~288 ad-hoc Python scripts that used to live at the repo root, each
# with the production root password hardcoded in it. This script takes no
# credentials: it uses your SSH agent / ~/.ssh/config, so nothing secret is ever
# written to a file in this repository.
#
# Usage:
#   deploy/deploy.sh                       Deploy using the settings in deploy/deploy.env
#   deploy/deploy.sh --dry-run             Show what would transfer, change nothing
#   deploy/deploy.sh --skip-migrate        Deploy code without running migrations
#   deploy/deploy.sh --rollback            Restore the previous release
#   deploy/deploy.sh --status              Show current/previous release and service state
#
# Configuration is read from deploy/deploy.env (gitignored). Copy
# deploy/deploy.env.example and edit. Every value can also be overridden by an
# environment variable of the same name.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# shellcheck source=/dev/null
[[ -f "$SCRIPT_DIR/deploy.env" ]] && source "$SCRIPT_DIR/deploy.env"

SSH_HOST="${SSH_HOST:-}"
SSH_USER="${SSH_USER:-deploy}"
SSH_PORT="${SSH_PORT:-22}"
APP_ROOT="${APP_ROOT:-/opt/mailpanel}"
RELEASES_ROOT="${RELEASES_ROOT:-/opt/mailpanel-releases}"
SHARED_ENV="${SHARED_ENV:-/etc/mailpanel/.env}"
SHARED_STORAGE_ROOT="${SHARED_STORAGE_ROOT:-/var/lib/mailpanel/storage}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
WEB_USER="${WEB_USER:-www-data}"
AGENT_USER="${AGENT_USER:-mailpanel-agent}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"

DRY_RUN=0
SKIP_MIGRATE=0
MODE="deploy"

for arg in "$@"; do
  case "$arg" in
    --dry-run) DRY_RUN=1 ;;
    --skip-migrate) SKIP_MIGRATE=1 ;;
    --rollback) MODE="rollback" ;;
    --status) MODE="status" ;;
    -h|--help) sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

# ------------------------------------------------------------------ output

if [[ -t 1 ]]; then
  C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'; C_ERR=$'\033[0;31m'; C_INFO=$'\033[0;36m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_INFO=""; C_OFF=""
fi

step() { printf '%s==>%s %s\n' "$C_INFO" "$C_OFF" "$1"; }
ok()   { printf '%s  ok%s %s\n' "$C_OK" "$C_OFF" "$1"; }
warn() { printf '%swarn%s %s\n' "$C_WARN" "$C_OFF" "$1"; }
die()  { printf '%sFAIL%s %s\n' "$C_ERR" "$C_OFF" "$1" >&2; exit 1; }

# ------------------------------------------------------------ preflight

[[ -n "$SSH_HOST" ]] || die "SSH_HOST is not set. Copy deploy/deploy.env.example to deploy/deploy.env and edit it."
command -v rsync >/dev/null || die "rsync is required on this machine."
command -v ssh >/dev/null || die "ssh is required on this machine."

SSH=(ssh -p "$SSH_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "${SSH_USER}@${SSH_HOST}")

remote() { "${SSH[@]}" "$@"; }

remote_root() {
  if [[ "$SSH_USER" == "root" ]]; then
    printf '%s\n' "$1" | "${SSH[@]}" bash -se
  else
    printf '%s\n' "$1" | "${SSH[@]}" sudo -n bash -se
  fi
}

step "Checking SSH connectivity to ${SSH_USER}@${SSH_HOST}:${SSH_PORT}"
remote true 2>/dev/null || die "Cannot connect. This script uses key-based auth only (BatchMode). Add your key to the server and load it into ssh-agent."
ok "connected"

if [[ "$SSH_USER" != "root" ]]; then
  remote "sudo -n true" 2>/dev/null \
    || die "${SSH_USER} needs passwordless sudo for deployment operations."
  ok "passwordless sudo available"
fi

if [[ "$MODE" == "status" ]]; then
  step "Release state"
  remote_root "readlink -f '$APP_ROOT' 2>/dev/null || echo '(no current release)'"
  remote_root "ls -1t '$RELEASES_ROOT' 2>/dev/null | head -5 || echo '(no releases directory)'"
  step "Service state"
  remote_root "systemctl is-active nginx $PHP_FPM_SERVICE exim4 dovecot rspamd fail2ban 2>&1 | paste -d' ' - - - - - - || true"
  step "Pending migrations"
  remote_root "cd '$APP_ROOT' && runuser -u '$WEB_USER' -- php scripts/migrate.php --status 2>&1 || true"
  exit 0
fi

if [[ "$MODE" == "rollback" ]]; then
  step "Rolling back to the previous release"
  remote_root "set -euo pipefail
    prev=\$(ls -1t '$RELEASES_ROOT' | sed -n 2p)
    [[ -n \"\$prev\" ]] || { echo 'No previous release to roll back to.' >&2; exit 1; }
    ln -sfn '$RELEASES_ROOT'/\"\$prev\" '$APP_ROOT'
    systemctl reload '$PHP_FPM_SERVICE'
    echo \"rolled back to \$prev\""
  ok "rollback complete"
  exit 0
fi

# ------------------------------------------------------------ safety gate

step "Verifying no credentials are about to be shipped"
# The repo previously contained 223 files with the production root password.
# Refuse to deploy if anything resembling a committed secret reappears.
if grep -rlE '(password|passwd)\s*=\s*["'"'"'][^"'"'"']{6,}' \
     --include='*.py' --include='*.sh' --include='*.php' \
     --exclude-dir=vendor --exclude-dir=node_modules \
     "$REPO_ROOT" 2>/dev/null | grep -v '/tests/' | head -5 | grep -q .; then
  warn "Files containing literal password assignments were found:"
  grep -rlE '(password|passwd)\s*=\s*["'"'"'][^"'"'"']{6,}' \
    --include='*.py' --include='*.sh' --include='*.php' \
    --exclude-dir=vendor --exclude-dir=node_modules \
    "$REPO_ROOT" 2>/dev/null | grep -v '/tests/' | head -10 | sed 's/^/       /'
  die "Refusing to deploy. Move secrets into the server's .env, never into the repo."
fi
ok "no committed credentials detected"

[[ -f "$REPO_ROOT/.env" ]] && warn ".env exists locally and will NOT be transferred (server keeps its own)."

# ------------------------------------------------------------ build

RELEASE="$(date -u +%Y%m%d-%H%M%S)"
RELEASE_DIR="$RELEASES_ROOT/$RELEASE"

step "Preparing release $RELEASE"

RSYNC_OPTS=(-az --delete --human-readable
  --exclude '.git/' --exclude '.env' --exclude '.env.*'
  --exclude 'vendor/' --exclude 'node_modules/'
  --exclude 'storage/logs/*' --exclude 'storage/sessions/*'
  --exclude 'storage/cache/*' --exclude 'storage/generated/*'
  --exclude 'storage/rate_limits/*' --exclude 'storage/app_settings/*'
  --exclude 'tests/' --exclude '.deploy-backups/' --exclude '.codex-backup/'
  --exclude '_backups/' --exclude '_archive/' --exclude 'tools/'
  --exclude '/*.py' --exclude '.vscode/' --exclude '.idea/'
  -e "ssh -p $SSH_PORT -o BatchMode=yes")

if [[ "$DRY_RUN" == "1" ]]; then
  step "Dry run: showing what would transfer"
  RSYNC_DRY_RUN_ROOT=()
  [[ "$SSH_USER" != "root" ]] && RSYNC_DRY_RUN_ROOT=(--rsync-path="sudo -n rsync")
  rsync "${RSYNC_OPTS[@]}" "${RSYNC_DRY_RUN_ROOT[@]}" --dry-run --itemize-changes \
    "$REPO_ROOT/" "${SSH_USER}@${SSH_HOST}:$APP_ROOT/" | head -60
  step "Dry run: pending migrations on the server"
  remote_root "cd '$APP_ROOT' && runuser -u '$WEB_USER' -- php scripts/migrate.php --dry-run 2>&1 || true"
  ok "dry run complete; nothing was changed"
  exit 0
fi

step "Creating release directory on the server"
remote_root "install -d -m 0755 '$RELEASES_ROOT' '$RELEASE_DIR'
  # Seed the new release from the current one so rsync only sends the delta.
  if [[ -d '$APP_ROOT' ]]; then cp -a '$APP_ROOT'/. '$RELEASE_DIR'/ 2>/dev/null || true; fi
  chown -R '$SSH_USER' '$RELEASE_DIR'"

step "Transferring application code"
rsync "${RSYNC_OPTS[@]}" "$REPO_ROOT/" "${SSH_USER}@${SSH_HOST}:$RELEASE_DIR/"
ok "code transferred"

step "Linking the shared server .env into the release"
remote_root "set -euo pipefail
  if [[ ! -f '$SHARED_ENV' ]]; then
    if [[ -f '$APP_ROOT/.env' ]]; then
      install -d -m 0755 \"\$(dirname '$SHARED_ENV')\"
      install -m 0640 -o root -g '$WEB_USER' '$APP_ROOT/.env' '$SHARED_ENV'
    else
      echo 'No shared .env found; create $SHARED_ENV before the first deploy.' >&2
      exit 1
    fi
  fi
  chmod 0640 '$SHARED_ENV'
  chown root:'$WEB_USER' '$SHARED_ENV'
  ln -sfn '$SHARED_ENV' '$RELEASE_DIR/.env'"
ok ".env in place"

step "Installing PHP dependencies (no dev)"
remote "cd '$RELEASE_DIR' && composer install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1 | tail -5"
ok "dependencies installed"

step "Fetching self-hosted UI fonts"
remote "bash '$RELEASE_DIR/deploy/fetch-fonts.sh' '$RELEASE_DIR/public/assets/fonts'"
ok "UI fonts ready"

step "Fixing ownership and permissions"
remote_root "set -euo pipefail
  chown -R root:'$WEB_USER' '$RELEASE_DIR'
  find '$RELEASE_DIR' -type d -exec chmod 0750 {} +
  find '$RELEASE_DIR' -type f -exec chmod 0640 {} +
  chmod 0750 '$RELEASE_DIR/deploy'/*.sh 2>/dev/null || true
  install -d -m 0770 -o '$WEB_USER' -g '$WEB_USER' '$SHARED_STORAGE_ROOT'
  for d in logs sessions cache generated rate_limits app_settings; do
    shared_dir='$SHARED_STORAGE_ROOT'/\"\$d\"
    install -d -m 0770 -o '$WEB_USER' -g '$WEB_USER' \"\$shared_dir\"
    if ! find \"\$shared_dir\" -mindepth 1 -print -quit | grep -q . \
       && [[ -d '$APP_ROOT/storage'/\"\$d\" ]]; then
      cp -a '$APP_ROOT/storage'/\"\$d\"/. \"\$shared_dir\"/
    fi
    chown -R '$WEB_USER':'$WEB_USER' \"\$shared_dir\"
    chmod 0770 \"\$shared_dir\"
    rm -rf '$RELEASE_DIR/storage'/\"\$d\"
    ln -s \"\$shared_dir\" '$RELEASE_DIR/storage'/\"\$d\"
  done"
ok "permissions set"

# The privileged agent lives outside the release directory (/usr/local/bin and
# /usr/local/lib), so a code deploy does not update it. Refresh it from the new
# release before the migrations, or the running agent stays on the old version.
step "Refreshing the privileged agent"
remote_root "bash '$RELEASE_DIR/deploy/install_agent.sh' '$APP_ROOT' '$AGENT_USER' '$WEB_USER' '$RELEASE_DIR'"
ok "agent, wrapper and sudoers rules refreshed"

if [[ "$SKIP_MIGRATE" == "0" ]]; then
  step "Running database migrations"
  remote_root "cd '$RELEASE_DIR' && runuser -u '$WEB_USER' -- php scripts/migrate.php" \
    || die "Migration failed. The release was NOT activated; the running site is untouched."
  ok "migrations applied"
else
  warn "migrations skipped (--skip-migrate)"
fi

step "Activating the release"
remote_root "set -euo pipefail
  ln -sfn '$RELEASE_DIR' '$APP_ROOT'.new
  if [[ -e '$APP_ROOT' && ! -L '$APP_ROOT' ]]; then
    [[ -d '$APP_ROOT' ]] || { echo '$APP_ROOT exists and is not a directory.' >&2; exit 1; }
    mv '$APP_ROOT' '$RELEASES_ROOT/${RELEASE}-legacy'
  fi
  mv -Tf '$APP_ROOT'.new '$APP_ROOT'
  systemctl reload '$PHP_FPM_SERVICE'"
ok "release $RELEASE is live"

step "Health check"
if remote "curl -fsS -o /dev/null -w '%{http_code}' -k https://localhost/admin/login" 2>/dev/null | grep -qE '^(200|302)$'; then
  ok "admin login responds"
else
  warn "health check did not return 200/302 — check 'deploy/deploy.sh --status' and the nginx/php-fpm logs"
fi

step "Pruning old releases (keeping $KEEP_RELEASES)"
remote_root "cd '$RELEASES_ROOT' && ls -1t | tail -n +\$(( $KEEP_RELEASES + 1 )) | xargs -r rm -rf"
ok "done"

printf '\n%sDeployed %s to %s%s\n' "$C_OK" "$RELEASE" "$SSH_HOST" "$C_OFF"
printf 'Roll back with: deploy/deploy.sh --rollback\n'
