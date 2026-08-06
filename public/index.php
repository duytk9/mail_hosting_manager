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
    echo "[MailPanel] .env not loaded — DB_HOST and DB_DATABASE are both empty.\n";
    echo "Ensure " . $basePath . "/.env exists (or is symlinked from /etc/mailpanel/.env).\n";
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

