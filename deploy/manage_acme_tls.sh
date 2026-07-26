#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
APP_ROOT="${2:-/opt/mailpanel}"
TARGET_DOMAIN="${3:-}"

ACME_EMAIL="${ACME_EMAIL:-${CERTBOT_EMAIL:-}}"
ACME_WEBROOT="${ACME_WEBROOT:-/var/www/acme}"
ACME_MANIFEST_ROOT="${ACME_MANIFEST_ROOT:-/etc/mailpanel/letsencrypt/domains}"
ACME_HOOK_PATH="${ACME_HOOK_PATH:-/etc/letsencrypt/renewal-hooks/deploy/20-mailpanel-sync.sh}"
TLS_SNI_ROOT="${TLS_SNI_ROOT:-/etc/mailpanel/tls/sni}"
ACME_CERT_NAME_PREFIX="${ACME_CERT_NAME_PREFIX:-mailpanel-}"
ACME_INCLUDE_APEX="${ACME_INCLUDE_APEX:-1}"
ACME_MAIL_HOST_PATTERNS="${ACME_MAIL_HOST_PATTERNS-mail.%s}"
ACME_WEB_HOST_PATTERNS="${ACME_WEB_HOST_PATTERNS-webmail.%s,autodiscover.%s,autoconfig.%s}"
ACME_FORCE_RENEWAL="${ACME_FORCE_RENEWAL:-0}"
CERTBOT_BIN="${CERTBOT_BIN:-/usr/bin/certbot}"
CERTBOT_CONFIG_DIR="${CERTBOT_CONFIG_DIR:-/etc/letsencrypt}"
EXIM_TLS_GROUP="${EXIM_TLS_GROUP:-Debian-exim}"
ACME_LOCK_PATH="${ACME_LOCK_PATH:-/run/lock/mailpanel-acme-tls.lock}"

usage() {
  cat <<'EOF'
Usage:
  manage_acme_tls.sh bootstrap [app_root]
  manage_acme_tls.sh readiness [app_root] <domain>
  manage_acme_tls.sh provision-domain [app_root] <domain>
  manage_acme_tls.sh sync-domain [app_root] <domain>
  manage_acme_tls.sh sync-all [app_root]
EOF
}

upsert_env() {
  local key="${1:-}"
  local value="${2:-}"
  local env_file="${APP_ROOT}/.env"
  touch "$env_file"

  if grep -q "^${key}=" "$env_file"; then
    sed -i "s#^${key}=.*#${key}=${value}#g" "$env_file"
  else
    printf '%s=%s\n' "$key" "$value" >>"$env_file"
  fi
}

normalize_domain() {
  local domain="${1,,}"
  domain="${domain#.}"
  domain="${domain%.}"
  [[ "${#domain}" -le 253 && "$domain" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]] || {
    echo "Invalid domain: $1" >&2
    exit 64
  }
  printf '%s\n' "$domain"
}

normalize_cert_name() {
  local cert_name="${1:-}"
  [[ "$cert_name" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$ ]] || {
    echo "Invalid certificate name." >&2
    exit 64
  }
  printf '%s\n' "$cert_name"
}

manifest_path() {
  printf '%s/%s.env\n' "$ACME_MANIFEST_ROOT" "$1"
}

cert_name_for_domain() {
  local domain="$1"
  local safe
  safe="$(printf '%s' "$domain" | sed 's/[^A-Za-z0-9.-]/-/g')"
  printf '%s%s\n' "$ACME_CERT_NAME_PREFIX" "$safe"
}

csv_to_lines() {
  printf '%s\n' "${1:-}" | tr ',' '\n' | sed '/^[[:space:]]*$/d' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//'
}

domain_hosts() {
  local domain="$1"
  local -a hosts=()
  local host

  if [[ "$ACME_INCLUDE_APEX" == "1" ]]; then
    hosts+=("$domain")
  fi

  while IFS= read -r pattern; do
    [[ -n "$pattern" ]] || continue
    host="$(printf "$pattern" "$domain")"
    hosts+=("$(normalize_domain "$host")")
  done < <(csv_to_lines "$ACME_MAIL_HOST_PATTERNS")

  while IFS= read -r pattern; do
    [[ -n "$pattern" ]] || continue
    host="$(printf "$pattern" "$domain")"
    hosts+=("$(normalize_domain "$host")")
  done < <(csv_to_lines "$ACME_WEB_HOST_PATTERNS")

  printf '%s\n' "${hosts[@]}" | awk 'NF && !seen[$0]++'
}

