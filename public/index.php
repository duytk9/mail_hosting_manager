<?php

declare(strict_types=1);

use MailPanel\Bootstrap\ApplicationFactory;
use MailPanel\Bootstrap\Environment;

require_once dirname(__DIR__) . '/vendor/autoload.php';
Environment::load(dirname(__DIR__));

/** @var array<string, mixed> $appConfig */
$appConfig = require dirname(__DIR__) . '/config/app.php';

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

$app = ApplicationFactory::create(dirname(__DIR__));
$response = $app->handleCurrentRequest();
$response->send();
