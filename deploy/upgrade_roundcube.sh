#!/usr/bin/env bash
#
# Safely upgrade an existing Roundcube installation.
#
# The upgrade is prepared in a sibling directory, backed up in full, checked
# against a pinned release digest, and only then swapped into place. If any
# validation fails, both the old tree and the database are restored.
#
# Usage:
#   sudo bash deploy/upgrade_roundcube.sh
#   sudo bash deploy/upgrade_roundcube.sh --if-installed
#
set -Eeuo pipefail

TARGET_VERSION="${ROUNDCUBE_VERSION:-1.6.17}"
TARGET_SHA256="${ROUNDCUBE_SHA256:-}"
WEBMAIL_ROOT="${WEBMAIL_ROOT:-/var/www/webmail}"
WEBMAIL_PATH="${WEBMAIL_PATH:-/webmail}"
WEBMAIL_HEALTHCHECK_URL="${WEBMAIL_HEALTHCHECK_URL:-https://127.0.0.1${WEBMAIL_PATH}/}"
BACKUP_BASE="${ROUNDCUBE_BACKUP_ROOT:-/root/mailpanel-roundcube-backups}"
LOCK_FILE="${ROUNDCUBE_LOCK_FILE:-/var/lock/mailpanel-roundcube-upgrade.lock}"
WEB_USER="${WEB_USER:-www-data}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
IF_INSTALLED=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --if-installed) IF_INSTALLED=1; shift ;;
    -h|--help) sed -n '2,14p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

if [[ "$TARGET_VERSION" == "1.6.17" && -z "$TARGET_SHA256" ]]; then
  TARGET_SHA256="e1f6c437959cb8dffda1a3e59f0c0a2160b3d669948db69bb02edb218c8e69a1"
fi

die() { printf 'FAIL %s\n' "$1" >&2; return 1; }
ok() { printf '  ok %s\n' "$1"; }
step() { printf '==> %s\n' "$1"; }