bootstrap_dirs() {
  install -d -m 0755 "$ACME_WEBROOT/.well-known/acme-challenge"
  install -d -m 0755 "$ACME_MANIFEST_ROOT"
  install -d -m 0755 "$TLS_SNI_ROOT"
  install -d -m 0755 "$(dirname "$ACME_HOOK_PATH")"
}

install_renew_hook() {
  local hook_tmp
  hook_tmp="$(mktemp "$(dirname "$ACME_HOOK_PATH")/.20-mailpanel-sync.XXXXXX")"
  cat >"$hook_tmp" <<EOF
#!/usr/bin/env bash
set -euo pipefail
"${APP_ROOT}/deploy/manage_acme_tls.sh" sync-all "${APP_ROOT}"
EOF
  install -o root -g root -m 0755 "$hook_tmp" "$ACME_HOOK_PATH"
  rm -f "$hook_tmp"
}

write_manifest() {
  local domain="$1"
  local cert_name="$2"
  shift 2
  local hosts_csv
  hosts_csv="$(printf '%s\n' "$@" | paste -sd, -)"

  cat >"$(manifest_path "$domain")" <<EOF
DOMAIN=$domain
CERT_NAME=$cert_name
HOSTS=$hosts_csv
EOF
}

repair_certbot_renewal_webroot() {
  local cert_name="$1"
  local hosts_csv="$2"
  local renewal_conf="${CERTBOT_CONFIG_DIR}/renewal/${cert_name}.conf"

  [[ -f "$renewal_conf" ]] || return 0

  CERTBOT_RENEWAL_CONF="$renewal_conf" \
    ACME_WEBROOT="$ACME_WEBROOT" \
    MANAGED_HOSTS="$hosts_csv" \
    python3 <<'PY'
import os
import re
import tempfile
import time
from pathlib import Path

renewal_conf = Path(os.environ["CERTBOT_RENEWAL_CONF"])
webroot = os.environ["ACME_WEBROOT"]
hosts = [
    host.strip()
    for host in os.environ.get("MANAGED_HOSTS", "").split(",")
    if host.strip()
]

if not webroot.startswith("/"):
    raise SystemExit("ACME_WEBROOT must be an absolute path.")

host_pattern = re.compile(
    r"^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?"
    r"(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$"
)
if any(host_pattern.fullmatch(host) is None for host in hosts):
    raise SystemExit("Renewal config contains an invalid hostname.")

original = renewal_conf.read_text(encoding="utf-8")
lines = original.splitlines(keepends=True)
output = []
changed = False
in_webroot_map = False
has_webroot_map = False
seen_hosts = set()

for line in lines:
    stripped = line.strip()
    newline = "\r\n" if line.endswith("\r\n") else "\n"

    if stripped.startswith("[") and stripped.endswith("]"):
        in_webroot_map = stripped == "[[webroot_map]]"
        has_webroot_map = has_webroot_map or in_webroot_map

    if line.startswith("webroot_path = "):
        replacement = f"webroot_path = {webroot},{newline}"
        output.append(replacement)
        changed = changed or replacement != line
        continue

    if in_webroot_map and "=" in line and not stripped.startswith("#"):
        key = line.split("=", 1)[0].strip()
        if key in hosts:
            replacement = f"{key} = {webroot}{newline}"
            output.append(replacement)
            seen_hosts.add(key)
            changed = changed or replacement != line
            continue

    output.append(line)

missing_hosts = [host for host in hosts if host not in seen_hosts]
if missing_hosts:
    changed = True
    if output and not output[-1].endswith(("\n", "\r")):
        output[-1] = output[-1] + "\n"
    if not has_webroot_map:
        if output and output[-1].strip():
            output.append("\n")
        output.append("[[webroot_map]]\n")
    for host in missing_hosts:
        output.append(f"{host} = {webroot}\n")

updated = "".join(output)
if updated == original:
    raise SystemExit(0)

backup = renewal_conf.with_name(
    f"{renewal_conf.name}.mailpanel-bak-{int(time.time())}"
)
backup.write_text(original, encoding="utf-8")

stat_result = renewal_conf.stat()
with tempfile.NamedTemporaryFile(
    "w",
    encoding="utf-8",
    dir=str(renewal_conf.parent),
    prefix=f".{renewal_conf.name}.",
    delete=False,
) as handle:
    handle.write(updated)
    temp_name = handle.name

os.chmod(temp_name, stat_result.st_mode & 0o777)
os.replace(temp_name, renewal_conf)
print(f"renewal_webroot=repaired:{renewal_conf.name}")
PY
}

