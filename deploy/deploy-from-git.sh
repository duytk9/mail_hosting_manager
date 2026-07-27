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
KEEP_RELEASES="${KEEP_RELEASES:-5}"
WEB_USER="${WEB_USER:-www-data}"
AGENT_USER="${AGENT_USER:-mailpanel-agent}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
HEALTHCHECK_HOST="${HEALTHCHECK_HOST:-localhost}"

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
command -v "$COMPOSER_BIN" >/dev/null || die "composer is not installed or COMPOSER_BIN points nowhere."

if [[ "$MODE" == "status" ]]; then
  step "Releases"
  printf '  current : %s\n' "$(readlink -f "$APP_ROOT" 2>/dev/null || echo '(none)')"
  ls -1t "$RELEASES_ROOT" 2>/dev/null | head -5 | sed 's/^/  /' || echo "  (no releases)"
  step "Deployed revision"
  cat "$APP_ROOT/REVISION" 2>/dev/null || echo "  (unknown)"
  step "Services"
  systemctl is-active nginx "$PHP_FPM_SERVICE" exim4 dovecot rspamd fail2ban 2>&1 | paste -sd' ' - || true
  step "Migrations"
  as_root bash -c 'cd "$1" && exec runuser -u "$2" -- php scripts/migrate.php --status' \
    _ "$APP_ROOT" "$WEB_USER" || true
  exit 0
fi

if [[ "$MODE" == "rollback" ]]; then
  prev="$(ls -1t "$RELEASES_ROOT" 2>/dev/null | sed -n 2p || true)"
  [[ -n "$prev" ]] || die "No previous release to roll back to."
  step "Rolling back to $prev"
  as_root ln -sfn "$RELEASES_ROOT/$prev" "$APP_ROOT.new"
  as_root mv -Tf "$APP_ROOT.new" "$APP_ROOT"
  as_root systemctl reload "$PHP_FPM_SERVICE"
  ok "rolled back to $prev"
  warn "Migrations are forward-only. If this release added schema changes, review them."
  exit 0
fi

[[ -n "$GIT_REMOTE" ]] || die "GIT_REMOTE is not set in $CONF_FILE."
[[ -f "$SHARED_ENV" ]] || die "$SHARED_ENV is missing. Run --bootstrap first."

# -------------------------------------------------------------------- fetch

step "Fetching $GIT_REF from $GIT_REMOTE"

if [[ ! -d "$REPO_CACHE/.git" ]]; then
  as_root install -d -m 0755 "$REPO_CACHE"
  as_root chown "$(id -u):$(id -g)" "$REPO_CACHE"
  git clone --quiet "$GIT_REMOTE" "$REPO_CACHE"
else
  git -C "$REPO_CACHE" remote set-url origin "$GIT_REMOTE"
  git -C "$REPO_CACHE" fetch --quiet --prune --tags origin
fi

git -C "$REPO_CACHE" checkout --quiet --force "$GIT_REF" 2>/dev/null \
  || git -C "$REPO_CACHE" checkout --quiet --force "origin/$GIT_REF" \
  || die "Cannot check out ref '$GIT_REF'."

# Fast-forward when on a branch; a tag/commit checkout is detached and needs none.
git -C "$REPO_CACHE" symbolic-ref -q HEAD >/dev/null 2>&1 \
  && git -C "$REPO_CACHE" merge --quiet --ff-only "origin/$GIT_REF"

REVISION="$(git -C "$REPO_CACHE" rev-parse --short HEAD)"
SUBJECT="$(git -C "$REPO_CACHE" log -1 --pretty=%s)"
ok "at $REVISION — $SUBJECT"

# --------------------------------------------------------------- safety gate

