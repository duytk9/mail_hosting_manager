#!/usr/bin/env bash
#
# MailPanel deployment — pull model.
#
# Runs ON the Ubuntu server. The server fetches the code from git itself, so
# nothing is pushed from a workstation and no credential ever leaves your machine.
# Use a read-only deploy key for the repository.
#
# The alternative, deploy/deploy.sh, pushes from a workstation over rsync. Use
# whichever fits; do not mix them for the same install.
#
# Usage (as the deploy user, on the server):
#   deploy/deploy-from-git.sh                 Deploy the configured branch
#   deploy/deploy-from-git.sh --ref v1.4.0    Deploy a tag or commit
#   deploy/deploy-from-git.sh --dry-run       Show what would happen
#   deploy/deploy-from-git.sh --skip-migrate  Deploy code only
#   deploy/deploy-from-git.sh --rollback      Restore the previous release
#   deploy/deploy-from-git.sh --status        Show release/service/migration state
#   deploy/deploy-from-git.sh --bootstrap     First-time setup (creates dirs, .env)
#
# Configuration: /etc/mailpanel/deploy.conf (see deploy/deploy-from-git.conf.example)
#
set -euo pipefail

CONF_FILE="${MAILPANEL_DEPLOY_CONF:-/etc/mailpanel/deploy.conf}"
# shellcheck source=/dev/null
[[ -f "$CONF_FILE" ]] && source "$CONF_FILE"

GIT_REMOTE="${GIT_REMOTE:-}"
GIT_REF="${GIT_REF:-main}"
APP_ROOT="${APP_ROOT:-/opt/mailpanel}"
RELEASES_ROOT="${RELEASES_ROOT:-/opt/mailpanel-releases}"
REPO_CACHE="${REPO_CACHE:-/opt/mailpanel-repo}"
SHARED_ENV="${SHARED_ENV:-/etc/mailpanel/.env}"
SHARED_STORAGE_ROOT="${SHARED_STORAGE_ROOT:-/var/lib/mailpanel/storage}"
PREVIOUS_LINK="${PREVIOUS_LINK:-${APP_ROOT}-previous}"
DEPLOY_LOCK_FILE="${DEPLOY_LOCK_FILE:-/var/lock/mailpanel-deploy.lock}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
WEB_USER="${WEB_USER:-www-data}"
AGENT_USER="${AGENT_USER:-mailpanel-agent}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
HEALTHCHECK_HOST="${HEALTHCHECK_HOST:-localhost}"
UPGRADE_ROUNDCUBE="${UPGRADE_ROUNDCUBE:-1}"
ROUNDCUBE_VERSION="${ROUNDCUBE_VERSION:-1.6.17}"

DRY_RUN=0
SKIP_MIGRATE=0
MODE="deploy"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ref) GIT_REF="${2:?--ref needs a value}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --skip-migrate) SKIP_MIGRATE=1; shift ;;
    --rollback) MODE="rollback"; shift ;;
    --status) MODE="status"; shift ;;
    --bootstrap) MODE="bootstrap"; shift ;;
    -h|--help) sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

# ------------------------------------------------------------------- output

