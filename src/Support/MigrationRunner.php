<?php

declare(strict_types=1);

namespace MailPanel\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Tracked, idempotent migration runner.
 *
 * Replaces the previous scripts/migrate.php which re-executed every .sql file on
 * every run with no tracking and no error isolation. That was destructive:
 * migration 010 opened with DELETE statements that removed orphaned production
 * rows, so a second run silently deleted data again.
 *
 * Guarantees:
 *  - Each migration file runs at most once (tracked in `schema_migrations`).
 *  - Statements execute individually so a failure names the exact statement.
 *  - "Object already exists" errors are treated as already-applied, which makes
 *    the runner safe against a database that was migrated by the old script.
 *  - `baseline()` marks existing files as applied without executing them.
 */
final class MigrationRunner
{
    /**
     * MySQL/MariaDB error codes meaning "this DDL was already applied".
     * Tolerating these lets a database migrated by the old untracked script
     * adopt tracking without erroring on every pre-existing object.
     */
    private const ALREADY_APPLIED_CODES = [
        1007, // database exists
        1050, // table exists
        1060, // duplicate column name
        1061, // duplicate key name
        1091, // can't DROP; check that column/key exists
        1826, // duplicate foreign key constraint name
        1022, // duplicate key
    ];

    /** Statements that must never appear in a migration file. */
    private const FORBIDDEN_PATTERNS = [
        '/\ADELETE\s+FROM/i',
        '/\ATRUNCATE/i',
        '/\ADROP\s+DATABASE/i',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
        private readonly bool $allowDestructive = false,
    ) {
    }

    public function ensureTrackingTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(191) NOT NULL PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<int, string> Migration filenames in execution order.
     */
    public function discover(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names, SORT_NATURAL);

        $this->assertNoDuplicatePrefix($names);

        return $names;
    }

    /**
     * @return array<string, string> migration => checksum
     */
    public function applied(): array
    {
        $this->ensureTrackingTable();
        $rows = $this->pdo->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['migration']] = (string) $row['checksum'];
        }

        return $map;
    }

    /**
     * @return array<int, string> Migrations not yet applied.
     */
    public function pending(): array
    {
        $applied = $this->applied();

        return array_values(array_filter(
            $this->discover(),
            static fn (string $name): bool => !isset($applied[$name])
        ));
    }

    /**
     * Mark every discovered migration as applied without executing it.
     * Use once on a database that was already migrated by the old script.
     *
     * @return array<int, string>
     */
    public function baseline(): array
    {
        $this->ensureTrackingTable();
        $marked = [];

        foreach ($this->pending() as $name) {
            $this->recordApplied($name, $this->checksum($name), 0);
            $marked[] = $name;
        }

        return $marked;
    }

    /**
     * Detect migration files whose content changed after being applied.
     *
     * @return array<int, string>
     */
    public function drifted(): array
    {
        $applied = $this->applied();
        $drifted = [];

        foreach ($this->discover() as $name) {
            if (!isset($applied[$name])) {
                continue;
            }

            if (!hash_equals($applied[$name], $this->checksum($name))) {
                $drifted[] = $name;
            }
        }

        return $drifted;
    }

    /**
     * Run all pending migrations.
     *
     * @param callable(string, string):void|null $onEvent Receives (level, message).
     * @return array<int, string> Migrations that were applied.
     */
    public function migrate(bool $dryRun = false, ?callable $onEvent = null): array
    {
        $this->ensureTrackingTable();
        $emit = $onEvent ?? static function (): void {};
        $done = [];

        foreach ($this->drifted() as $name) {
            $emit('warn', "Migration [{$name}] changed after it was applied. Content drift is not re-run; create a new migration instead.");
        }

        foreach ($this->pending() as $name) {
            $statements = $this->statementsFor($name);
            $this->assertNotDestructive($name, $statements);

            if ($dryRun) {
                $emit('info', sprintf('[dry-run] %s (%d statement(s))', $name, count($statements)));
                $done[] = $name;
                continue;
            }

            $startedAt = microtime(true);
            $skipped = 0;

            foreach ($statements as $index => $statement) {
                try {
                    $this->pdo->exec($statement);
                } catch (PDOException $exception) {
                    if ($this->isAlreadyApplied($exception)) {
                        $skipped++;
                        continue;
                    }

                    throw new RuntimeException(sprintf(
                        "Migration [%s] failed at statement #%d:\n%s\n\n%s",
                        $name,
                        $index + 1,
                        $this->preview($statement),
                        $exception->getMessage()
                    ), 0, $exception);
                }
            }

            $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->recordApplied($name, $this->checksum($name), $elapsedMs);
            $done[] = $name;

            $suffix = $skipped > 0 ? sprintf(' (%d statement(s) already present, skipped)', $skipped) : '';
            $emit('ok', sprintf('Applied %s in %dms%s', $name, $elapsedMs, $suffix));
        }

        return $done;
    }

    /**
     * @return array<int, string>
     */
    private function statementsFor(string $name): array
    {
        $path = rtrim($this->migrationsPath, '/') . '/' . $name;
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException("Unable to read migration [{$name}].");
        }

        return SqlStatementSplitter::split($sql);
    }

    private function checksum(string $name): string
    {
        $path = rtrim($this->migrationsPath, '/') . '/' . $name;
        $sql = (string) file_get_contents($path);

        // Normalise line endings so a CRLF/LF change is not reported as drift.
        return hash('sha256', str_replace(["\r\n", "\r"], "\n", $sql));
    }

    private function recordApplied(string $name, string $checksum, int $elapsedMs): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO schema_migrations (migration, checksum, execution_ms)
             VALUES (:migration, :checksum, :execution_ms)
             ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), execution_ms = VALUES(execution_ms)'
        );

        $statement->execute([
            'migration' => $name,
            'checksum' => $checksum,
            'execution_ms' => $elapsedMs,
        ]);
    }

    private function isAlreadyApplied(PDOException $exception): bool
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($driverCode, self::ALREADY_APPLIED_CODES, true);
    }

    /**
     * @param array<int, string> $statements
     */
    private function assertNotDestructive(string $name, array $statements): void
    {
        if ($this->allowDestructive) {
            return;
        }

        foreach ($statements as $statement) {
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (preg_match($pattern, ltrim($statement)) === 1) {
                    throw new RuntimeException(sprintf(
                        "Migration [%s] contains a destructive data statement:\n%s\n\n"
                        . 'Schema migrations must not delete rows. Move data cleanup to '
                        . 'scripts/cleanup_orphans.php, which requires explicit confirmation.',
                        $name,
                        $this->preview($statement)
                    ));
                }
            }
        }
    }

    /**
     * @param array<int, string> $names
     */
    private function assertNoDuplicatePrefix(array $names): void
    {
        $seen = [];

        foreach ($names as $name) {
            if (preg_match('/\A(\d+)_/', $name, $matches) !== 1) {
                continue;
            }

            $prefix = $matches[1];
            if (isset($seen[$prefix])) {
                throw new RuntimeException(sprintf(
                    'Duplicate migration number [%s]: "%s" and "%s". '
                    . 'Execution order would depend on filename sorting. Renumber one of them.',
                    $prefix,
                    $seen[$prefix],
                    $name
                ));
            }

            $seen[$prefix] = $name;
        }
    }

    private function preview(string $statement): string
    {
        $collapsed = trim(preg_replace('/\s+/', ' ', $statement) ?? $statement);

        return strlen($collapsed) > 300 ? substr($collapsed, 0, 300) . ' ...' : $collapsed;
    }
}