step "Checking the checkout for committed credentials"
# This repository previously carried the production root password in 223 files.
# Refuse to deploy if that pattern reappears.
if grep -rlE '(password|passwd)[[:space:]]*=[[:space:]]*["'"'"'][^"'"'"']{6,}' \
     --include='*.py' --include='*.sh' --include='*.php' \
     --exclude-dir=vendor --exclude-dir=tests --exclude-dir=.git \
     "$REPO_CACHE" 2>/dev/null | head -1 | grep -q .; then
  grep -rlE '(password|passwd)[[:space:]]*=[[:space:]]*["'"'"'][^"'"'"']{6,}' \
    --include='*.py' --include='*.sh' --include='*.php' \
    --exclude-dir=vendor --exclude-dir=tests --exclude-dir=.git \
    "$REPO_CACHE" 2>/dev/null | head -10 | sed 's/^/       /'
  die "Refusing to deploy: the checkout contains literal password assignments."
fi
ok "clean"

RELEASE="$(date -u +%Y%m%d-%H%M%S)-$REVISION"
RELEASE_DIR="$RELEASES_ROOT/$RELEASE"

if [[ "$DRY_RUN" == "1" ]]; then
  step "Dry run"
  printf '  would deploy : %s (%s)\n' "$REVISION" "$SUBJECT"
  printf '  release dir  : %s\n' "$RELEASE_DIR"
  step "Changes since the running release"
  if [[ -f "$APP_ROOT/REVISION" ]]; then
    git -C "$REPO_CACHE" log --oneline "$(cat "$APP_ROOT/REVISION")..HEAD" 2>/dev/null | head -20 || echo "  (cannot diff)"
  else
    echo "  (no running release recorded)"
  fi
  step "Pending migrations"
  (cd "$APP_ROOT" 2>/dev/null && php scripts/migrate.php --dry-run) || echo "  (cannot check)"
  ok "nothing was changed"
  exit 0
fi

# ------------------------------------------------------------------- build

step "Creating release $RELEASE"
as_root install -d -m 0755 "$RELEASE_DIR"
as_root chown "$(id -u):$(id -g)" "$RELEASE_DIR"

# git archive exports exactly the tracked tree: no .git, no untracked leftovers.
git -C "$REPO_CACHE" archive --format=tar "$REVISION" | tar -x -C "$RELEASE_DIR"
printf '%s\n' "$REVISION" > "$RELEASE_DIR/REVISION"
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
ok "agent, wrapper and sudoers rules refreshed"

if [[ "$SKIP_MIGRATE" == "0" ]]; then
  step "Running database migrations"
  as_root bash -c 'cd "$1" && exec runuser -u "$2" -- php scripts/migrate.php' \
    _ "$RELEASE_DIR" "$WEB_USER" \
    || die "Migration failed. The release was NOT activated; the running site is untouched."
  ok "migrations applied"
else
  warn "migrations skipped (--skip-migrate)"
fi

step "Activating"
as_root ln -sfn "$RELEASE_DIR" "$APP_ROOT.new"
if [[ -e "$APP_ROOT" && ! -L "$APP_ROOT" ]]; then
  [[ -d "$APP_ROOT" ]] || die "$APP_ROOT exists and is not a directory."
  as_root mv "$APP_ROOT" "$RELEASES_ROOT/${RELEASE}-legacy"
fi
as_root mv -Tf "$APP_ROOT.new" "$APP_ROOT"
as_root systemctl reload "$PHP_FPM_SERVICE"
ok "release $RELEASE is live"

step "Health check"
code="$(curl -fsS -k -o /dev/null -w '%{http_code}' "https://$HEALTHCHECK_HOST/admin/login" 2>/dev/null || echo 000)"
if [[ "$code" =~ ^(200|302)$ ]]; then
  ok "admin login responded $code"
else
  warn "health check returned $code — check nginx/php-fpm logs, then --rollback if needed"
fi

step "Pruning old releases (keeping $KEEP_RELEASES)"
# as_root is a shell function, so it cannot be invoked through xargs.
while read -r old; do
  [[ -n "$old" ]] && as_root rm -rf "${RELEASES_ROOT:?}/$old"
done < <(cd "$RELEASES_ROOT" && ls -1t | tail -n +$((KEEP_RELEASES + 1)))
ok "done"

printf '\n%sDeployed %s (%s)%s\n' "$C_OK" "$REVISION" "$SUBJECT" "$C_OFF"
printf 'Roll back with: %s --rollback\n' "$0"
