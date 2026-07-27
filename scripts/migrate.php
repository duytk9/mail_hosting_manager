<?php

declare(strict_types=1);

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;

require_once __DIR__ . '/../vendor/autoload.php';
Environment::load(dirname(__DIR__));

$basePath = dirname(__DIR__);
$options = [
    'baseline_existing' => in_array('--baseline-existing', $argv, true),
    'status' => in_array('--status', $argv, true),
];

$config = new Config($basePath, [
    'database' => require $basePath . '/config/database.php',
]);

$database = new Database($config->get('database'));
$pdo = $database->connection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$supersededMigrations = [
    // 010_add_foreign_keys.sql is superseded by 010_add_foreign_keys_and_indexes.sql.
    '010_add_foreign_keys.sql',
];

$checksumTransitions = [
    // Migration 014 originally dropped indexes that were still required by
    // foreign keys on a fresh database. Existing installations already have
    // equivalent replacement indexes from the former migration 011, so this
    // tightly bound old->new checksum transition is schema-compatible.
    '014_optimize_operational_indexes.sql' => [
        'fd1e6377805ca8df08495c285383575ffb6627725b66b961f48f6743e97008af'
            => '7b0008e5f7e694f9335d8898db15aa71ee70bb29f4aa3a66a315fde5b6016e1f',
    ],
];

$files = array_values(array_filter(
    glob($basePath . '/database/migrations/*.sql') ?: [],
    static fn (string $file): bool => !in_array(basename($file), $supersededMigrations, true)
));
sort($files);

assertUniqueMigrationVersions($files);
ensureMigrationTable($pdo);

if ($options['status']) {
    printMigrationStatus($pdo, $files, $checksumTransitions);
    exit(0);
}

$applied = appliedMigrations($pdo);

if ($applied === [] && hasExistingApplicationTables($pdo)) {
    if (!$options['baseline_existing']) {
        fwrite(STDERR, "Existing database tables were detected without migration history.\n");
        fwrite(STDERR, "Existing MailPanel tables were detected, but schema_migrations is empty.\n");
        fwrite(STDERR, "Review the database, then run: php scripts/migrate.php --baseline-existing\n");
        exit(2);
    }

    baselineExistingDatabase($pdo, $files);
    echo 'Baselined ' . count($files) . ' existing migrations.' . PHP_EOL;
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);
    $checksum = checksum($file);

    if (isset($applied[$name])) {
        if (!checksumIsAccepted($name, (string) $applied[$name], $checksum, $checksumTransitions)) {
            throw new RuntimeException("Applied migration file was modified: checksum mismatch for [{$name}]. Migrations are immutable.");
        }

        continue;
    }

    $sql = file_get_contents($file);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Migration [{$name}] is empty or unreadable.");
    }

    $startedTransaction = false;
    if (supportsTransactionalDdl($pdo) && !$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $pdo->exec($sql);
        recordMigration($pdo, $name, $checksum);

        if ($startedTransaction) {
            $pdo->commit();
        }

        echo 'Applied ' . $name . PHP_EOL;
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

function ensureMigrationTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

function appliedMigrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT migration, checksum FROM schema_migrations ORDER BY migration ASC');
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $applied = [];

    foreach ($rows as $row) {
        $applied[(string) $row['migration']] = (string) $row['checksum'];
    }

    return $applied;
}

function recordMigration(PDO $pdo, string $name, string $checksum): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (migration, checksum, applied_at)
         VALUES (:migration, :checksum, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        'migration' => $name,
        'checksum' => $checksum,
    ]);
}

function baselineExistingDatabase(PDO $pdo, array $files): void
{
    foreach ($files as $file) {
        recordMigration($pdo, basename($file), checksum($file));
    }
}

function printMigrationStatus(PDO $pdo, array $files, array $checksumTransitions): void
{
    $applied = appliedMigrations($pdo);

    foreach ($files as $file) {
        $name = basename($file);
        $checksum = checksum($file);
        if (!isset($applied[$name])) {
            $status = 'pending';
        } elseif (hash_equals((string) $applied[$name], $checksum)) {
            $status = 'applied';
        } elseif (checksumTransitionAllowed($name, (string) $applied[$name], $checksum, $checksumTransitions)) {
            $status = 'applied-compatible';
        } else {
            $status = 'checksum-mismatch';
        }

        echo $status . ' ' . $name . PHP_EOL;
    }
}

function checksumIsAccepted(string $name, string $applied, string $current, array $checksumTransitions): bool
{
    return hash_equals($applied, $current)
        || checksumTransitionAllowed($name, $applied, $current, $checksumTransitions);
}

function checksumTransitionAllowed(string $name, string $applied, string $current, array $checksumTransitions): bool
{
    $expectedCurrent = $checksumTransitions[$name][$applied] ?? null;

    return is_string($expectedCurrent) && hash_equals($expectedCurrent, $current);
}

function checksum(string $file): string
{
    $checksum = hash_file('sha256', $file);
    if (!is_string($checksum)) {
        throw new RuntimeException("Unable to checksum migration [{$file}].");
    }

    return $checksum;
}

function hasExistingApplicationTables(PDO $pdo): bool
{
    foreach (['users', 'tenants', 'domains', 'mailboxes'] as $table) {
        if (tableExists($pdo, $table)) {
            return true;
        }
    }

    return false;
}

function tableExists(PDO $pdo, string $table): bool
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
        $stmt->execute(['table' => $table]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $stmt->execute(['table' => $table]);

    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function supportsTransactionalDdl(PDO $pdo): bool
{
    return !in_array((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql'], true);
}

function assertUniqueMigrationVersions(array $files): void
{
    $seen = [];

    foreach ($files as $file) {
        $name = basename($file);
        if (preg_match('/\A(\d+)_/', $name, $matches) !== 1) {
            throw new RuntimeException("Migration [{$name}] must start with a numeric version prefix.");
        }

        $version = $matches[1];
        if (isset($seen[$version])) {
            throw new RuntimeException("Duplicate migration version [{$version}] in [{$seen[$version]}] and [{$name}].");
        }

        $seen[$version] = $name;
    }
}
