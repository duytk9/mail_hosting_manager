#!/usr/bin/env bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

APP_ROOT="${1:-/opt/mailpanel}"
WEBMAIL_ROOT="${2:-/var/www/webmail}"
WEBMAIL_PATH="${WEBMAIL_PATH:-/webmail}"
SERVER_NAME="${SERVER_NAME:-_}"
WEBMAIL_DATA_ROOT="${WEBMAIL_DATA_ROOT:-${WEBMAIL_ROOT}/data/_data_/_default_}"
WEBMAIL_CONFIG_FILE="${WEBMAIL_CONFIG_FILE:-${WEBMAIL_DATA_ROOT}/configs/application.ini}"
WEBMAIL_LOG_DIR="${WEBMAIL_LOG_DIR:-${WEBMAIL_DATA_ROOT}/logs}"
WEBMAIL_AUTH_LOG="${WEBMAIL_AUTH_LOG:-${WEBMAIL_LOG_DIR}/auth.log}"
WEBMAIL_PUBLIC_LINK="${APP_ROOT}/public${WEBMAIL_PATH}"
BACKUP_ROOT="/root/mailpanel-webmail-backups/$(date +%Y%m%d-%H%M%S)"

if [[ "${WEBMAIL_PATH}" != /* ]]; then
  WEBMAIL_PATH="/${WEBMAIL_PATH}"
fi

WEBMAIL_PATH="${WEBMAIL_PATH%/}"
if [[ -z "${WEBMAIL_PATH}" ]]; then
  WEBMAIL_PATH="/webmail"
fi

backup_path() {
  local target="${1:-}"
  if [[ -z "${target}" || ! -e "${target}" ]]; then
    return
  fi

  install -d -m 0700 "${BACKUP_ROOT}$(dirname "${target}")"
  cp -a "${target}" "${BACKUP_ROOT}${target}"
}

upsert_env() {
  local key="${1:-}"
  local value="${2:-}"
  local env_file="${APP_ROOT}/.env"

  if [[ ! -f "${env_file}" ]]; then
    touch "${env_file}"
  fi

  if grep -q "^${key}=" "${env_file}"; then
    sed -i "s#^${key}=.*#${key}=${value}#g" "${env_file}"
  else
    printf '%s=%s\n' "${key}" "${value}" >>"${env_file}"
  fi
}

disable_legacy_webmail_services() {
  local raw_services="${LEGACY_WEBMAIL_SERVICE_NAMES:-}"
  local service=""

  [[ -n "${raw_services}" ]] || return 0

  IFS=',' read -r -a service_list <<< "${raw_services}"
  for service in "${service_list[@]}"; do
    service="${service#"${service%%[![:space:]]*}"}"
    service="${service%"${service##*[![:space:]]}"}"
    [[ -n "${service}" ]] || continue
    if systemctl list-unit-files "${service}.service" >/dev/null 2>&1; then
      systemctl stop "${service}" >/dev/null 2>&1 || true
      systemctl disable "${service}" >/dev/null 2>&1 || true
    fi
  done

  return 0
}

remove_public_info_page() {
  local info_file="${APP_ROOT}/public/info.php"
  if [[ -f "${info_file}" ]]; then
    backup_path "${info_file}"
    rm -f "${info_file}"
  fi

  return 0
}

cleanup_flattened_deploy_artifacts() {
  local artifact root_path canonical_path
  local -a artifacts=(
    "ADMIN_AUDIT.md:docs/ADMIN_AUDIT.md"
    "CODEBASE_MAP.md:docs/CODEBASE_MAP.md"
    "INSTALL.md:docs/INSTALL.md"
    "WEBMAIL_INTEGRATION.md:docs/WEBMAIL_INTEGRATION.md"
    "ApplicationFactory.php:src/Bootstrap/ApplicationFactory.php"
    "Fail2banConfigRenderer.php:src/Services/Fail2banConfigRenderer.php"
    "NginxConfigRenderer.php:src/Services/NginxConfigRenderer.php"
    "TenantAdminService.php:src/Services/TenantAdminService.php"
    "TenantRepository.php:src/Repositories/Pdo/TenantRepository.php"
    "UserRepository.php:src/Repositories/Pdo/UserRepository.php"
    "install_webmail_stack.sh:deploy/install_webmail_stack.sh"
    "layout.php:src/Views/admin/layout.php"
    "mailpanel.php:config/mailpanel.php"
    "manage_acme_tls.sh:deploy/manage_acme_tls.sh"
  )

  for artifact in "${artifacts[@]}"; do
    root_path="${APP_ROOT}/${artifact%%:*}"
    canonical_path="${APP_ROOT}/${artifact#*:}"

    if [[ -e "${root_path}" && -e "${canonical_path}" ]]; then
      backup_path "${root_path}"
      rm -rf "${root_path}"
    fi
  done

  return 0
}

install -d -m 0700 "${BACKUP_ROOT}"
backup_path "${APP_ROOT}/.env"
backup_path "${WEBMAIL_CONFIG_FILE}"
backup_path "${APP_ROOT}/public/webmail"
backup_path "${APP_ROOT}/public/info.php"

apt-get update -y
apt-get install -y curl ca-certificates php8.3-intl php8.3-gd php8.3-zip php8.3-xml php8.3-mbstring php8.3-sqlite3

if [[ ! -d "${WEBMAIL_ROOT}" ]]; then
  echo "Webmail root not found: ${WEBMAIL_ROOT}" >&2
  exit 70
fi

if [[ ! -f "${WEBMAIL_ROOT}/index.php" ]]; then
  echo "Webmail entrypoint not found: ${WEBMAIL_ROOT}/index.php" >&2
  exit 71
fi

if [[ ! -f "${WEBMAIL_CONFIG_FILE}" ]]; then
  echo "Webmail application.ini not found: ${WEBMAIL_CONFIG_FILE}" >&2
  exit 72
fi

install -d -o www-data -g www-data -m 0750 "${WEBMAIL_LOG_DIR}"
touch "${WEBMAIL_AUTH_LOG}"
chown www-data:www-data "${WEBMAIL_AUTH_LOG}"
chmod 0640 "${WEBMAIL_AUTH_LOG}"

install -d -m 0755 "$(dirname "${WEBMAIL_PUBLIC_LINK}")"
rm -rf "${WEBMAIL_PUBLIC_LINK}"
disable_legacy_webmail_services
remove_public_info_page
cleanup_flattened_deploy_artifacts

upsert_env "WEBMAIL_ENABLED" "1"
upsert_env "WEBMAIL_DRIVER" "webmail"
upsert_env "WEBMAIL_PUBLIC_ROOT" "${WEBMAIL_ROOT}"
upsert_env "WEBMAIL_LOG_PATH" "${WEBMAIL_AUTH_LOG}"
upsert_env "WEBMAIL_DISPLAY_NAME" "Webmail"
upsert_env "LEGACY_WEBMAIL_ENABLED" "0"
upsert_env "LEGACY_WEBMAIL_LOG_PATH" "${WEBMAIL_AUTH_LOG}"
upsert_env "NGINX_ROOT" "${APP_ROOT}/public"
upsert_env "NGINX_SERVER_NAME" "${SERVER_NAME}"
upsert_env "NGINX_PHP_FPM_SOCKET" "/run/php/php8.3-fpm.sock"
upsert_env "WEBMAIL_PATH" "${WEBMAIL_PATH}"
upsert_env "ACME_WEBROOT" "/var/www/acme"
upsert_env "TLS_SNI_ROOT" "/etc/mailpanel/tls/sni"
export MAILPANEL_APP_ROOT="${APP_ROOT}"

cat >/tmp/mailpanel-apply-webmail.php <<'PHP'
<?php
$appRoot = getenv('MAILPANEL_APP_ROOT') ?: '/opt/mailpanel';
require $appRoot . '/vendor/autoload.php';

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\ConfigVersionRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Services\AgentClientService;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\ConfigDeploymentService;
use MailPanel\Services\ConfigValidatorService;
use MailPanel\Services\DovecotConfigRenderer;
use MailPanel\Services\EximConfigRenderer;
use MailPanel\Services\Fail2banConfigRenderer;
use MailPanel\Services\NginxConfigRenderer;
use MailPanel\Services\RspamdConfigRenderer;
use MailPanel\Services\SystemCommandService;
use MailPanel\Services\TlsCertificateInventory;

Environment::load($appRoot);
$config = new Config($appRoot, [
    'database' => require $appRoot . '/config/database.php',
    'mailpanel' => require $appRoot . '/config/mailpanel.php',
]);

$db = new Database($config->get('database'));
$tlsInventory = new TlsCertificateInventory((string) $config->get('mailpanel.tls_sni_root', '/etc/mailpanel/tls/sni'));
$deployment = new ConfigDeploymentService(
    $config->get('mailpanel.generated_root'),
    new ConfigVersionRepository($db),
    new NginxConfigRenderer(
        $config->get('mailpanel.generated_root'),
        $config->get('mailpanel.nginx_root'),
        $config->get('mailpanel.nginx_server_name'),
        $config->get('mailpanel.webmail_path'),
        $config->get('mailpanel.webmail_public_root'),
        $config->get('mailpanel.nginx_php_fpm_socket'),
        $config->get('mailpanel.nginx_tls_certificate'),
        $config->get('mailpanel.nginx_tls_privatekey'),
        $config->get('mailpanel.acme_webroot'),
        $tlsInventory,
    ),
    new EximConfigRenderer(
        $config->get('mailpanel.generated_root'),
        new DomainRepository($db),
        new MailboxRepository($db),
        new AliasRepository($db),
        new TenantRepository($db),
        $config->get('mailpanel.exim_tls_certificate'),
        $config->get('mailpanel.exim_tls_privatekey'),
        $config->get('mailpanel.exim_submission_ports'),
        $config->get('mailpanel.exim_tls_on_connect_ports'),
        $tlsInventory,
    ),
    new DovecotConfigRenderer(
        $config->get('mailpanel.generated_root'),
        $config->get('mailpanel.vmail_root'),
        $config->get('database'),
        $config->get('mailpanel.vmail_uid'),
        $config->get('mailpanel.vmail_gid'),
        $config->get('mailpanel.dovecot_pass_scheme'),
        $config->get('mailpanel.exim_tls_certificate'),
        $config->get('mailpanel.exim_tls_privatekey'),
        $tlsInventory,
    ),
    new RspamdConfigRenderer($config->get('mailpanel.generated_root')),
    new Fail2banConfigRenderer(
        $config->get('mailpanel.generated_root'),
        (bool) $config->get('mailpanel.webmail_enabled', false),
        (string) $config->get('mailpanel.webmail_log_path', '/var/www/webmail/data/_data_/_default_/logs/auth.log'),
    ),
    new ConfigValidatorService(new SystemCommandService()),
    new AgentClientService($config->get('mailpanel.agent_binary'), $config->get('mailpanel.system_user')),
    new AuditLogService($db),
);

$drafts = $deployment->generateDrafts(1);
foreach ($drafts as $draft) {
    if (!in_array($draft['service'], ['nginx', 'fail2ban'], true)) {
        continue;
    }

    $deployment->applyVersion((int) $draft['id'], false);
}
PHP

php /tmp/mailpanel-apply-webmail.php
rm -f /tmp/mailpanel-apply-webmail.php
php "${APP_ROOT}/scripts/sync_webmail_runtime.php"

nginx -t
systemctl reload nginx
systemctl restart fail2ban || systemctl reload fail2ban || true

printf '\n== WEBMAIL ==\n'
curl -k -L -s -o /tmp/mailpanel-webmail.html -w 'webmail_http=%{http_code}\n' "https://127.0.0.1${WEBMAIL_PATH}/"

LEGACY_WEBMAIL_MARKER="${LEGACY_WEBMAIL_MARKER:-}"
if [[ -n "${LEGACY_WEBMAIL_MARKER}" ]] && grep -qi -- "${LEGACY_WEBMAIL_MARKER}" /tmp/mailpanel-webmail.html; then
  echo "Legacy webmail content is still being served at ${WEBMAIL_PATH}" >&2
  exit 73
fi

printf '\n== FAIL2BAN ==\n'
fail2ban-client status webmail-auth || true
