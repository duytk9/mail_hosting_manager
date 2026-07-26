<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Support/AppSecuritySettingsStore.php';

$appRoot = dirname(__DIR__);
$runtimeSecuritySettings = \MailPanel\Support\AppSecuritySettingsStore::load($appRoot);

$superAdminAllowlist = array_values(array_filter(array_map(
    static fn (string $ip): string => trim($ip),
    explode(',', (string) ($_ENV['SUPER_ADMIN_IP_ALLOWLIST'] ?? ''))
)));

$superAdminAllowlist = $runtimeSecuritySettings['super_admin_ip_allowlist'] ?? $superAdminAllowlist;
$superAdminAllowlistEnabled = array_key_exists('super_admin_ip_allowlist_enabled', $runtimeSecuritySettings)
    ? (bool) $runtimeSecuritySettings['super_admin_ip_allowlist_enabled']
    : filter_var($_ENV['SUPER_ADMIN_IP_ALLOWLIST_ENABLED'] ?? false, FILTER_VALIDATE_BOOL);

$safeSessionName = static function (mixed $value): string {
    $name = trim((string) $value);

    return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $name) === 1 ? $name : 'mailpanel_session';
};

$safeCookiePath = static function (mixed $value): string {
    $path = trim((string) $value);

    if ($path === '' || !str_starts_with($path, '/') || preg_match('/[\x00-\x20\x7F;,\\\\]/', $path) === 1) {
        return '/';
    }

    return $path;
};

$safeCookieDomain = static function (mixed $value): string {
    $domain = strtolower(trim((string) $value));

    if ($domain === '') {
        return '';
    }

    if (preg_match('/\A\.?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $domain) !== 1) {
        return '';
    }

    return $domain;
};

$safeSessionTimeout = static function (mixed $value): int {
    $seconds = (int) $value;

    return max(60, min(86400, $seconds));
};

$baseUrl = (string) ($_ENV['APP_URL'] ?? 'http://127.0.0.1:8080');
$baseUrlScheme = (string) (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'http');
$appEnv = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'local'))) ?: 'local';
$appKey = trim((string) ($_ENV['APP_KEY'] ?? ''));
if ($appKey !== '' && preg_match('/[\x00-\x1F\x7F]/', $appKey) === 1) {
    throw new RuntimeException('APP_KEY contains invalid control characters.');
}
if ($appEnv === 'production' && strlen($appKey) < 32) {
    throw new RuntimeException('APP_KEY must be configured with at least 32 characters in production.');
}
$sessionSameSite = ucfirst(strtolower((string) ($_ENV['SESSION_COOKIE_SAMESITE'] ?? 'Strict')));
if (!in_array($sessionSameSite, ['Strict', 'Lax', 'None'], true)) {
    $sessionSameSite = 'Strict';
}
$sessionSecureDefault = $baseUrlScheme === 'https' || $appEnv === 'production' || $sessionSameSite === 'None';
$sessionCookieSecure = filter_var($_ENV['SESSION_COOKIE_SECURE'] ?? $sessionSecureDefault, FILTER_VALIDATE_BOOL);
if ($sessionSameSite === 'None') {
    $sessionCookieSecure = true;
}

return [
    'name' => 'MailPanel',
    'env' => $appEnv,
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? ($appEnv !== 'production'), FILTER_VALIDATE_BOOL),
    'key' => $appKey,
    'base_url' => $baseUrl,
    'session_name' => $safeSessionName($_ENV['SESSION_NAME'] ?? 'mailpanel_session'),
    'session' => [
        'timeout_seconds' => $safeSessionTimeout($_ENV['SESSION_TIMEOUT_SECONDS'] ?? 1800),
        'cookie_secure' => $sessionCookieSecure,
        'cookie_http_only' => true,
        'cookie_same_site' => $sessionSameSite,
        'cookie_path' => $safeCookiePath($_ENV['SESSION_COOKIE_PATH'] ?? '/'),
        'cookie_domain' => $safeCookieDomain($_ENV['SESSION_COOKIE_DOMAIN'] ?? ''),
    ],
    'csrf' => [
        'header' => $_ENV['CSRF_HEADER'] ?? 'X-CSRF-Token',
    ],
    'rate_limits' => [
        'api' => [
            'max_attempts' => (int) ($_ENV['RATE_LIMIT_API_MAX_ATTEMPTS'] ?? 120),
            'window_seconds' => (int) ($_ENV['RATE_LIMIT_API_WINDOW_SECONDS'] ?? 60),
        ],
        'admin_login' => [
            'max_attempts' => (int) ($_ENV['RATE_LIMIT_ADMIN_LOGIN_MAX_ATTEMPTS'] ?? 5),
            'window_seconds' => (int) ($_ENV['RATE_LIMIT_ADMIN_LOGIN_WINDOW_SECONDS'] ?? 900),
        ],
        'mailbox_login' => [
            'max_attempts' => (int) ($_ENV['RATE_LIMIT_MAILBOX_LOGIN_MAX_ATTEMPTS'] ?? 5),
            'window_seconds' => (int) ($_ENV['RATE_LIMIT_MAILBOX_LOGIN_WINDOW_SECONDS'] ?? 900),
        ],
        'mailbox_password_change' => [
            'max_attempts' => (int) ($_ENV['RATE_LIMIT_MAILBOX_PASSWORD_CHANGE_MAX_ATTEMPTS'] ?? 5),
            'window_seconds' => (int) ($_ENV['RATE_LIMIT_MAILBOX_PASSWORD_CHANGE_WINDOW_SECONDS'] ?? 900),
        ],
    ],
    'password_policy' => [
        'min_length' => (int) ($_ENV['PASSWORD_MIN_LENGTH'] ?? 12),
        'require_uppercase' => filter_var($_ENV['PASSWORD_REQUIRE_UPPERCASE'] ?? true, FILTER_VALIDATE_BOOL),
        'require_lowercase' => filter_var($_ENV['PASSWORD_REQUIRE_LOWERCASE'] ?? true, FILTER_VALIDATE_BOOL),
        'require_number' => filter_var($_ENV['PASSWORD_REQUIRE_NUMBER'] ?? true, FILTER_VALIDATE_BOOL),
        'require_symbol' => filter_var($_ENV['PASSWORD_REQUIRE_SYMBOL'] ?? true, FILTER_VALIDATE_BOOL),
        'prevent_whitespace' => filter_var($_ENV['PASSWORD_PREVENT_WHITESPACE'] ?? true, FILTER_VALIDATE_BOOL),
        'enforce_history' => filter_var($_ENV['PASSWORD_ENFORCE_HISTORY'] ?? false, FILTER_VALIDATE_BOOL),
        'history_count' => (int) ($_ENV['PASSWORD_HISTORY_COUNT'] ?? 5),
    ],
    'totp' => [
        'issuer' => $_ENV['TOTP_ISSUER'] ?? 'MailPanel',
        'window' => (int) ($_ENV['TOTP_WINDOW'] ?? 1),
        'encryption_key' => $_ENV['TOTP_ENCRYPTION_KEY'] ?? ($_ENV['APP_KEY'] ?? ''),
    ],
    'admin_auth' => [
        'mode' => $_ENV['ADMIN_AUTH_MODE'] ?? 'hybrid',
    ],
    'super_admin_ip_allowlist_enabled' => $superAdminAllowlistEnabled,
    'super_admin_ip_allowlist' => $superAdminAllowlist,
];
