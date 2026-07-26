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

// This script lives in bin/, so every path must climb one level. It previously
// used __DIR__ directly, which resolved to bin/vendor/autoload.php and bin/.env
// and made the whole command unusable.
$appRoot = dirname(__DIR__);

require_once $appRoot . '/vendor/autoload.php';

Environment::load($appRoot);

$config = new Config($appRoot, [
    'app' => require $appRoot . '/config/app.php',
    'database' => require $appRoot . '/config/database.php',
    'mailpanel' => require $appRoot . '/config/mailpanel.php',
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

if (!in_array($command, ['status', 'reset', 'create'], true)) {
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

if ($command === 'create') {
    if ($user !== null) {
        fwrite(STDERR, "An account already exists for [{$email}]. Use 'reset' to change its password.\n");
        exit(1);
    }

    $username = strtolower(trim((string) ($options['username'] ?? '')));
    if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/', $username) !== 1) {
        fwrite(STDERR, "Missing or invalid --username. The panel logs in by system username.\n");
        exit(1);
    }

    if ($users->findByLinuxUsername($username) !== null) {
        fwrite(STDERR, "Username [{$username}] is already taken.\n");
        exit(1);
    }

    $role = (string) ($options['role'] ?? 'super_admin');
    if (!in_array($role, ['super_admin', 'support_readonly'], true)) {
        fwrite(STDERR, "Invalid --role. Only super_admin or support_readonly may be created here.\n");
        exit(1);
    }

    $password = readPassword($options);
    if ($password === '') {
        fwrite(STDERR, "Missing password. Use --password-stdin, --password-file=<path>, or --password-env=<ENV_NAME>.\n");
        exit(1);
    }

    try {
        $passwordPolicy->assertStrong($password);
        $hash = $passwordHasher->hash($password);

        $created = $users->create([
            'tenant_id' => null,
            'role' => $role,
            'name' => (string) ($options['name'] ?? 'Administrator'),
            'email' => $email,
            'password_hash' => $hash,
            'linux_username' => $username,
            'force_password_change' => array_key_exists('force-password-change', $options) ? 1 : 0,
        ]);

        $history->store((int) $created['id'], null, $hash);

        $auditLog->log([
            'actor_id' => null,
            'actor_role' => 'system',
            'tenant_id' => null,
            'action' => 'system.admin_account_created',
            'target_type' => 'user',
            'target_id' => $created['id'] ?? null,
            'new_values' => ['email' => $email, 'username' => $username, 'role' => $role],
        ]);
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }

    echo json_encode([
        'status' => 'created',
        'id' => (int) ($created['id'] ?? 0),
        'email' => $email,
        'username' => $username,
        'role' => $role,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(0);
}

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
  php bin/admin_account.php create --email=admin@example.test --username=opsadmin --password-stdin [--name='Ops Admin'] [--role=super_admin] [--force-password-change]
  php bin/admin_account.php status --email=admin@example.test
  printf '%s\n' '<new-strong-password>' | php admin_account.php reset --email=admin@example.test --password-stdin [--disable-totp] [--force-password-change]
  php admin_account.php reset --email=admin@example.test --password-file=/root/mailpanel-admin-password [--disable-totp] [--force-password-change]
  MAILPANEL_TEMP_PASSWORD='<new-strong-password>' php admin_account.php reset --email=admin@example.test --password-env=MAILPANEL_TEMP_PASSWORD

Examples:
  php admin_account.php status --email=admin@example.test
  printf '%s\n' '<temporary-strong-password>' | php admin_account.php reset --email=admin@example.test --password-stdin --disable-totp

TXT;
}
