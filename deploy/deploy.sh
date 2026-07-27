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
PREVIOUS_LINK="${PREVIOUS_LINK:-${APP_ROOT}-previous}"
DEPLOY_LOCK_DIR="${DEPLOY_LOCK_DIR:-${RELEASES_ROOT}/.deploy-lock}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
WEB_USER="${WEB_USER:-www-data}"
AGENT_USER="${AGENT_USER:-mailpanel-agent}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
UPGRADE_ROUNDCUBE="${UPGRADE_ROUNDCUBE:-1}"
ROUNDCUBE_VERSION="${ROUNDCUBE_VERSION:-1.6.17}"
ALLOW_DIRTY_DEPLOY="${ALLOW_DIRTY_DEPLOY:-0}"
DEPLOY_BRANCH="${DEPLOY_BRANCH:-main}"

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
command -v git >/dev/null || die "git is required on this machine."

safe_absolute_path() {
  local value="${1:-}"
  [[ "$value" =~ ^/[A-Za-z0-9._/-]+$ && "$value" != "/" && "$value" != *"/../"* ]]
}

for configured_path in \
  "$APP_ROOT" "$RELEASES_ROOT" "$SHARED_ENV" "$SHARED_STORAGE_ROOT" \
  "$PREVIOUS_LINK" "$DEPLOY_LOCK_DIR"; do
  safe_absolute_path "$configured_path" || die "Unsafe absolute path in deployment configuration: $configured_path"
