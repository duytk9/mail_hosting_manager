<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Contracts\SuperAdminLinuxAccountManager;
use MailPanel\Core\Database;
use MailPanel\Core\Request;
use MailPanel\Http\Controllers\AuthController;
use MailPanel\Repositories\Pdo\ApiTokenRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TokenGuard;
use MailPanel\Security\TotpService;
use MailPanel\Services\AdminPasswordVerifier;
use MailPanel\Services\AdminSecurityService;
use MailPanel\Services\ApiTokenService;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\AuthService;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\PasswordPolicyService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuthControllerLoginKeyTest extends TestCase
{
    private ?string $sqlitePath = null;

    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }

        parent::tearDown();
    }

    public function test_issue_login_key_returns_friendly_aliases(): void
    {
        $this->requireSqliteDriver();
        $passwordHasher = new PasswordHashingService('bcrypt');
        $database = $this->makeDatabase($passwordHasher->hash('AdminStrong123!'));
        $sessions = new SessionManager();
        $sessions->putIdentity(['id' => 7, 'role' => 'super_admin'], 'admin');

        $controller = new AuthController(
            $this->makeAuthServiceStub(),
            $sessions,
            new RequestActorResolver($sessions),
            new AuthorizationService(),
            new ApiTokenService(
                new class extends ApiTokenRepository {
                    public function __construct()
                    {
                    }

                    public function create(array $data): array
                    {
                        return [
                            'id' => 55,
                            'tenant_id' => $data['tenant_id'],
                            'user_id' => $data['user_id'],
                            'actor_role' => $data['actor_role'],
                            'name' => $data['name'],
                            'scopes' => $data['scopes'],
                            'expires_at' => $data['expires_at'],
                        ];
                    }
                },
                new class extends AuditLogService {
                    public function __construct()
                    {
                    }

                    public function log(array $entry): void
                    {
                    }
                }
            ),
            $this->makeAdminSecurityService($database, $sessions, $passwordHasher)
        );

        $response = $controller->issueLoginKey(new Request(
            'POST',
            '/api/admin/login-keys',
            [],
            [
                'login_key_name' => 'ops-key',
                'scopes' => ['dashboard.read'],
                'current_password' => 'AdminStrong123!',
            ],
            []
        ));

        ob_start();
        $response->send();
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload['success']);
        self::assertSame('ops-key', $payload['data']['name']);
        self::assertSame('Login Key', $payload['data']['credential_label']);
        self::assertArrayHasKey('plain_text_token', $payload['data']);
        self::assertSame($payload['data']['plain_text_token'], $payload['data']['login_key']);
        self::assertSame($payload['data']['plain_text_token'], $payload['data']['plain_text_login_key']);
    }

    public function test_issue_login_key_requires_current_password(): void
    {
        $this->requireSqliteDriver();
        $passwordHasher = new PasswordHashingService('bcrypt');
        $database = $this->makeDatabase($passwordHasher->hash('AdminStrong123!'));
        $sessions = new SessionManager();
        $sessions->putIdentity(['id' => 7, 'role' => 'super_admin'], 'admin');

        $controller = new AuthController(
            $this->makeAuthServiceStub(),
            $sessions,
            new RequestActorResolver($sessions),
            new AuthorizationService(),
            new ApiTokenService(
                new class extends ApiTokenRepository {
                    public function __construct()
                    {
                    }

                    public function create(array $data): array
                    {
                        return ['id' => 55] + $data;
                    }
                },
                new class extends AuditLogService {
                    public function __construct()
                    {
                    }

                    public function log(array $entry): void
                    {
                    }
                }
            ),
            $this->makeAdminSecurityService($database, $sessions, $passwordHasher)
        );

        $response = $controller->issueLoginKey(new Request(
            'POST',
            '/api/admin/login-keys',
            [],
            [
                'login_key_name' => 'ops-key',
                'scopes' => ['dashboard.read'],
                'current_password' => 'WrongStrong123!',
            ],
            []
        ));

        ob_start();
        $response->send();
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
        self::assertSame('Current password is invalid.', $payload['error']['message']);
    }

    public function test_issue_login_key_rejects_bearer_token_issuance(): void
    {
        $sessions = new SessionManager();
        $tokenGuard = new TokenGuard(new class extends ApiTokenRepository {
            public function __construct()
            {
            }

            public function findByHash(string $tokenHash): ?array
            {
                return [
                    'id' => 99,
                    'user_id' => 7,
                    'tenant_id' => null,
                    'actor_role' => 'super_admin',
                    'scopes' => json_encode(['tokens.write']),
                ];
            }

            public function touch(int $id): void
            {
            }
        });
        $controller = new AuthController(
            $this->makeAuthServiceStub(),
            $sessions,
            new RequestActorResolver($sessions, $tokenGuard),
            new AuthorizationService(),
            new ApiTokenService(
                new class extends ApiTokenRepository {
                    public function __construct()
                    {
                    }

                    public function create(array $data): array
                    {
                        self::fail('Bearer token must not be allowed to mint a new Login Key.');
                    }
                },
                new class extends AuditLogService {
                    public function __construct()
                    {
                    }

                    public function log(array $entry): void
                    {
                    }
                }
            ),
            (new ReflectionClass(AdminSecurityService::class))->newInstanceWithoutConstructor()
        );

        $response = $controller->issueLoginKey(new Request(
            'POST',
            '/api/admin/login-keys',
            [],
            [
                'login_key_name' => 'ops-key',
                'scopes' => ['dashboard.read'],
                'current_password' => 'AdminStrong123!',
            ],
            ['HTTP_AUTHORIZATION' => 'Bearer valid-token']
        ));

        ob_start();
        $response->send();
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['success']);
        self::assertSame('Login Key issuance requires an active admin session.', $payload['error']['message']);
    }

    private function makeAuthServiceStub(): AuthService
    {
        return (new ReflectionClass(AuthService::class))->newInstanceWithoutConstructor();
    }

    private function makeAdminSecurityService(Database $database, SessionManager $sessions, PasswordHashingService $passwordHasher): AdminSecurityService
    {
        $linuxAccounts = new class implements SuperAdminLinuxAccountManager {
            public function syncAccount(string $linuxUsername, bool $sshEnabled, bool $sshSudoEnabled, ?string $sshPublicKey = null, ?string $password = null): void {}
            public function revoke(string $linuxUsername): void {}
            public function purge(string $linuxUsername): void {}
            public function verifyPassword(string $linuxUsername, string $password): bool { return false; }
        };

        return new AdminSecurityService(
            new UserRepository($database),
            new UserPasswordHistoryRepository($database),
            new PasswordPolicyService(),
            $passwordHasher,
            new TotpService(),
            new AuditLogService($database),
            $linuxAccounts,
            new AdminPasswordVerifier($passwordHasher, ['admin_auth' => ['mode' => 'panel']], $linuxAccounts),
            $sessions
        );
    }

    private function makeDatabase(string $passwordHash): Database
    {
        $this->sqlitePath = sys_get_temp_dir() . '/mailpanel-login-key-' . bin2hex(random_bytes(4)) . '.sqlite';
        $database = new Database([
            'driver' => 'sqlite',
            'sqlite_path' => $this->sqlitePath,
        ]);
        $pdo = $database->connection();
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));
        $pdo->exec(
            'CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NULL,
                role TEXT NOT NULL,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                linux_username TEXT NULL,
                password_hash TEXT NOT NULL,
                totp_secret TEXT NULL,
                totp_pending_secret TEXT NULL,
                totp_enabled INTEGER NOT NULL DEFAULT 0,
                force_password_change INTEGER NOT NULL DEFAULT 0,
                deleted_at TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $pdo->exec(
            'CREATE TABLE audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                actor_id INTEGER NULL,
                actor_role TEXT NULL,
                tenant_id INTEGER NULL,
                action TEXT NOT NULL,
                target_type TEXT NOT NULL,
                target_id INTEGER NULL,
                old_values TEXT NULL,
                new_values TEXT NULL,
                ip_address TEXT NULL,
                user_agent TEXT NULL,
                created_at TEXT NULL,
                updated_at TEXT NULL
            )'
        );
        $statement = $pdo->prepare(
            "INSERT INTO users (id, tenant_id, role, name, email, password_hash, force_password_change, created_at, updated_at)
             VALUES (7, NULL, 'super_admin', 'Ops', 'ops@example.test', :password_hash, 0, NOW(), NOW())"
        );
        $statement->execute(['password_hash' => $passwordHash]);

        return $database;
    }

    private function requireSqliteDriver(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('SQLite PDO driver is not available.');
        }
    }
}
