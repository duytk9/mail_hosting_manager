<?php

declare(strict_types=1);

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\PasswordPolicyService;

require_once __DIR__ . '/vendor/autoload.php';

Environment::load(__DIR__);

$config = new Config(__DIR__, [
    'app' => require __DIR__ . '/config/app.php',
    'database' => require __DIR__ . '/config/database.php',
    'mailpanel' => require __DIR__ . '/config/mailpanel.php',
]);

$database = new Database($config->get('database'));
$users = new UserRepository($database);
$history = new UserPasswordHistoryRepository($database);
$auditLog = new AuditLogService($database);
$passwordPolicy = new PasswordPolicyService($config->get('app.password_policy', []));
$passwordHasher = new PasswordHashingService((string) $config->get('mailpanel.password_algorithm', 'bcrypt'));

$command = $argv[1] ?? null;
$options = parseOptions(array_slice($argv, 2));

if ($command === null || in_array($command, ['-h', '--help', 'help'], true)) {
    printUsage();
    exit(0);
}

if (!in_array($command, ['status', 'reset'], true)) {
    fwrite(STDERR, "Unknown command [{$command}].\n\n");
    printUsage();
    exit(1);
}

$email = strtolower(trim((string) ($options['email'] ?? '')));
if ($email === '') {
    fwrite(STDERR, "Missing required option --email.\n");
    exit(1);
}

$user = $users->findByEmail($email);
if ($user === null) {
    fwrite(STDERR, "Admin user not found for [{$email}].\n");
    exit(1);
}

if ($command === 'status') {
    echo json_encode([
        'id' => (int) ($user['id'] ?? 0),
        'email' => (string) ($user['email'] ?? ''),
        'role' => (string) ($user['role'] ?? ''),
        'force_password_change' => !empty($user['force_password_change']),
        'totp_enabled' => !empty($user['totp_enabled']),
        'last_login_at' => $user['last_login_at'] ?? null,
        'password_changed_at' => $user['password_changed_at'] ?? null,
        'deleted_at' => $user['deleted_at'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

$password = readPassword($options);
if ($password === '') {
    fwrite(STDERR, "Missing password. Use --password-stdin, --password-file=<path>, or --password-env=<ENV_NAME>.\n");
    exit(1);
}

$disableTotp = array_key_exists('disable-totp', $options);
$requirePasswordChange = array_key_exists('force-password-change', $options);

try {
    $passwordPolicy->assertStrong($password);
    $passwordPolicy->assertNotReused(
        $password,
        $history->recentHashesForUser((int) $user['id'], $passwordPolicy->historyCount())
    );

    $hash = $passwordHasher->hash($password);
    $users->updatePassword((int) $user['id'], $hash);
    $history->store((int) $user['id'], isset($user['tenant_id']) ? (int) $user['tenant_id'] : null, $hash);

    if ($requirePasswordChange) {
        $users->updateForcePasswordChange((int) $user['id'], true);
    }

    if ($disableTotp) {
        $users->disableTotp((int) $user['id']);
    }

    $auditLog->log([
        'actor_id' => null,
        'actor_role' => 'system',
        'tenant_id' => $user['tenant_id'] ?? null,
        'action' => 'system.admin_account_reset',
        'target_type' => 'user',
        'target_id' => $user['id'],
        'new_values' => [
            'email' => $user['email'] ?? null,
            'role' => $user['role'] ?? null,
            'totp_disabled' => $disableTotp,
            'force_password_change' => $requirePasswordChange,
        ],
    ]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

echo json_encode([
    'status' => 'ok',
    'email' => (string) ($user['email'] ?? ''),
    'role' => (string) ($user['role'] ?? ''),
    'totp_disabled' => $disableTotp,
    'force_password_change' => $requirePasswordChange,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

function parseOptions(array $arguments): array
{
    $options = [];

    foreach ($arguments as $argument) {
        if (!str_starts_with($argument, '--')) {
            continue;
        }

        $argument = substr($argument, 2);
        if ($argument === '') {
            continue;
        }

        if (!str_contains($argument, '=')) {
            $options[$argument] = true;
            continue;
        }

        [$key, $value] = explode('=', $argument, 2);
        $options[$key] = $value;
    }

    return $options;
}

function readPassword(array $options): string
{
    if (isset($options['password'])) {
        if (getenv('MAILPANEL_ALLOW_INSECURE_ARG_PASSWORD') !== '1') {
            fwrite(STDERR, "Refusing --password because command-line arguments can leak via process lists and shell history.\n");
            fwrite(STDERR, "Use --password-stdin, --password-file=<path>, or --password-env=<ENV_NAME> instead.\n");
            exit(1);
        }

        return (string) $options['password'];
    }

    if (array_key_exists('password-stdin', $options)) {
        $password = fgets(STDIN);

        return is_string($password) ? rtrim($password, "\r\n") : '';
    }

    if (isset($options['password-file'])) {
        $path = (string) $options['password-file'];
        if ($path === '' || !is_file($path)) {
            fwrite(STDERR, "Password file not found.\n");
            exit(1);
        }

        $password = file_get_contents($path);
        if ($password === false) {
            fwrite(STDERR, "Unable to read password file.\n");
            exit(1);
        }

        return rtrim($password, "\r\n");
    }

    if (isset($options['password-env'])) {
        $envName = (string) $options['password-env'];
        if (!preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $envName)) {
            fwrite(STDERR, "Invalid password environment variable name.\n");
            exit(1);
        }

        $password = getenv($envName);

        return is_string($password) ? $password : '';
    }

    return '';
}

function printUsage(): void
{
    echo <<<TXT
Usage:
  php admin_account.php status --email=admin@example.test
  printf '%s\n' '<new-strong-password>' | php admin_account.php reset --email=admin@example.test --password-stdin [--disable-totp] [--force-password-change]
  php admin_account.php reset --email=admin@example.test --password-file=/root/mailpanel-admin-password [--disable-totp] [--force-password-change]
  MAILPANEL_TEMP_PASSWORD='<new-strong-password>' php admin_account.php reset --email=admin@example.test --password-env=MAILPANEL_TEMP_PASSWORD

Examples:
  php admin_account.php status --email=admin@example.test
  printf '%s\n' '<temporary-strong-password>' | php admin_account.php reset --email=admin@example.test --password-stdin --disable-totp

TXT;
}