[[ "$(id -u)" == "0" ]] || die "Run as root."
[[ "$TARGET_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Invalid Roundcube version: $TARGET_VERSION"
[[ "$TARGET_SHA256" =~ ^[a-fA-F0-9]{64}$ ]] \
  || die "Set ROUNDCUBE_SHA256 to the release asset's 64-character SHA-256 digest."
[[ "$WEBMAIL_ROOT" == /* && "$WEBMAIL_ROOT" != "/" ]] || die "WEBMAIL_ROOT must be a safe absolute path."
[[ "$WEBMAIL_ROOT" =~ ^/[A-Za-z0-9._/-]+$ && "$WEBMAIL_ROOT" != *"/../"* ]] \
  || die "WEBMAIL_ROOT contains unsupported characters."
[[ "$BACKUP_BASE" =~ ^/[A-Za-z0-9._/-]+$ && "$BACKUP_BASE" != "/" && "$BACKUP_BASE" != *"/../"* ]] \
  || die "ROUNDCUBE_BACKUP_ROOT must be a safe absolute path."
[[ "$LOCK_FILE" =~ ^/[A-Za-z0-9._/-]+$ && "$LOCK_FILE" != "/" && "$LOCK_FILE" != *"/../"* ]] \
  || die "ROUNDCUBE_LOCK_FILE must be a safe absolute path."
[[ "$WEBMAIL_PATH" =~ ^/[A-Za-z0-9._/-]*$ && "$WEBMAIL_PATH" != *"/../"* ]] \
  || die "WEBMAIL_PATH contains unsupported characters."
[[ "$WEBMAIL_HEALTHCHECK_URL" == https://* && "$WEBMAIL_HEALTHCHECK_URL" != *$'\n'* ]] \
  || die "WEBMAIL_HEALTHCHECK_URL must be an HTTPS URL."
[[ "$WEB_USER" =~ ^[a-z_][a-z0-9_-]{0,31}$ ]] || die "Invalid WEB_USER."
[[ "$PHP_FPM_SERVICE" =~ ^[A-Za-z0-9@_.-]+$ ]] || die "Invalid PHP_FPM_SERVICE."

for command_name in curl flock gzip mysqldump mysql php rsync sha256sum tar; do
  command -v "$command_name" >/dev/null || die "$command_name is required."
done

if [[ ! -f "$WEBMAIL_ROOT/index.php" || ! -f "$WEBMAIL_ROOT/program/include/iniset.php" ]]; then
  if [[ "$IF_INSTALLED" == "1" ]]; then
    ok "Roundcube is not installed; upgrade skipped"
    exit 0
  fi
  die "No Roundcube installation found at $WEBMAIL_ROOT."
fi

install -d -m 0755 "$(dirname "$LOCK_FILE")"
touch "$LOCK_FILE"
chmod 0600 "$LOCK_FILE"
exec 9>"$LOCK_FILE"
flock -n 9 || die "Another Roundcube upgrade is already running."

current_version() {
  sed -nE "s/.*define\(['\"]RCMAIL_VERSION['\"],[[:space:]]*['\"]([^'\"]+)['\"]\).*/\1/p" \
    "$1/program/include/iniset.php" | head -1
}

CURRENT_VERSION="$(current_version "$WEBMAIL_ROOT")"
[[ -n "$CURRENT_VERSION" ]] || die "Cannot determine the installed Roundcube version."
[[ "$CURRENT_VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] \
  || die "The installed Roundcube version is not valid."

if [[ "$CURRENT_VERSION" == "$TARGET_VERSION" ]]; then
  ok "Roundcube $TARGET_VERSION is already installed"
  exit 0
fi

version_highest="$(printf '%s\n%s\n' "$CURRENT_VERSION" "$TARGET_VERSION" | sort -V | tail -1)"
[[ "$version_highest" == "$TARGET_VERSION" ]] \
  || die "Refusing to downgrade Roundcube from $CURRENT_VERSION to $TARGET_VERSION."

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)-$$"
BACKUP_ROOT="$BACKUP_BASE/$RUN_ID-${CURRENT_VERSION}-to-${TARGET_VERSION}"
WEBMAIL_PARENT="$(dirname "$WEBMAIL_ROOT")"
WEBMAIL_NAME="$(basename "$WEBMAIL_ROOT")"
WORK_ROOT="$(mktemp -d "/tmp/mailpanel-roundcube-${RUN_ID}.XXXXXX")"
STAGE_ROOT="$(mktemp -d "$WEBMAIL_PARENT/.${WEBMAIL_NAME}-upgrade-${RUN_ID}.XXXXXX")"
ROLLBACK_TREE="${WEBMAIL_ROOT}.rollback-${RUN_ID}"
OLD_MOVED=0
NEW_ACTIVATED=0
DB_TOUCHED=0

cleanup_work() {
  rm -rf -- "$WORK_ROOT"
  if [[ -d "$STAGE_ROOT" ]]; then
    rm -rf -- "$STAGE_ROOT"
  fi
}

rollback_upgrade() {
  local status=$?
  trap - ERR
  set +e

  printf 'FAIL Roundcube upgrade failed; restoring the previous version.\n' >&2

  if [[ "$NEW_ACTIVATED" == "1" && -d "$WEBMAIL_ROOT" ]]; then
    mv "$WEBMAIL_ROOT" "$BACKUP_ROOT/failed-tree" 2>/dev/null || true
  fi
  if [[ "$OLD_MOVED" == "1" && -d "$ROLLBACK_TREE" ]]; then
    mv "$ROLLBACK_TREE" "$WEBMAIL_ROOT" 2>/dev/null || true
  fi

  if [[ "$DB_TOUCHED" == "1" && -f "$BACKUP_ROOT/roundcube-db.sql.gz" ]]; then
    mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`" 2>/dev/null || true
    gzip -dc "$BACKUP_ROOT/roundcube-db.sql.gz" | mysql 2>/dev/null || true
  fi

  systemctl reload "$PHP_FPM_SERVICE" 2>/dev/null || true
  cleanup_work
  printf 'Backup retained at %s\n' "$BACKUP_ROOT" >&2
  exit "$status"
}

trap rollback_upgrade ERR
trap cleanup_work EXIT

step "Backing up Roundcube $CURRENT_VERSION"
install -d -m 0700 "$BACKUP_ROOT"

CONFIG_FILE="$WEBMAIL_ROOT/config/config.inc.php"
[[ -f "$CONFIG_FILE" ]] || die "Roundcube config is missing: $CONFIG_FILE"

# The PHP source is deliberately single-quoted so the shell cannot expand it.
# shellcheck disable=SC2016
DB_NAME="$(php -r '
  $config = [];
  require $argv[1];
  $dsn = (string) ($config["db_dsnw"] ?? "");
  $parts = parse_url($dsn);
  if (!is_array($parts) || empty($parts["path"])) {
      exit(1);
  }
  echo rawurldecode(ltrim($parts["path"], "/"));
' "$CONFIG_FILE")"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "Cannot safely determine the Roundcube database name."

tar -czf "$BACKUP_ROOT/webmail-files.tar.gz" -C "$WEBMAIL_PARENT" "$WEBMAIL_NAME"
mysqldump --single-transaction --quick --routines --triggers --databases "$DB_NAME" \
  | gzip -9 >"$BACKUP_ROOT/roundcube-db.sql.gz"
cp -a "$CONFIG_FILE" "$BACKUP_ROOT/config.inc.php"
sha256sum "$BACKUP_ROOT/webmail-files.tar.gz" "$BACKUP_ROOT/roundcube-db.sql.gz" \
  >"$BACKUP_ROOT/SHA256SUMS"
chmod 0600 "$BACKUP_ROOT"/*
ok "backup created at $BACKUP_ROOT"

step "Downloading and verifying Roundcube $TARGET_VERSION"
ARCHIVE="$WORK_ROOT/roundcubemail-${TARGET_VERSION}-complete.tar.gz"
DOWNLOAD_URL="https://github.com/roundcube/roundcubemail/releases/download/${TARGET_VERSION}/roundcubemail-${TARGET_VERSION}-complete.tar.gz"
curl -fL --retry 3 --retry-delay 2 --connect-timeout 15 --max-time 180 \
  -o "$ARCHIVE" "$DOWNLOAD_URL"
printf '%s  %s\n' "$TARGET_SHA256" "$ARCHIVE" | sha256sum -c -
tar -xzf "$ARCHIVE" -C "$WORK_ROOT"
SOURCE_ROOT="$WORK_ROOT/roundcubemail-${TARGET_VERSION}"
[[ "$(current_version "$SOURCE_ROOT")" == "$TARGET_VERSION" ]] \
  || die "The downloaded archive does not contain Roundcube $TARGET_VERSION."
ok "release digest and version verified"

step "Preparing the upgraded tree"
cp -a "$WEBMAIL_ROOT/." "$STAGE_ROOT/"
DB_TOUCHED=1
if ! php "$SOURCE_ROOT/bin/installto.sh" -y "$STAGE_ROOT" >"$BACKUP_ROOT/upgrade.log" 2>&1; then
  tail -30 "$BACKUP_ROOT/upgrade.log" >&2
  die "Roundcube's installto.sh command failed."
fi
grep -q "This instance of Roundcube is up-to-date." "$BACKUP_ROOT/upgrade.log" \
  || die "Roundcube's update script did not confirm a complete upgrade."
grep -E '^(Upgrading from|Executing database schema update|This instance of Roundcube|All done)' \
  "$BACKUP_ROOT/upgrade.log" || true
[[ "$(current_version "$STAGE_ROOT")" == "$TARGET_VERSION" ]] \
  || die "The staged Roundcube tree still reports the wrong version."

rm -rf -- "$STAGE_ROOT/installer"
find "$STAGE_ROOT" -type d -exec chmod 0750 {} +
find "$STAGE_ROOT" -type f -exec chmod 0640 {} +
chown -R root:"$WEB_USER" "$STAGE_ROOT"
install -d -m 0770 -o "$WEB_USER" -g "$WEB_USER" "$STAGE_ROOT/temp" "$STAGE_ROOT/logs"
php -l "$STAGE_ROOT/index.php" >/dev/null
ok "staged tree validated"

step "Activating Roundcube $TARGET_VERSION"
mv "$WEBMAIL_ROOT" "$ROLLBACK_TREE"
OLD_MOVED=1
mv "$STAGE_ROOT" "$WEBMAIL_ROOT"
NEW_ACTIVATED=1
systemctl reload "$PHP_FPM_SERVICE"

http_code="$(curl -kfsS -o /dev/null -w '%{http_code}' "$WEBMAIL_HEALTHCHECK_URL" || true)"
[[ "$http_code" =~ ^(200|302)$ ]] \
  || die "Webmail health check returned ${http_code:-no response}."
[[ "$(current_version "$WEBMAIL_ROOT")" == "$TARGET_VERSION" ]] \
  || die "Active Roundcube version check failed."

mv "$ROLLBACK_TREE" "$BACKUP_ROOT/previous-tree"
OLD_MOVED=0
DB_TOUCHED=0
trap - ERR
ok "Roundcube upgraded from $CURRENT_VERSION to $TARGET_VERSION (HTTP $http_code)"
printf 'Backup: %s\n' "$BACKUP_ROOT"