apply_tls_stack() {
  MAILPANEL_APP_ROOT="$APP_ROOT" php <<'PHP'
<?php
declare(strict_types=1);

use MailPanel\Services\ConfigDeploymentService;

$appRoot = (string) getenv('MAILPANEL_APP_ROOT');
$realRoot = realpath($appRoot);
if ($realRoot === false || !is_file($realRoot . '/vendor/autoload.php')) {
    throw new RuntimeException('MailPanel application root is invalid.');
}

require $realRoot . '/vendor/autoload.php';
$application = require $realRoot . '/bootstrap/app.php';
$deployment = $application->resolve(ConfigDeploymentService::class);

$drafts = $deployment->generateDrafts(1);
foreach ($drafts as $draft) {
    if (!in_array($draft['service'], ['nginx', 'exim', 'dovecot'], true)) {
        continue;
    }

    $deployment->applyVersion((int) $draft['id'], false);
}

echo "tls_stack=applied\n";
PHP
}

manifest_value() {
  local manifest="$1"
  local key="$2"
  local count
  count="$(grep -c "^${key}=" "$manifest" || true)"
  [[ "$count" == "1" ]] || {
    echo "Manifest key ${key} is missing or duplicated: $manifest" >&2
    exit 67
  }
  sed -n "s/^${key}=//p" "$manifest"
}

sync_manifest_file() {
  local manifest="$1"
  local domain
  local cert_name
  local hosts_csv
  local live_root
  [[ -f "$manifest" ]] || {
    echo "Manifest not found: $manifest" >&2
    exit 66
  }

  domain="$(normalize_domain "$(manifest_value "$manifest" DOMAIN)")"
  cert_name="$(normalize_cert_name "$(manifest_value "$manifest" CERT_NAME)")"
  hosts_csv="$(manifest_value "$manifest" HOSTS)"
  [[ -n "$hosts_csv" ]] || {
    echo "Manifest is incomplete: $manifest" >&2
    exit 67
  }
  repair_certbot_renewal_webroot "$cert_name" "$hosts_csv"

  live_root="${CERTBOT_CONFIG_DIR}/live/${cert_name}"
  [[ -f "${live_root}/fullchain.pem" && -f "${live_root}/privkey.pem" ]] || {
    echo "Certificate lineage is missing: $live_root" >&2
    exit 68
  }

  local host
  local host_root
  local cert_tmp
  local key_tmp
  local cert_public_hash
  local key_public_hash
  while IFS= read -r host; do
    [[ -n "$host" ]] || continue
    host="$(normalize_domain "$host")"
    openssl x509 -in "${live_root}/fullchain.pem" -noout -checkhost "$host" >/dev/null
    cert_public_hash="$(openssl x509 -in "${live_root}/fullchain.pem" -pubkey -noout | openssl pkey -pubin -outform DER | sha256sum | awk '{print $1}')"
    key_public_hash="$(openssl pkey -in "${live_root}/privkey.pem" -pubout -outform DER | sha256sum | awk '{print $1}')"
    [[ -n "$cert_public_hash" && "$cert_public_hash" == "$key_public_hash" ]] || {
      echo "Certificate and private key do not match for $host." >&2
      exit 68
    }

    host_root="${TLS_SNI_ROOT}/${host}"
    install -d -o root -g "$EXIM_TLS_GROUP" -m 0755 "$host_root"
    cert_tmp="$(mktemp "${host_root}/.fullchain.pem.XXXXXX")"
    key_tmp="$(mktemp "${host_root}/.privkey.pem.XXXXXX")"
    install -o root -g root -m 0644 "${live_root}/fullchain.pem" "$cert_tmp"
    install -o root -g "$EXIM_TLS_GROUP" -m 0640 "${live_root}/privkey.pem" "$key_tmp"
    mv -f "$key_tmp" "${host_root}/privkey.pem"
    mv -f "$cert_tmp" "${host_root}/fullchain.pem"
  done < <(csv_to_lines "$hosts_csv")
}

