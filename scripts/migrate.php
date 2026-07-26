<?php

declare(strict_types=1);

/**
 * Tracked migration runner.
 *
 *   php scripts/migrate.php                 Apply pending migrations
 *   php scripts/migrate.php --status        Show applied / pending, then exit
 *   php scripts/migrate.php --dry-run       List what would run, change nothing
 *   php scripts/migrate.php --baseline      Mark all as applied without running
 *                                           (use ONCE on a database already
 *                                           migrated by the old untracked script)
 *
 * Exit codes: 0 success, 1 failure.
 */

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Support\MigrationRunner;

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
Environment::load($basePath);

$options = getopt('', ['status', 'dry-run', 'baseline', 'help']);

$colour = static function (string $level, string $text): string {
    if (!stream_isatty(STDOUT)) {
        return $text;
    }

    $codes = ['ok' => '0;32', 'warn' => '0;33', 'error' => '0;31', 'info' => '0;36'];

    return "\033[" . ($codes[$level] ?? '0') . "m{$text}\033[0m";
};

$emit = static function (string $level, string $message) use ($colour): void {
    $prefix = ['ok' => '  ok ', 'warn' => 'warn ', 'error' => 'FAIL ', 'info' => '     '][$level] ?? '     ';
    fwrite($level === 'error' ? STDERR : STDOUT, $colour($level, $prefix . $message) . PHP_EOL);
};

if (isset($options['help'])) {
    echo <<<TXT
    Usage: php scripts/migrate.php [option]

      (no option)   Apply pending migrations
      --status      Show applied / pending migrations, then exit
      --dry-run     List what would run, change nothing
      --baseline    Mark all migrations as applied WITHOUT executing them.
                    Use once on a database already migrated by the old script.
      --help        This message

    TXT;
    exit(0);
}

try {
    $config = new Config($basePath, [
        'database' => require $basePath . '/config/database.php',
    ]);

    $pdo = (new Database($config->get('database')))->connection();
    $runner = new MigrationRunner($pdo, $basePath . '/database/migrations');

    if (isset($options['status'])) {
        $applied = $runner->applied();
        $pending = $runner->pending();
        $drifted = $runner->drifted();

        echo 'Applied: ' . count($applied) . PHP_EOL;
        foreach (array_keys($applied) as $name) {
            $emit('ok', $name);
        }

        echo 'Pending: ' . count($pending) . PHP_EOL;
        foreach ($pending as $name) {
            $emit('info', $name);
        }

        if ($drifted !== []) {
            echo 'Changed after apply: ' . count($drifted) . PHP_EOL;
            foreach ($drifted as $name) {
                $emit('warn', $name);
            }
        }

        exit(0);
    }

    if (isset($options['baseline'])) {
        $marked = $runner->baseline();

        if ($marked === []) {
            $emit('info', 'Nothing to baseline; every migration is already tracked.');
            exit(0);
        }

        foreach ($marked as $name) {
            $emit('ok', 'Marked as applied (not executed): ' . $name);
        }

        $emit('warn', 'Baseline only records state. Verify the schema actually matches these migrations.');
        exit(0);
    }

    $dryRun = isset($options['dry-run']);
    $applied = $runner->migrate($dryRun, $emit);

    if ($applied === []) {
        $emit('info', 'Database is up to date; no pending migrations.');
    } elseif ($dryRun) {
        $emit('info', sprintf('%d migration(s) would run. Nothing was changed.', count($applied)));
    } else {
        $emit('ok', sprintf('%d migration(s) applied.', count($applied)));
    }

    exit(0);
} catch (Throwable $exception) {
    $emit('error', $exception->getMessage());
    exit(1);
}
