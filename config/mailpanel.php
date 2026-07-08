<?php

declare(strict_types=1);

$boolEnv = static function (string $key, bool $default = false): bool {
    $value = $_ENV[$key] ?? ($default ? '1' : '0');

    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
};

$appUrl = (string) ($_ENV['APP_URL'] ?? 'http://127.0.0.1');

$appSettingsFile = dirname(__DIR__) . '/storage/app_settings/app_security.json';
if (is_file($appSettingsFile)) {
    $contents = file_get_contents($appSettingsFile);
    if ($contents !== false) {
        $decoded = json_decode(preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents, true);
        if (is_array($decoded) && !empty($decoded['portal_domain'])) {
            $portalDomain = (string) $decoded['portal_domain'];
            $appUrl = 'https://' . $portalDomain;
            $_ENV['NGINX_SERVER_NAME'] = $portalDomain;
            
            $sniRoot = $_ENV['TLS_SNI_ROOT'] ?? '/etc/mailpanel/tls/sni';
            $certPath = $sniRoot . '/' . $portalDomain . '.pem';
            $keyPath = $sniRoot . '/' . $portalDomain . '.key';
            if (is_file($certPath) && is_file($keyPath)) {
                $_ENV['NGINX_TLS_CERTIFICATE'] = $certPath;
                $_ENV['NGINX_TLS_PRIVATEKEY'] = $keyPath;
            }
        }
    }
}

$appHost = (string) (parse_url($appUrl, PHP_URL_HOST) ?: '_');
$legacyWebmailEnabled = $boolEnv('LEGACY_WEBMAIL_ENABLED');
$webmailEnabled = $boolEnv('WEBMAIL_ENABLED', $legacyWebmailEnabled);
$webmailDriver = trim(strtolower((string) ($_ENV['WEBMAIL_DRIVER'] ?? ($legacyWebmailEnabled ? 'legacy-webmail' : 'webmail'))));
$webmailDriver = $webmailDriver !== '' ? $webmailDriver : 'webmail';
$legacyWebmailLogPath = (string) ($_ENV['LEGACY_WEBMAIL_LOG_PATH'] ?? '/var/log/legacy-webmail/errors.log');
$webmailLogPath = (string) ($_ENV['WEBMAIL_LOG_PATH'] ?? ($legacyWebmailEnabled
    ? $legacyWebmailLogPath
    : '/var/www/webmail/logs/userlogins.log'));
$webmailPublicRoot = (string) ($_ENV['WEBMAIL_PUBLIC_ROOT'] ?? '/var/www/webmail');
$webmailDisplayName = (string) ($_ENV['WEBMAIL_DISPLAY_NAME'] ?? 'Webmail');

return [
    'app_root' => $_ENV['APP_ROOT'] ?? '/opt/mailpanel',
    'generated_root' => $_ENV['GENERATED_ROOT'] ?? dirname(__DIR__) . '/storage/generated',
    'vmail_root' => $_ENV['VMAIL_ROOT'] ?? '/var/vmail',
    'vmail_uid' => (int) ($_ENV['VMAIL_UID'] ?? 2000),
    'vmail_gid' => (int) ($_ENV['VMAIL_GID'] ?? 2000),
    'system_user' => $_ENV['SYSTEM_USER'] ?? 'mailpanel-agent',
    'agent_binary' => $_ENV['AGENT_BINARY'] ?? '/usr/local/bin/mailpanel-agent',
    'web_agent_binary' => $_ENV['WEB_AGENT_BINARY'] ?? '/usr/local/bin/mailpanel-web-agent',
    'sudo_binary' => $_ENV['SUDO_BINARY'] ?? 'sudo',
    'agent_timeout_seconds' => (int) ($_ENV['AGENT_TIMEOUT_SECONDS'] ?? 60),
    'tenant_suspend_inbound_mode' => $_ENV['TENANT_SUSPEND_INBOUND_MODE'] ?? 'accept',
    'dkim_selector' => $_ENV['DKIM_SELECTOR'] ?? 'mail',
    'mail_host_label' => $_ENV['MAIL_HOST_LABEL'] ?? 'mail',
    'password_algorithm' => $_ENV['PASSWORD_ALGORITHM'] ?? (defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt'),
    'dovecot_pass_scheme' => $_ENV['DOVECOT_PASS_SCHEME'] ?? (defined('PASSWORD_ARGON2ID') ? 'ARGON2ID' : 'BLF-CRYPT'),
    'exim_tls_certificate' => $_ENV['EXIM_TLS_CERTIFICATE'] ?? '/etc/exim4/ssl/mailpanel.pem',
    'exim_tls_privatekey' => $_ENV['EXIM_TLS_PRIVATEKEY'] ?? '/etc/exim4/ssl/mailpanel.key',
    'exim_submission_ports' => $_ENV['EXIM_SUBMISSION_PORTS'] ?? '25 : 465 : 587',
    'exim_auth_ports' => $_ENV['EXIM_AUTH_PORTS'] ?? ($_ENV['EXIM_SUBMISSION_AUTH_PORTS'] ?? '465 : 587'),
    'exim_tls_on_connect_ports' => $_ENV['EXIM_TLS_ON_CONNECT_PORTS'] ?? '465',
    'nginx_root' => $_ENV['NGINX_ROOT'] ?? '/opt/mailpanel/public',
    'nginx_server_name' => $_ENV['NGINX_SERVER_NAME'] ?? $appHost,
    'nginx_tls_certificate' => $_ENV['NGINX_TLS_CERTIFICATE'] ?? '/etc/ssl/certs/ssl-cert-snakeoil.pem',
    'nginx_tls_privatekey' => $_ENV['NGINX_TLS_PRIVATEKEY'] ?? '/etc/ssl/private/ssl-cert-snakeoil.key',
    'nginx_php_fpm_socket' => $_ENV['NGINX_PHP_FPM_SOCKET'] ?? '/run/php/php8.3-fpm.sock',
    'webmail_path' => $_ENV['WEBMAIL_PATH'] ?? '/webmail',
    'webmail_enabled' => $webmailEnabled,
    'webmail_driver' => $webmailDriver,
    'webmail_public_root' => $webmailPublicRoot,
    'webmail_log_path' => $webmailLogPath,
    'webmail_display_name' => $webmailDisplayName,
    'acme_webroot' => $_ENV['ACME_WEBROOT'] ?? '/var/www/acme',
    'acme_email' => $_ENV['ACME_EMAIL'] ?? ($_ENV['CERTBOT_EMAIL'] ?? ''),
    'acme_auto_issue_on_domain_create' => $boolEnv('ACME_AUTO_ISSUE_ON_DOMAIN_CREATE', true),
    'acme_default_profile' => $_ENV['ACME_DEFAULT_PROFILE'] ?? 'mail_only',
    'tls_sni_root' => $_ENV['TLS_SNI_ROOT'] ?? '/etc/mailpanel/tls/sni',
    'tenant_expiry_warning_days' => (int) ($_ENV['TENANT_EXPIRY_WARNING_DAYS'] ?? 14),
    'tenant_default_grace_days' => (int) ($_ENV['TENANT_DEFAULT_GRACE_DAYS'] ?? 7),
    'tenant_expired_inbound_mode' => $_ENV['TENANT_EXPIRED_INBOUND_MODE'] ?? 'accept',
    'legacy_webmail_enabled' => $webmailEnabled,
    'legacy_webmail_log_path' => $webmailLogPath,
];
