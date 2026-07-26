<?php

declare(strict_types=1);

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Services\WebmailApplicationConfigService;
use MailPanel\Services\WebmailDomainConfigService;
use MailPanel\Services\WebmailPluginDeploymentService;
use MailPanel\Services\WebmailUserStorageService;

$appRoot = dirname(__DIR__);

require $appRoot . '/vendor/autoload.php';

Environment::load($appRoot);

$config = new Config($appRoot, [
    'database' => require $appRoot . '/config/database.php',
    'mailpanel' => require $appRoot . '/config/mailpanel.php',
]);

$webmailEnabled = (bool) $config->get('mailpanel.webmail_enabled', false);
$webmailRoot = (string) $config->get('mailpanel.webmail_public_root', '/var/www/webmail');
$webmailLogPath = (string) $config->get('mailpanel.webmail_log_path', '/var/www/webmail/data/_data_/_default_/logs/auth.log');
$webmailDisplayName = (string) $config->get('mailpanel.webmail_display_name', 'Webmail');
$brandName = trim($webmailDisplayName);
$brandName = $brandName !== '' && strtolower($brandName) !== 'webmail'
    ? $brandName
    : 'MailPanel Webmail';

$database = new Database($config->get('database'));
$domains = (new DomainRepository($database))->all();
$mailboxes = (new MailboxRepository($database))->all();

$applicationConfig = (new WebmailApplicationConfigService(
    $webmailEnabled,
    $webmailRoot,
    $webmailLogPath,
    $brandName
))->sync();
$pluginConfig = (new WebmailPluginDeploymentService(
    $webmailEnabled,
    $webmailRoot,
    '/api/webmail/password-change'
))->sync();
$mailboxStorage = (new WebmailUserStorageService(
    $webmailEnabled,
    $webmailRoot
))->bootstrapMailboxes($mailboxes);

$domainConfig = new WebmailDomainConfigService($webmailEnabled, $webmailRoot);
$domainConfig->syncManagedDomains($domains);

echo json_encode([
    'webmail_enabled' => $webmailEnabled,
    'webmail_root' => $webmailRoot,
    'application_config' => $applicationConfig,
    'plugin' => $pluginConfig,
    'mailbox_storage' => $mailboxStorage,
    'synced_domains' => count($domains),
    'synced_mailboxes' => count($mailboxes),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
