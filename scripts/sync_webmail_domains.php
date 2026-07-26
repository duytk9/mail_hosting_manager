<?php

declare(strict_types=1);

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Services\WebmailDomainConfigService;

$appRoot = dirname(__DIR__);

require $appRoot . '/vendor/autoload.php';

Environment::load($appRoot);

$config = new Config($appRoot, [
    'database' => require $appRoot . '/config/database.php',
    'mailpanel' => require $appRoot . '/config/mailpanel.php',
]);

$database = new Database($config->get('database'));
$domains = (new DomainRepository($database))->all();

$service = new WebmailDomainConfigService(
    (bool) $config->get('mailpanel.webmail_enabled', false),
    (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail')
);

$service->syncManagedDomains($domains);

echo json_encode([
    'synced' => count($domains),
    'webmail_root' => (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
