<?php

declare(strict_types=1);

use MailPanel\Bootstrap\ApplicationFactory;
use MailPanel\Bootstrap\Environment;

// ---------------------------------------------------------------------------
// Pre-flight: catch the most common deployment failures early with clear
// messages instead of an opaque 500.
// ---------------------------------------------------------------------------
$basePath = dirname(__DIR__);

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "[MailPanel] vendor/autoload.php not found.\n";
    echo "Run: cd " . $basePath . " && composer install --no-dev --optimize-autoloader\n";
    exit(1);
}

require_once $basePath . '/vendor/autoload.php';
Environment::load($basePath);

if (empty($_ENV['DB_HOST']) && empty($_ENV['DB_DATABASE'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');

    $envFile = $basePath . '/.env';
    echo "[MailPanel] .env not loaded — DB_HOST and DB_DATABASE are both empty.\n\n";
    echo "--- Diagnostic ---\n";
    echo "Base path  : " . $basePath . "\n";
    echo ".env path  : " . $envFile . "\n";
    echo "File exists: " . (file_exists($envFile) ? 'yes' : 'NO') . "\n";
    echo "Is file    : " . (is_file($envFile) ? 'yes' : 'NO') . "\n";
    echo "Is link    : " . (is_link($envFile) ? 'yes -> ' . @readlink($envFile) : 'no') . "\n";
    echo "Readable   : " . (is_readable($envFile) ? 'yes' : 'NO') . "\n";

    if (is_file($envFile) && is_readable($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $dataLines = array_filter($lines, fn($l) => trim($l) !== '' && !str_starts_with(trim($l), '#'));
        echo "Lines      : " . count($lines) . " total, " . count($dataLines) . " config\n";

        if (count($dataLines) === 0) {
            echo "\n[!] .env file exists but has no configuration lines.\n";
            echo "    Regenerate it: sudo rm " . $envFile . " && sudo bash deploy/install.sh\n";
        } else {
            echo "\n[!] .env has config but values were NOT loaded into \$_ENV.\n";
            echo "    This usually means PHP-FPM has env vars pre-set (clear_env=no)\n";
            echo "    that shadow the .env values. Check your PHP-FPM pool config:\n";
            echo "    grep -r 'clear_env\\|env\\[' /etc/php/*/fpm/pool.d/\n";
        }
    } elseif (is_link($envFile)) {
        echo "\n[!] .env is a broken symlink pointing to: " . @readlink($envFile) . "\n";
        echo "    The target file does not exist. Recreate it:\n";
        echo "    sudo bash deploy/install.sh\n";
    } else {
        echo "\n[!] .env file not found at: " . $envFile . "\n";
        echo "    Create it: sudo bash deploy/install.sh\n";
        echo "    Or symlink: sudo ln -sfn /etc/mailpanel/.env " . $envFile . "\n";
    }

    echo "\nPHP user   : " . (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user()) . "\n";
    echo "PHP SAPI   : " . PHP_SAPI . "\n";
    exit(1);
}

/** @var array<string, mixed> $appConfig */
$appConfig = require $basePath . '/config/app.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $sessionConfig = $appConfig['session'] ?? [];
    session_name((string) ($appConfig['session_name'] ?? 'mailpanel_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (string) ($sessionConfig['cookie_path'] ?? '/'),
        'domain' => (string) ($sessionConfig['cookie_domain'] ?? ''),
        'secure' => (bool) ($sessionConfig['cookie_secure'] ?? false),
        'httponly' => (bool) ($sessionConfig['cookie_http_only'] ?? true),
        'samesite' => (string) ($sessionConfig['cookie_same_site'] ?? 'Strict'),
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    session_start();
}

$app = ApplicationFactory::create($basePath);
$response = $app->handleCurrentRequest();
$response->send();