acquire_lock() {
  install -d -m 0755 "$(dirname "$ACME_LOCK_PATH")"
  exec 9>"$ACME_LOCK_PATH"
  flock -w 60 9 || {
    echo "Another ACME/TLS synchronization is already running." >&2
    exit 75
  }
}

[[ "$APP_ROOT" == /* && -f "${APP_ROOT}/vendor/autoload.php" ]] || {
  echo "Invalid MailPanel application root." >&2
  exit 64
}
APP_ROOT="$(cd "$APP_ROOT" && pwd -P)"

case "$ACTION" in
  bootstrap|provision-domain|sync-domain|sync-all) acquire_lock ;;
esac

case "$ACTION" in
  bootstrap)
    bootstrap_dirs
    apt-get update -y
    apt-get install -y certbot
    install_renew_hook
    upsert_env ACME_WEBROOT "$ACME_WEBROOT"
    upsert_env ACME_MANIFEST_ROOT "$ACME_MANIFEST_ROOT"
    upsert_env ACME_HOOK_PATH "$ACME_HOOK_PATH"
    upsert_env TLS_SNI_ROOT "$TLS_SNI_ROOT"
    upsert_env CERTBOT_CONFIG_DIR "$CERTBOT_CONFIG_DIR"
    if command -v systemctl >/dev/null 2>&1; then
      systemctl enable --now certbot.timer
    fi
    echo "acme_bootstrap=ok"
    ;;
  readiness)
    [[ -n "$TARGET_DOMAIN" ]] || {
      usage
      exit 64
    }
    TARGET_DOMAIN="$(normalize_domain "$TARGET_DOMAIN")"
    echo "readiness_domain=$TARGET_DOMAIN"
    while IFS= read -r host; do
      [[ -n "$host" ]] || continue
      resolved="$( (getent ahostsv4 "$host" 2>/dev/null || true) | awk '{print $1}' | sort -u | paste -sd, - )"
      echo "$host => ${resolved:-unresolved}"
    done < <(domain_hosts "$TARGET_DOMAIN")
    ;;
  provision-domain)
    [[ -n "$TARGET_DOMAIN" ]] || {
      usage
      exit 64
    }
    [[ -n "$ACME_EMAIL" ]] || {
      echo "ACME_EMAIL is required." >&2
      exit 65
    }
    TARGET_DOMAIN="$(normalize_domain "$TARGET_DOMAIN")"
    bootstrap_dirs
    install_renew_hook

    cert_name="$(cert_name_for_domain "$TARGET_DOMAIN")"
    mapfile -t hosts < <(domain_hosts "$TARGET_DOMAIN")
    [[ "${#hosts[@]}" -gt 0 ]] || {
      echo "No hostnames were generated for $TARGET_DOMAIN" >&2
      exit 66
    }

    certbot_args=(
      certonly
      --non-interactive
      --agree-tos
      --email "$ACME_EMAIL"
      --config-dir "$CERTBOT_CONFIG_DIR"
      --webroot
      -w "$ACME_WEBROOT"
      --cert-name "$cert_name"
      --renew-with-new-domains
    )
    for host in "${hosts[@]}"; do
      certbot_args+=(-d "$host")
    done
    if [[ "$ACME_FORCE_RENEWAL" == "1" ]]; then
      certbot_args+=(--force-renewal)
    fi

    "$CERTBOT_BIN" "${certbot_args[@]}"
    write_manifest "$TARGET_DOMAIN" "$cert_name" "${hosts[@]}"
    sync_manifest_file "$(manifest_path "$TARGET_DOMAIN")"
    apply_tls_stack
    echo "acme_domain=provisioned"
    ;;
  sync-domain)
    [[ -n "$TARGET_DOMAIN" ]] || {
      usage
      exit 64
    }
    TARGET_DOMAIN="$(normalize_domain "$TARGET_DOMAIN")"
    sync_manifest_file "$(manifest_path "$TARGET_DOMAIN")"
    apply_tls_stack
    echo "acme_domain=synced"
    ;;
  sync-all)
    bootstrap_dirs
    install_renew_hook
    mapfile -d '' manifests < <(find "$ACME_MANIFEST_ROOT" -maxdepth 1 -type f -name '*.env' -print0)
    for manifest in "${manifests[@]}"; do
      sync_manifest_file "$manifest"
    done
    apply_tls_stack
    echo "acme_sync_all=ok"
    ;;
  *)
    usage
    exit 64
    ;;
esac
