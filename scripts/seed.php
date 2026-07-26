<?php

declare(strict_types=1);

use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Bootstrap\Environment;

require_once __DIR__ . '/../vendor/autoload.php';
Environment::load(dirname(__DIR__));

$config = new Config(dirname(__DIR__), [
    'database' => require dirname(__DIR__) . '/config/database.php',
]);

$pdo = (new Database($config->get('database')))->connection();
$demoMailboxPassword = (string) ($_ENV['MAILPANEL_SEED_MAILBOX_PASSWORD'] ?? bin2hex(random_bytes(12)) . 'A1!');
$passwordHash = password_hash($demoMailboxPassword, PASSWORD_DEFAULT);

$pdo->exec(<<<SQL
INSERT INTO packages (name, description, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, max_mailbox_quota_mb, max_message_size_mb, outbound_per_hour, outbound_per_day, enable_pop3, enable_imap, enable_managesieve, enable_catchall, enable_external_forwarding, spam_level_default, quarantine_enabled, antivirus_enabled, dkim_enabled, custom_smtp_banner_allowed, retention_days, created_at, updated_at)
VALUES ('Starter', 'Demo package', 5, 50, 50, 20, 20480, 1024, 4096, 25, 500, 5000, 1, 1, 1, 1, 1, 'normal', 1, 1, 1, 0, 30, NOW(), NOW());
SQL);

$packageId = (int) $pdo->lastInsertId();
$pdo->exec(<<<SQL
INSERT INTO tenants (name, slug, status, package_id, is_custom_limits, extra_domains, extra_mailboxes, extra_aliases, extra_forwarders, extra_total_quota_mb, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, allow_catchall, allow_external_forwarding, note, created_at, updated_at)
VALUES ('Demo Tenant', 'demo-tenant', 'active', {$packageId}, 0, 0, 0, 0, 0, 0, 5, 50, 50, 20, 20480, 1024, 1, 1, 'Tenant demo seeded from scripts/seed.php', NOW(), NOW());
SQL);
$tenantId = (int) $pdo->lastInsertId();

$pdo->exec(<<<SQL
INSERT INTO domains (tenant_id, domain, status, is_primary, catchall_mailbox_id, inbound_enabled, outbound_enabled, dkim_enabled, dmarc_policy_expected, created_at, updated_at)
VALUES ({$tenantId}, 'example.test', 'active', 1, NULL, 1, 1, 1, 'quarantine', NOW(), NOW());
SQL);
$domainId = (int) $pdo->lastInsertId();

$demoAdminPassword = (string) ($_ENV['MAILPANEL_SEED_ADMIN_PASSWORD'] ?? bin2hex(random_bytes(12)) . 'A1!');
$adminHash = password_hash($demoAdminPassword, PASSWORD_DEFAULT);
$statement = $pdo->prepare('INSERT INTO users (tenant_id, role, name, email, password_hash, created_at, updated_at) VALUES (NULL, :role, :name, :email, :password_hash, NOW(), NOW())');
$statement->execute([
    'role' => 'super_admin',
    'name' => 'Super Admin',
    'email' => 'admin@example.test',
    'password_hash' => $adminHash,
]);

$mailboxStatement = $pdo->prepare('INSERT INTO mailboxes (tenant_id, domain_id, local_part, email, password_hash, display_name, quota_mb, status, force_password_change, imap_enabled, pop3_enabled, smtp_enabled, managesieve_enabled, created_at, updated_at) VALUES (:tenant_id, :domain_id, :local_part, :email, :password_hash, :display_name, :quota_mb, :status, :force_password_change, :imap_enabled, :pop3_enabled, :smtp_enabled, :managesieve_enabled, NOW(), NOW())');

foreach ([
    ['local_part' => 'demo1', 'email' => 'demo1@example.test', 'display_name' => 'Demo User 1'],
    ['local_part' => 'demo2', 'email' => 'demo2@example.test', 'display_name' => 'Demo User 2'],
] as $mailbox) {
    $mailboxStatement->execute([
        'tenant_id' => $tenantId,
        'domain_id' => $domainId,
        'local_part' => $mailbox['local_part'],
        'email' => $mailbox['email'],
        'password_hash' => $passwordHash,
        'display_name' => $mailbox['display_name'],
        'quota_mb' => 1024,
        'status' => 'active',
        'force_password_change' => 0,
        'imap_enabled' => 1,
        'pop3_enabled' => 1,
        'smtp_enabled' => 1,
        'managesieve_enabled' => 1,
    ]);
}

echo "Seeded demo data.\n";