if [[ -t 1 ]]; then
  C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'; C_ERR=$'\033[0;31m'; C_INFO=$'\033[0;36m'; C_OFF=$'\033[0m'
else
  C_OK=""; C_WARN=""; C_ERR=""; C_INFO=""; C_OFF=""
fi

step() { printf '%s==>%s %s\n' "$C_INFO" "$C_OFF" "$1"; }
ok()   { printf '%s  ok%s %s\n' "$C_OK" "$C_OFF" "$1"; }
warn() { printf '%swarn%s %s\n' "$C_WARN" "$C_OFF" "$1"; }
die()  { printf '%sFAIL%s %s\n' "$C_ERR" "$C_OFF" "$1" >&2; exit 1; }

as_root() {
  if [[ "$(id -u)" == "0" ]]; then "$@"; else sudo -n "$@"; fi
}

safe_absolute_path() {
  local value="${1:-}"
  [[ "$value" =~ ^/[A-Za-z0-9._/-]+$ && "$value" != "/" && "$value" != *"/../"* ]]
}

for configured_path in \
  "$APP_ROOT" "$RELEASES_ROOT" "$REPO_CACHE" "$SHARED_ENV" \
  "$SHARED_STORAGE_ROOT" "$PREVIOUS_LINK" "$DEPLOY_LOCK_FILE"; do
  safe_absolute_path "$configured_path" || die "Unsafe absolute path in deployment configuration: $configured_path"
done
[[ "$GIT_REF" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ && "$GIT_REF" != *".."* ]] \
  || die "GIT_REF contains unsupported characters."
[[ "$WEB_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid WEB_USER."
[[ "$AGENT_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid AGENT_USER."
[[ "$PHP_FPM_SERVICE" =~ ^[A-Za-z0-9@_.-]+$ ]] || die "Invalid PHP_FPM_SERVICE."
[[ "$COMPOSER_BIN" =~ ^[A-Za-z0-9._/-]+$ ]] || die "Invalid COMPOSER_BIN."
[[ "$HEALTHCHECK_HOST" =~ ^[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]] || die "Invalid HEALTHCHECK_HOST."
[[ "$KEEP_RELEASES" =~ ^[1-9][0-9]*$ ]] || die "KEEP_RELEASES must be a positive integer."
[[ "$UPGRADE_ROUNDCUBE" =~ ^[01]$ ]] || die "UPGRADE_ROUNDCUBE must be 0 or 1."

list_releases() {
  find "$RELEASES_ROOT" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null \
    | grep -E '^[0-9]{8}-[0-9]{6}(-[0-9a-f]{7,40})?(-[0-9]+)?$' \
    | sort -r
}

acquire_deploy_lock() {
  as_root install -d -m 0755 "$(dirname "$DEPLOY_LOCK_FILE")"
  as_root touch "$DEPLOY_LOCK_FILE"
  as_root chown "$(id -u):$(id -g)" "$DEPLOY_LOCK_FILE"
  chmod 0600 "$DEPLOY_LOCK_FILE"
  exec 9>"$DEPLOY_LOCK_FILE"
  flock -n 9 || die "Another MailPanel deployment is already running."
}

valid_release_path() {
  local candidate="${1:-}"
  [[ -n "$candidate" && -d "$candidate" && "$candidate" == "$RELEASES_ROOT"/* ]]
}

fallback_previous_release() {
  local current="${1:-}" release=""
  while IFS= read -r release; do
    [[ "$RELEASES_ROOT/$release" == "$current" ]] && continue
    printf '%s/%s\n' "$RELEASES_ROOT" "$release"
    return 0
  done < <(list_releases)
  return 1
}

run_release_healthcheck() {
  local release_root="$1"
  as_root bash "$release_root/deploy/healthcheck.sh" --quiet
}

# ---------------------------------------------------------------- bootstrap

if [[ "$MODE" == "bootstrap" ]]; then
  step "First-time setup"
  as_root install -d -m 0755 "$RELEASES_ROOT" "$(dirname "$SHARED_ENV")"
  as_root install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$SHARED_STORAGE_ROOT"
  as_root install -d -m 0755 "$(dirname "$REPO_CACHE")"

  if [[ ! -f "$SHARED_ENV" ]]; then
    warn "No $SHARED_ENV yet."
    echo "  1. Copy .env.example from the repo to $SHARED_ENV"
    echo "  2. Set APP_KEY:  echo \"base64:\$(openssl rand -base64 32)\""
    echo "  3. Set DB_PASSWORD, APP_URL, NGINX_SERVER_NAME, ACME_EMAIL"
    echo "  4. chmod 0640 $SHARED_ENV && chown root:$WEB_USER $SHARED_ENV"
  else
    ok "$SHARED_ENV exists"
  fi

  if [[ ! -f "$CONF_FILE" ]]; then
    warn "No $CONF_FILE yet. Copy deploy/deploy-from-git.conf.example to it and set GIT_REMOTE."
  else
    ok "$CONF_FILE exists"
  fi

  echo
  echo "Deploy key for the repository (add as a READ-ONLY key):"
  if [[ -f ~/.ssh/id_ed25519.pub ]]; then
    cat ~/.ssh/id_ed25519.pub
  else
    echo "  No key yet. Generate one:"
    echo "  ssh-keygen -t ed25519 -C 'mailpanel-deploy' -f ~/.ssh/id_ed25519 -N ''"
  fi
  exit 0
fi

# ----------------------------------------------------------------- preflight

command -v git >/dev/null || die "git is not installed. apt-get install -y git"
command -v php >/dev/null || die "php is not installed."
command -v flock >/dev/null || die "flock is not installed."
command -v "$COMPOSER_BIN" >/dev/null || die "composer is not installed or COMPOSER_BIN points nowhere."

if [[ "$MODE" == "status" ]]; then
  step "Releases"
  printf '  current : %s\n' "$(readlink -f "$APP_ROOT" 2>/dev/null || echo '(none)')"
  printf '  previous: %s\n' "$(readlink -f "$PREVIOUS_LINK" 2>/dev/null || echo '(none)')"
  release_list="$(list_releases | head -5)"
  if [[ -n "$release_list" ]]; then
    while IFS= read -r release_name; do
      printf '  %s\n' "$release_name"
    done <<<"$release_list"
  else
    echo "  (no releases)"
  fi
  step "Deployed revision"
  cat "$APP_ROOT/REVISION" 2>/dev/null || echo "  (unknown)"
  step "Services"
  systemctl is-active nginx "$PHP_FPM_SERVICE" exim4 dovecot rspamd fail2ban 2>&1 | paste -sd' ' - || true
  step "Migrations"
  # The positional parameters are intentionally expanded by the child shell.
  # shellcheck disable=SC2016
  as_root bash -c 'cd "$1" && exec runuser -u "$2" -- php scripts/migrate.php --status' \
    _ "$APP_ROOT" "$WEB_USER" || true
  exit 0
fi

if [[ "$MODE" == "rollback" ]]; then
  acquire_deploy_lock
  current="$(readlink -f "$APP_ROOT" 2>/dev/null || true)"
  prev="$(readlink -f "$PREVIOUS_LINK" 2>/dev/null || true)"
  if ! valid_release_path "$prev" || [[ "$prev" == "$current" ]]; then
    prev="$(fallback_previous_release "$current" || true)"
  fi
  valid_release_path "$prev" || die "No valid previous release to roll back to."

  step "Rolling back to $(basename "$prev")"
  as_root ln -sfn "$prev" "$APP_ROOT.new"
  as_root mv -Tf "$APP_ROOT.new" "$APP_ROOT"
  if ! as_root bash "$prev/deploy/install_agent.sh" "$APP_ROOT" "$AGENT_USER" "$WEB_USER" "$prev" \
     || ! as_root systemctl reload "$PHP_FPM_SERVICE" \
     || ! run_release_healthcheck "$prev"; then
    if valid_release_path "$current"; then
      warn "rollback validation failed; restoring $(basename "$current")"
      as_root ln -sfn "$current" "$APP_ROOT.new"
      as_root mv -Tf "$APP_ROOT.new" "$APP_ROOT"
      as_root bash "$current/deploy/install_agent.sh" "$APP_ROOT" "$AGENT_USER" "$WEB_USER" "$current" || true
      as_root systemctl reload "$PHP_FPM_SERVICE" || true
    fi
    die "Rollback failed validation; the original release was restored."
  fi

  if valid_release_path "$current"; then
    as_root ln -sfn "$current" "$PREVIOUS_LINK.new"
    as_root mv -Tf "$PREVIOUS_LINK.new" "$PREVIOUS_LINK"
  fi
  ok "rolled back to $(basename "$prev")"
  warn "Migrations are forward-only. If this release added schema changes, review them."
  exit 0
fi

[[ -n "$GIT_REMOTE" ]] || die "GIT_REMOTE is not set in $CONF_FILE."
[[ -f "$SHARED_ENV" ]] || die "$SHARED_ENV is missing. Run --bootstrap first."
[[ "$GIT_REMOTE" != *$'\n'* && "$GIT_REMOTE" != *$'\r'* ]] || die "GIT_REMOTE contains control characters."

acquire_deploy_lock

# -------------------------------------------------------------------- fetch

step "Fetching $GIT_REF from $GIT_REMOTE"

if [[ ! -d "$REPO_CACHE/.git" ]]; then
  as_root install -d -m 0755 "$REPO_CACHE"
  as_root chown "$(id -u):$(id -g)" "$REPO_CACHE"
  git clone --quiet --no-checkout "$GIT_REMOTE" "$REPO_CACHE"
else
  git -C "$REPO_CACHE" remote set-url origin "$GIT_REMOTE"
  git -C "$REPO_CACHE" fetch --quiet --prune --tags origin
fi

if git -C "$REPO_CACHE" show-ref --verify --quiet "refs/remotes/origin/$GIT_REF"; then
  DEPLOY_REF="refs/remotes/origin/$GIT_REF"
elif git -C "$REPO_CACHE" rev-parse --verify --quiet "${GIT_REF}^{commit}" >/dev/null; then
  DEPLOY_REF="$GIT_REF"
else
  die "Cannot resolve ref '$GIT_REF'."
fi

REVISION_FULL="$(git -C "$REPO_CACHE" rev-parse "${DEPLOY_REF}^{commit}")"
REVISION="$(git -C "$REPO_CACHE" rev-parse --short=12 "$REVISION_FULL")"
SUBJECT="$(git -C "$REPO_CACHE" log -1 --pretty=%s "$REVISION_FULL")"
ok "resolved immutable commit $REVISION — $SUBJECT"

# --------------------------------------------------------------- safety gate

step "Checking the selected commit for committed credentials"
# This repository previously carried the production root password in 223 files.
# Refuse to deploy if that pattern reappears.
secret_matches="$(git -C "$REPO_CACHE" grep -I -l -E \
  '(password|passwd)[[:space:]]*=[[:space:]]*["'"'"'][^"'"'"']{6,}' \
  "$REVISION_FULL" -- '*.py' '*.sh' '*.php' \
  ':(exclude)tests/**' ':(exclude)vendor/**' 2>/dev/null || true)"
if [[ -n "$secret_matches" ]]; then
  head -10 <<<"$secret_matches" | sed 's/^/       /'
  die "Refusing to deploy: the checkout contains literal password assignments."
fi
ok "clean"

RELEASE="$(date -u +%Y%m%d-%H%M%S)-$REVISION-$$"
RELEASE_DIR="$RELEASES_ROOT/$RELEASE"

if [[ "$DRY_RUN" == "1" ]]; then
  step "Dry run"
  printf '  would deploy : %s (%s)\n' "$REVISION" "$SUBJECT"
  printf '  release dir  : %s\n' "$RELEASE_DIR"
  step "Changes since the running release"
  if [[ -f "$APP_ROOT/REVISION" ]]; then
    git -C "$REPO_CACHE" log --oneline "$(cat "$APP_ROOT/REVISION")..$REVISION_FULL" 2>/dev/null \
      | head -20 || echo "  (cannot diff)"
  else
    echo "  (no running release recorded)"
  fi
  step "Pending migrations"
  if valid_release_path "$(readlink -f "$APP_ROOT" 2>/dev/null || true)"; then
    # shellcheck disable=SC2016
    as_root bash -c 'cd "$1" && exec runuser -u "$2" -- php scripts/migrate.php --dry-run' \
      _ "$APP_ROOT" "$WEB_USER" || echo "  (cannot check)"
  else
    echo "  (no running release)"
  fi
  ok "nothing was changed"
  exit 0
fi

# ------------------------------------------------------------------- build

ACTIVE_BEFORE="$(readlink -f "$APP_ROOT" 2>/dev/null || true)"
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

    if [[ "$ACTIVATED" == "1" ]] && valid_release_path "$ACTIVE_BEFORE"; then
      as_root ln -sfn "$ACTIVE_BEFORE" "$APP_ROOT.new"
      as_root mv -Tf "$APP_ROOT.new" "$APP_ROOT"
      as_root systemctl reload "$PHP_FPM_SERVICE" || true
    fi

    if [[ "$AGENT_REFRESHED" == "1" ]] && valid_release_path "$ACTIVE_BEFORE"; then
      as_root bash "$ACTIVE_BEFORE/deploy/install_agent.sh" \
        "$APP_ROOT" "$AGENT_USER" "$WEB_USER" "$ACTIVE_BEFORE" || true
    fi

    if [[ "$RELEASE_CREATED" == "1" && "$RELEASE_DIR" != "$(readlink -f "$APP_ROOT" 2>/dev/null || true)" ]]; then
      as_root rm -rf -- "$RELEASE_DIR"
    fi
  fi

  exit "$status"
}

trap cleanup_failed_deploy EXIT

step "Creating release $RELEASE"
as_root install -d -m 0755 "$RELEASE_DIR"
RELEASE_CREATED=1
as_root chown "$(id -u):$(id -g)" "$RELEASE_DIR"

# git archive exports exactly the tracked tree: no .git, no untracked leftovers.
git -C "$REPO_CACHE" archive --format=tar "$REVISION_FULL" | tar -x -C "$RELEASE_DIR"
printf '%s\n' "$REVISION_FULL" > "$RELEASE_DIR/REVISION"
ok "tree exported"

step "Linking shared .env"
as_root chmod 0640 "$SHARED_ENV"
as_root chown root:"$WEB_USER" "$SHARED_ENV"
as_root ln -sfn "$SHARED_ENV" "$RELEASE_DIR/.env"
ok ".env in place"

step "Installing PHP dependencies (no dev)"
(cd "$RELEASE_DIR" && "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction --no-progress 2>&1 | tail -5)
ok "dependencies installed"

step "Fetching self-hosted UI fonts"
bash "$RELEASE_DIR/deploy/fetch-fonts.sh" "$RELEASE_DIR/public/assets/fonts"
ok "UI fonts ready"

step "Setting ownership and permissions"
as_root chown -R root:"$WEB_USER" "$RELEASE_DIR"
as_root find "$RELEASE_DIR" -type d -exec chmod 0750 {} +
as_root find "$RELEASE_DIR" -type f -exec chmod 0640 {} +
as_root chmod 0750 "$RELEASE_DIR"/deploy/*.sh 2>/dev/null || true

as_root install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$SHARED_STORAGE_ROOT"
for d in logs sessions cache generated rate_limits app_settings; do
  shared_dir="$SHARED_STORAGE_ROOT/$d"
  as_root install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$shared_dir"

  # One-time migration from the pre-release layout. Never overwrite shared
  # runtime state on later deploys or rollbacks.
  if ! as_root find "$shared_dir" -mindepth 1 -print -quit | grep -q . \
     && as_root test -d "$APP_ROOT/storage/$d" \
     && [[ "$(readlink -f "$APP_ROOT/storage/$d")" != "$(readlink -f "$shared_dir")" ]]; then
    as_root cp -a "$APP_ROOT/storage/$d/." "$shared_dir/"
  fi

  as_root chown -R "$WEB_USER":"$WEB_USER" "$shared_dir"
  as_root chmod 0770 "$shared_dir"
  as_root rm -rf "$RELEASE_DIR/storage/$d"
  as_root ln -s "$shared_dir" "$RELEASE_DIR/storage/$d"
done
ok "permissions set"

# The privileged agent lives outside the release directory (/usr/local/bin and
# /usr/local/lib), so a code deploy does not update it. Refresh it from the new
# release before the migrations, or the running agent stays on the old version.
step "Refreshing the privileged agent"
as_root bash "$RELEASE_DIR/deploy/install_agent.sh" "$APP_ROOT" "$AGENT_USER" "$WEB_USER" "$RELEASE_DIR"
AGENT_REFRESHED=1
ok "agent, wrapper and sudoers rules refreshed"

if [[ "$SKIP_MIGRATE" == "0" ]]; then
  step "Running database migrations"
  # The positional parameters are intentionally expanded by the child shell.
  # shellcheck disable=SC2016
  as_root bash -c 'cd "$1" && exec runuser -u "$2" -- php scripts/migrate.php' \
    _ "$RELEASE_DIR" "$WEB_USER" \
    || die "Migration failed. The release was NOT activated; the running site is untouched."
  ok "migrations applied"
else
  warn "migrations skipped (--skip-migrate)"
fi

if [[ "$UPGRADE_ROUNDCUBE" == "1" ]]; then
  step "Checking Roundcube $ROUNDCUBE_VERSION"
  as_root env ROUNDCUBE_VERSION="$ROUNDCUBE_VERSION" WEB_USER="$WEB_USER" PHP_FPM_SERVICE="$PHP_FPM_SERVICE" \
    bash "$RELEASE_DIR/deploy/upgrade_roundcube.sh" --if-installed
  ok "Roundcube check complete"
fi

step "Activating"
if valid_release_path "$ACTIVE_BEFORE"; then
  as_root ln -sfn "$ACTIVE_BEFORE" "$PREVIOUS_LINK.new"
  as_root mv -Tf "$PREVIOUS_LINK.new" "$PREVIOUS_LINK"
fi
ACTIVATED=1
as_root ln -sfn "$RELEASE_DIR" "$APP_ROOT.new"
if [[ -e "$APP_ROOT" && ! -L "$APP_ROOT" ]]; then
  [[ -d "$APP_ROOT" ]] || die "$APP_ROOT exists and is not a directory."
  as_root mv "$APP_ROOT" "$RELEASES_ROOT/${RELEASE}-legacy"
fi
as_root mv -Tf "$APP_ROOT.new" "$APP_ROOT"
as_root systemctl reload "$PHP_FPM_SERVICE"
ok "release $RELEASE is live"

step "Strict post-deploy health check"
run_release_healthcheck "$RELEASE_DIR" \
  || die "Health check failed; the previous release will be restored."
code="$(curl -ksS -o /dev/null -w '%{http_code}' "https://$HEALTHCHECK_HOST/admin/login" 2>/dev/null || true)"
[[ "$code" =~ ^(200|302)$ ]] \
  || die "Admin login health check returned ${code:-no response}; the previous release will be restored."
ok "healthcheck passed; admin login responded $code"

step "Pruning old releases (keeping $KEEP_RELEASES)"
active_now="$(readlink -f "$APP_ROOT" 2>/dev/null || true)"
previous_now="$(readlink -f "$PREVIOUS_LINK" 2>/dev/null || true)"
release_count=0
while IFS= read -r old; do
  [[ -n "$old" ]] || continue
  old_path="$RELEASES_ROOT/$old"
  release_count=$((release_count + 1))
  if [[ "$release_count" -le "$KEEP_RELEASES" || "$old_path" == "$active_now" || "$old_path" == "$previous_now" ]]; then
    continue
  fi
  as_root rm -rf -- "$old_path"
done < <(list_releases)
ok "done"

DEPLOY_SUCCEEDED=1
printf '\n%sDeployed %s (%s)%s\n' "$C_OK" "$REVISION" "$SUBJECT" "$C_OFF"
printf 'Roll back with: %s --rollback\n' "$0"