done
[[ "$SSH_HOST" =~ ^[A-Za-z0-9.-]+$ ]] || die "Invalid SSH_HOST."
[[ "$SSH_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid SSH_USER."
[[ "$SSH_PORT" =~ ^[0-9]{1,5}$ && "$SSH_PORT" -ge 1 && "$SSH_PORT" -le 65535 ]] || die "Invalid SSH_PORT."
[[ "$WEB_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid WEB_USER."
[[ "$AGENT_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid AGENT_USER."
[[ "$PHP_FPM_SERVICE" =~ ^[A-Za-z0-9@_.-]+$ ]] || die "Invalid PHP_FPM_SERVICE."
[[ "$DEPLOY_BRANCH" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$DEPLOY_BRANCH" != *".."* ]] \
  || die "Invalid DEPLOY_BRANCH."
[[ "$KEEP_RELEASES" =~ ^[1-9][0-9]*$ ]] || die "KEEP_RELEASES must be a positive integer."
[[ "$UPGRADE_ROUNDCUBE" =~ ^[01]$ ]] || die "UPGRADE_ROUNDCUBE must be 0 or 1."
[[ "$ALLOW_DIRTY_DEPLOY" =~ ^[01]$ ]] || die "ALLOW_DIRTY_DEPLOY must be 0 or 1."
[[ "$DEPLOY_LOCK_DIR" == "$RELEASES_ROOT"/.deploy-* ]] \
  || die "DEPLOY_LOCK_DIR must be a hidden deployment directory directly under RELEASES_ROOT."

SSH=(ssh -p "$SSH_PORT" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "${SSH_USER}@${SSH_HOST}")

remote() { "${SSH[@]}" "$@"; }

remote_root() {
  if [[ "$SSH_USER" == "root" ]]; then
    printf '%s\n' "$1" | "${SSH[@]}" bash -se
  else
    printf '%s\n' "$1" | "${SSH[@]}" sudo -n bash -se
  fi
}

LOCK_TOKEN="$(date -u +%Y%m%dT%H%M%SZ)-$$-$RANDOM"
LOCK_ACQUIRED=0

acquire_remote_lock() {
  remote_root "set -euo pipefail
    install -d -m 0755 '$RELEASES_ROOT'
    if [[ -d '$DEPLOY_LOCK_DIR' ]] && find '$DEPLOY_LOCK_DIR' -maxdepth 0 -mmin +120 -print -quit | grep -q .; then
      rm -rf -- '$DEPLOY_LOCK_DIR'
    fi
    mkdir '$DEPLOY_LOCK_DIR' 2>/dev/null || {
      echo 'Another MailPanel deployment is already running.' >&2
      exit 1
    }
    printf '%s\n' '$LOCK_TOKEN' >'$DEPLOY_LOCK_DIR/token'
    chmod 0700 '$DEPLOY_LOCK_DIR'
    chmod 0600 '$DEPLOY_LOCK_DIR/token'"
  LOCK_ACQUIRED=1
}

release_remote_lock() {
  [[ "$LOCK_ACQUIRED" == "1" ]] || return 0
  remote_root "if [[ -f '$DEPLOY_LOCK_DIR/token' ]] && [[ \"\$(cat '$DEPLOY_LOCK_DIR/token')\" == '$LOCK_TOKEN' ]]; then
    rm -rf -- '$DEPLOY_LOCK_DIR'
  fi" >/dev/null 2>&1 || true
  LOCK_ACQUIRED=0
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
  remote_root "readlink -f '$PREVIOUS_LINK' 2>/dev/null || echo '(no previous release)'"
  remote_root "find '$RELEASES_ROOT' -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
    | grep -E '^[0-9]{8}-[0-9]{6}(-[0-9a-f]{7,40})?(-[0-9]+)?$' | sort -r | head -5 \
    || echo '(no releases directory)'"
  step "Service state"
  remote_root "systemctl is-active nginx $PHP_FPM_SERVICE exim4 dovecot rspamd fail2ban 2>&1 | paste -d' ' - - - - - - || true"
  step "Pending migrations"
  remote_root "cd '$APP_ROOT' && runuser -u '$WEB_USER' -- php scripts/migrate.php --status 2>&1 || true"
  exit 0
fi

if [[ "$MODE" == "rollback" ]]; then
  acquire_remote_lock || die "Cannot acquire the deployment lock."
  trap release_remote_lock EXIT
  step "Rolling back to the previous release"
  remote_root "set -euo pipefail
    current=\$(readlink -f '$APP_ROOT' 2>/dev/null || true)
    prev=\$(readlink -f '$PREVIOUS_LINK' 2>/dev/null || true)
    if [[ -z \"\$prev\" || ! -d \"\$prev\" || \"\$prev\" != '$RELEASES_ROOT'/* || \"\$prev\" == \"\$current\" ]]; then
      prev=\$(find '$RELEASES_ROOT' -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
        | grep -E '^[0-9]{8}-[0-9]{6}(-[0-9a-f]{7,40})?(-[0-9]+)?$' | sort -r \
        | while read -r name; do
            path='$RELEASES_ROOT'/\"\$name\"
            [[ \"\$path\" == \"\$current\" ]] || { printf '%s\n' \"\$path\"; break; }
          done)
    fi
    [[ -n \"\$prev\" && -d \"\$prev\" && \"\$prev\" == '$RELEASES_ROOT'/* ]] \
      || { echo 'No valid previous release to roll back to.' >&2; exit 1; }
    ln -sfn \"\$prev\" '$APP_ROOT.new'
    mv -Tf '$APP_ROOT.new' '$APP_ROOT'
    if ! bash \"\$prev/deploy/install_agent.sh\" '$APP_ROOT' '$AGENT_USER' '$WEB_USER' \"\$prev\" \
       || ! systemctl reload '$PHP_FPM_SERVICE' \
       || ! bash \"\$prev/deploy/healthcheck.sh\" --quiet; then
      if [[ -n \"\$current\" && -d \"\$current\" ]]; then
        ln -sfn \"\$current\" '$APP_ROOT.new'
        mv -Tf '$APP_ROOT.new' '$APP_ROOT'
        bash \"\$current/deploy/install_agent.sh\" '$APP_ROOT' '$AGENT_USER' '$WEB_USER' \"\$current\" || true
        systemctl reload '$PHP_FPM_SERVICE' || true
      fi
      echo 'Rollback failed validation; the original release was restored.' >&2
      exit 1
    fi
    if [[ -n \"\$current\" && -d \"\$current\" ]]; then
      ln -sfn \"\$current\" '$PREVIOUS_LINK.new'
      mv -Tf '$PREVIOUS_LINK.new' '$PREVIOUS_LINK'
    fi
    echo \"rolled back to \$(basename \"\$prev\")\""
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

CURRENT_BRANCH="$(git -C "$REPO_ROOT" branch --show-current)"
[[ "$CURRENT_BRANCH" == "$DEPLOY_BRANCH" ]] \
  || die "Refusing to deploy branch '$CURRENT_BRANCH'; DEPLOY_BRANCH is '$DEPLOY_BRANCH'."

if [[ "$ALLOW_DIRTY_DEPLOY" == "0" ]] && [[ -n "$(git -C "$REPO_ROOT" status --porcelain)" ]]; then
  die "The worktree is dirty. Commit and push the exact release before deploying."
fi

REVISION_FULL="$(git -C "$REPO_ROOT" rev-parse HEAD)"
REVISION="$(git -C "$REPO_ROOT" rev-parse --short=12 HEAD)"
RELEASE="$(date -u +%Y%m%d-%H%M%S)-$REVISION-$$"
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

acquire_remote_lock || die "Cannot acquire the deployment lock."
ACTIVE_BEFORE="$(remote_root "readlink -f '$APP_ROOT' 2>/dev/null || true")"
RELEASE_CREATED=0
AGENT_REFRESHED=0
ACTIVATED=0
DEPLOY_SUCCEEDED=0

cleanup_failed_deploy() {
  local status=$?
  trap - EXIT
  set +e

  if [[ "$status" != "0" && "$DEPLOY_SUCCEEDED" != "1" ]]; then
    warn "deployment failed; restoring the previously active release"
    remote_root "set +e
      active_before='$ACTIVE_BEFORE'
      if [[ '$ACTIVATED' == '1' && -n \"\$active_before\" && -d \"\$active_before\" && \"\$active_before\" == '$RELEASES_ROOT'/* ]]; then
        ln -sfn \"\$active_before\" '$APP_ROOT.new'
        mv -Tf '$APP_ROOT.new' '$APP_ROOT'
        systemctl reload '$PHP_FPM_SERVICE' || true
      fi
      if [[ '$AGENT_REFRESHED' == '1' && -n \"\$active_before\" && -d \"\$active_before\" ]]; then
        bash \"\$active_before/deploy/install_agent.sh\" '$APP_ROOT' '$AGENT_USER' '$WEB_USER' \"\$active_before\" || true
      fi
      if [[ '$RELEASE_CREATED' == '1' && '$RELEASE_DIR' != \"\$(readlink -f '$APP_ROOT' 2>/dev/null || true)\" ]]; then
        rm -rf -- '$RELEASE_DIR'
      fi" >/dev/null 2>&1 || true
  fi

  release_remote_lock
  exit "$status"
}

trap cleanup_failed_deploy EXIT

step "Creating release directory on the server"
remote_root "install -d -m 0755 '$RELEASES_ROOT' '$RELEASE_DIR'
  # Seed the new release from the current one so rsync only sends the delta.
  if [[ -d '$APP_ROOT' ]]; then cp -a '$APP_ROOT'/. '$RELEASE_DIR'/ 2>/dev/null || true; fi
  chown -R '$SSH_USER' '$RELEASE_DIR'"
RELEASE_CREATED=1

step "Transferring application code"
rsync "${RSYNC_OPTS[@]}" "$REPO_ROOT/" "${SSH_USER}@${SSH_HOST}:$RELEASE_DIR/"
remote_root "printf '%s\n' '$REVISION_FULL' >'$RELEASE_DIR/REVISION'"
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
       && [[ -d '$APP_ROOT/storage'/\"\$d\" ]] \
       && [[ \"\$(readlink -f '$APP_ROOT/storage'/\"\$d\")\" != \"\$(readlink -f \"\$shared_dir\")\" ]]; then
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
AGENT_REFRESHED=1
ok "agent, wrapper and sudoers rules refreshed"

if [[ "$SKIP_MIGRATE" == "0" ]]; then
  step "Running database migrations"
  remote_root "cd '$RELEASE_DIR' && runuser -u '$WEB_USER' -- php scripts/migrate.php" \
    || die "Migration failed. The release was NOT activated; the running site is untouched."
  ok "migrations applied"
else
  warn "migrations skipped (--skip-migrate)"
fi

if [[ "$UPGRADE_ROUNDCUBE" == "1" ]]; then
  step "Checking Roundcube $ROUNDCUBE_VERSION"
  remote_root "ROUNDCUBE_VERSION='$ROUNDCUBE_VERSION' WEB_USER='$WEB_USER' PHP_FPM_SERVICE='$PHP_FPM_SERVICE' \
    bash '$RELEASE_DIR/deploy/upgrade_roundcube.sh' --if-installed"
  ok "Roundcube check complete"
fi

step "Activating the release"
ACTIVATED=1
remote_root "set -euo pipefail
  active_before='$ACTIVE_BEFORE'
  if [[ -n \"\$active_before\" && -d \"\$active_before\" && \"\$active_before\" == '$RELEASES_ROOT'/* ]]; then
    ln -sfn \"\$active_before\" '$PREVIOUS_LINK.new'
    mv -Tf '$PREVIOUS_LINK.new' '$PREVIOUS_LINK'
  fi
  ln -sfn '$RELEASE_DIR' '$APP_ROOT'.new
  if [[ -e '$APP_ROOT' && ! -L '$APP_ROOT' ]]; then
    [[ -d '$APP_ROOT' ]] || { echo '$APP_ROOT exists and is not a directory.' >&2; exit 1; }
    mv '$APP_ROOT' '$RELEASES_ROOT/${RELEASE}-legacy'
  fi
  mv -Tf '$APP_ROOT'.new '$APP_ROOT'
  systemctl reload '$PHP_FPM_SERVICE'"
ok "release $RELEASE is live"

step "Strict post-deploy health check"
remote_root "bash '$RELEASE_DIR/deploy/healthcheck.sh' --quiet" \
  || die "Health check failed; the previous release will be restored."
code="$(remote "curl -ksS -o /dev/null -w '%{http_code}' https://localhost/admin/login" 2>/dev/null || true)"
[[ "$code" =~ ^(200|302)$ ]] \
  || die "Admin login health check returned ${code:-no response}; the previous release will be restored."
ok "healthcheck passed; admin login responded $code"

step "Pruning old releases (keeping $KEEP_RELEASES)"
remote_root "set -euo pipefail
  active_now=\$(readlink -f '$APP_ROOT' 2>/dev/null || true)
  previous_now=\$(readlink -f '$PREVIOUS_LINK' 2>/dev/null || true)
  count=0
  while read -r old; do
    [[ -n \"\$old\" ]] || continue
    old_path='$RELEASES_ROOT'/\"\$old\"
    count=\$((count + 1))
    if [[ \"\$count\" -le '$KEEP_RELEASES' || \"\$old_path\" == \"\$active_now\" || \"\$old_path\" == \"\$previous_now\" ]]; then
      continue
    fi
    rm -rf -- \"\$old_path\"
  done < <(find '$RELEASES_ROOT' -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
    | grep -E '^[0-9]{8}-[0-9]{6}(-[0-9a-f]{7,40})?(-[0-9]+)?$' | sort -r)"
ok "done"

DEPLOY_SUCCEEDED=1
printf '\n%sDeployed %s to %s%s\n' "$C_OK" "$RELEASE" "$SSH_HOST" "$C_OFF"
printf 'Roll back with: deploy/deploy.sh --rollback\n'
