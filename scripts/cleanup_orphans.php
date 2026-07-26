<?php

declare(strict_types=1);

/**
 * Remove orphaned rows that block foreign key creation.
 *
 * This logic used to live inside database/migrations/010_add_foreign_keys.sql.
 * Because the old runner re-executed every migration on every invocation, those
 * DELETE statements ran again on each deploy and silently destroyed data. Data
 * cleanup is an operational task, not a schema migration, so it lives here and
 * refuses to delete anything without an explicit flag.
 *
 *   php scripts/cleanup_orphans.php            Report only (default, safe)
 *   php scripts/cleanup_orphans.php --apply    Actually delete, after confirmation
 *   php scripts/cleanup_orphans.php --apply --force
 *                                              Delete without the interactive prompt
 *
 * Run this BEFORE the foreign key migration if that migration fails with
 * "Cannot add or update a child row".
 */

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
Environment::load($basePath);

$options = getopt('', ['apply', 'force', 'help']);

if (isset($options['help'])) {
    echo <<<TXT
    Usage: php scripts/cleanup_orphans.php [--apply] [--force]

      (no option)  Report orphaned rows, delete nothing
      --apply      Delete the reported rows (asks for confirmation)
      --force      Skip the confirmation prompt (requires --apply)

    TXT;
    exit(0);
}

/**
 * Each entry: table => [description, WHERE clause identifying orphans].
 * Order matters: children are cleaned before parents.
 */
$targets = [
    ['quota_usage', 'quota usage without a mailbox', 'mailbox_id NOT IN (SELECT id FROM mailboxes)'],
    ['mailbox_password_history', 'password history without a mailbox', 'mailbox_id NOT IN (SELECT id FROM mailboxes)'],
    ['user_password_history', 'password history without a user', 'user_id NOT IN (SELECT id FROM users)'],
    ['api_tokens', 'tokens without a user', 'user_id NOT IN (SELECT id FROM users)'],
    ['dns_checks', 'DNS checks without a domain', 'domain_id NOT IN (SELECT id FROM domains)'],
    ['dkim_keys', 'DKIM keys without a domain', 'domain_id NOT IN (SELECT id FROM domains)'],
    ['spam_policies', 'spam policies with a missing tenant', 'tenant_id IS NOT NULL AND tenant_id NOT IN (SELECT id FROM tenants)'],
    ['spam_policies', 'spam policies with a missing domain', 'domain_id IS NOT NULL AND domain_id NOT IN (SELECT id FROM domains)'],
    ['mailboxes', 'mailboxes without a tenant or domain', 'tenant_id NOT IN (SELECT id FROM tenants) OR domain_id NOT IN (SELECT id FROM domains)'],
    ['aliases', 'aliases without a tenant or domain', 'tenant_id NOT IN (SELECT id FROM tenants) OR domain_id NOT IN (SELECT id FROM domains)'],
    ['forwards', 'forwards without a tenant or domain', 'tenant_id NOT IN (SELECT id FROM tenants) OR domain_id NOT IN (SELECT id FROM domains)'],
    ['mail_groups', 'mail groups without a tenant or domain', 'tenant_id NOT IN (SELECT id FROM tenants) OR domain_id NOT IN (SELECT id FROM domains)'],
    ['tenant_subscriptions', 'subscriptions without a tenant', 'tenant_id NOT IN (SELECT id FROM tenants)'],
    ['tenant_lifecycle_events', 'lifecycle events without a tenant', 'tenant_id NOT IN (SELECT id FROM tenants)'],
    ['domains', 'domains without a tenant', 'tenant_id NOT IN (SELECT id FROM tenants)'],
    ['users', 'users without a tenant', 'tenant_id IS NOT NULL AND tenant_id NOT IN (SELECT id FROM tenants)'],
    ['tenants', 'tenants without a package', 'package_id NOT IN (SELECT id FROM packages)'],
];

try {
    $config = new Config($basePath, [
        'database' => require $basePath . '/config/database.php',
    ]);
    $pdo = (new Database($config->get('database')))->connection();

    $findings = [];
    $total = 0;

    foreach ($targets as [$table, $description, $where]) {
        try {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
        } catch (PDOException $exception) {
            fwrite(STDERR, "  skip {$table}: {$exception->getMessage()}" . PHP_EOL);
            continue;
        }

        if ($count > 0) {
            $findings[] = [$table, $description, $where, $count];
            $total += $count;
            printf("  %-28s %6d  %s%s", $table, $count, $description, PHP_EOL);
        }
    }

    if ($total === 0) {
        echo 'No orphaned rows found. Nothing to clean up.' . PHP_EOL;
        exit(0);
    }

    printf('%sTotal orphaned rows: %d%s', PHP_EOL, $total, PHP_EOL);

    if (!isset($options['apply'])) {
        echo 'Report only. Re-run with --apply to delete these rows.' . PHP_EOL;
        exit(0);
    }

    if (!isset($options['force'])) {
        echo PHP_EOL . "This permanently deletes {$total} row(s). Take a database backup first." . PHP_EOL;
        echo 'Type "DELETE" to continue: ';
        $answer = trim((string) fgets(STDIN));

        if ($answer !== 'DELETE') {
            echo 'Aborted. Nothing was changed.' . PHP_EOL;
            exit(1);
        }
    }

    $deleted = 0;
    foreach ($findings as [$table, $description, $where, $count]) {
        $affected = $pdo->exec("DELETE FROM {$table} WHERE {$where}");
        $deleted += (int) $affected;
        printf('  deleted %6d from %s%s', (int) $affected, $table, PHP_EOL);
    }

    printf('%sDeleted %d row(s).%s', PHP_EOL, $deleted, PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
