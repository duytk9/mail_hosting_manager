<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Contracts\MailboxPasswordManager;
use MailPanel\Repositories\Pdo\AuthRepository;
use MailPanel\Repositories\Pdo\UserPasswordHistoryRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TotpService;
use MailPanel\Services\AdminPasswordVerifier;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\AuthService;
use MailPanel\Services\PasswordHashingService;
use MailPanel\Services\RateLimiterService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * AuthService is the login gate for the whole panel and had no dedicated test.
 * These cases pin down the security decisions: credential verification, the TOTP
 * requirement, the super admin IP allowlist, rate limiting, and the guarantee
 * that secrets never reach the session.
 */
final class AuthServiceLoginPolicyTest extends TestCase
{
    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $auditEntries;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $rateLimitHits;

    protected function setUp(): void
    {
        $_SESSION = [];
        // ArrayObject, not array: PHP forbids by-reference constructor property
        // promotion, so the stubs below need an object handle to append through.
        $this->auditEntries = new \ArrayObject();
        $this->rateLimitHits = new \ArrayObject();
    }

    // ---------------------------------------------------------------- builders

    /**
     * @param array<string, mixed>|null $adminRow
     * @param array<string, mixed> $appConfig
     */
    private function makeService(
        ?array $adminRow,
        array $appConfig = [],
        bool $rateLimitExceeded = false,
    ): AuthService {
        $authRepository = new class ($adminRow) extends AuthRepository {
            /** @param array<string, mixed>|null $row */
            public function __construct(private ?array $row)
            {
            }

            public function findAdminByLogin(string $login): ?array
            {
                return $this->row !== null && strtolower((string) $this->row['linux_username']) === $login
                    ? $this->row
                    : null;
            }

            public function findMailboxByEmail(string $email): ?array
            {
                return null;
            }

            public function updateAdminLastLogin(int $userId): void
            {
            }

            public function updateMailboxLastLogin(int $mailboxId): void
            {
            }
        };

        $users = new class ($adminRow) extends UserRepository {
            /** @param array<string, mixed>|null $row */
            public function __construct(private ?array $row)
            {
            }

            public function find(int $id): ?array
            {
                return $this->row;
            }

            public function updateLastLogin(int $id): void
            {
            }
        };

        $history = new class extends UserPasswordHistoryRepository {
            public function __construct()
            {
            }
        };

        $audit = new class ($this->auditEntries) extends AuditLogService {
            /** @param \ArrayObject<int, array<string, mixed>> $sink */
            public function __construct(private \ArrayObject $sink)
            {
            }

            public function log(array $entry): void
            {
                $this->sink[] = $entry;
            }
        };

        $limiter = new class ($this->rateLimitHits, $rateLimitExceeded) extends RateLimiterService {
            /** @param \ArrayObject<int, string> $sink */
            public function __construct(private \ArrayObject $sink, private bool $exceeded)
            {
            }

            public function assertWithinLimit(string $bucket, int $maxAttempts, int $windowSeconds): void
            {
                if ($this->exceeded) {
                    throw new RuntimeException('Too many requests. Retry after 900 seconds.');
                }
            }

            public function hit(string $bucket, int $maxAttempts, int $windowSeconds): array
            {
                $this->sink[] = $bucket;

                return ['attempts' => $this->sink->count(), 'expires_at' => time() + $windowSeconds];
            }

            public function clear(string $bucket): void
            {
            }
        };

        $mailboxPasswords = new class implements MailboxPasswordManager {
            public function changePassword(int $mailboxId, string $newPassword): void
            {
            }

            public function changePasswordWithCurrent(int $mailboxId, string $currentPassword, string $newPassword): void
            {
            }
        };

        $hasher = new PasswordHashingService();

        return new AuthService(
            $authRepository,
            $users,
            $history,
            $audit,
            new SessionManager(1800),
            $hasher,
            $mailboxPasswords,
            $limiter,
            new TotpService(),
            // Pin panel-only auth: the default "hybrid" mode can fall through to a
            // Linux account lookup, which is not available under test.
            new AdminPasswordVerifier($hasher, $appConfig + ['admin_auth' => ['mode' => 'panel']]),
            $appConfig,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function adminRow(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'linux_username' => 'opsadmin',
            'name' => 'Ops Admin',
            'role' => 'super_admin',
            'tenant_id' => null,
            'password_hash' => (new PasswordHashingService())->hash('CorrectHorse1!'),
            'totp_enabled' => 0,
            'totp_secret' => null,
        ], $overrides);
    }

    /**
     * @return array<int, string>
     */
    private function auditActions(): array
    {
        return array_map(
            static fn (array $entry): string => (string) ($entry['action'] ?? ''),
            $this->auditEntries->getArrayCopy()
        );
    }

    // ------------------------------------------------------------- happy path

    public function test_valid_credentials_establish_an_admin_session(): void
    {
        $service = $this->makeService($this->adminRow());

        $identity = $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');

        $this->assertSame(1, $identity['id']);
        $this->assertSame('super_admin', $identity['role']);
        $this->assertSame('admin', $_SESSION['auth']['guard']);
        $this->assertContains('auth.admin_login', $this->auditActions());
    }

    public function test_login_is_case_insensitive_on_the_username(): void
    {
        $service = $this->makeService($this->adminRow());

        $identity = $service->loginAdmin('  OpsAdmin  ', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');

        $this->assertSame(1, $identity['id']);
    }

    /**
     * A session must never carry the password hash or the TOTP secret; anything in
     * the session is reachable by later code paths and by session storage.
     */
    public function test_session_identity_never_contains_secrets(): void
    {
        $service = $this->makeService($this->adminRow([
            'totp_enabled' => 0,
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
        ]));

        $identity = $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');

        $this->assertArrayNotHasKey('password_hash', $identity);
        $this->assertArrayNotHasKey('totp_secret', $identity);
        $this->assertArrayNotHasKey('password_hash', $_SESSION['auth']['identity']);
        $this->assertArrayNotHasKey('totp_secret', $_SESSION['auth']['identity']);
    }

    // -------------------------------------------------------- failed attempts

    public function test_wrong_password_is_rejected(): void
    {
        $service = $this->makeService($this->adminRow());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials.');

        $service->loginAdmin('opsadmin', 'WrongPassword1!', null, '203.0.113.10', 'phpunit');
    }

    public function test_unknown_user_gives_the_same_message_as_a_wrong_password(): void
    {
        $service = $this->makeService($this->adminRow());

        try {
            $service->loginAdmin('ghost', 'anything', null, '203.0.113.10', 'phpunit');
            $this->fail('Expected the login to be rejected.');
        } catch (InvalidArgumentException $exception) {
            // Distinct messages would let an attacker enumerate valid usernames.
            $this->assertSame('Invalid credentials.', $exception->getMessage());
        }
    }

    public function test_failed_login_does_not_create_a_session(): void
    {
        $service = $this->makeService($this->adminRow());

        try {
            $service->loginAdmin('opsadmin', 'WrongPassword1!', null, '203.0.113.10', 'phpunit');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertArrayNotHasKey('auth', $_SESSION);
    }

    public function test_failed_login_is_recorded_and_rate_limited(): void
    {
        $service = $this->makeService($this->adminRow());

        try {
            $service->loginAdmin('opsadmin', 'WrongPassword1!', null, '203.0.113.10', 'phpunit');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertContains('auth.admin_login.failed', $this->auditActions());
        $this->assertGreaterThan(0, $this->rateLimitHits->count(), 'A failed login must consume a rate limit slot.');
    }

    public function test_rate_limit_blocks_before_credentials_are_checked(): void
    {
        $service = $this->makeService($this->adminRow(), [], true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many requests.');

        // Correct credentials must still be refused while the bucket is exhausted.
        $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');
    }

    // --------------------------------------------------------------- 2FA gate

    public function test_totp_enabled_account_requires_a_code(): void
    {
        $service = $this->makeService($this->adminRow([
            'totp_enabled' => 1,
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
        ]));

        try {
            $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');
            $this->fail('Expected the login to require a one-time password.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('One-time password is required.', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('auth', $_SESSION);
        $this->assertContains('auth.admin_login.totp_required', $this->auditActions());
    }

    public function test_totp_enabled_account_rejects_a_wrong_code(): void
    {
        $service = $this->makeService($this->adminRow([
            'totp_enabled' => 1,
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
        ]));

        try {
            $service->loginAdmin('opsadmin', 'CorrectHorse1!', '000000', '203.0.113.10', 'phpunit');
            $this->fail('Expected the one-time password to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Invalid one-time password.', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('auth', $_SESSION);
        $this->assertContains('auth.admin_login.totp_failed', $this->auditActions());
    }

    public function test_blank_totp_code_counts_as_missing(): void
    {
        $service = $this->makeService($this->adminRow([
            'totp_enabled' => 1,
            'totp_secret' => 'JBSWY3DPEHPK3PXP',
        ]));

        $this->expectExceptionMessage('One-time password is required.');

        $service->loginAdmin('opsadmin', 'CorrectHorse1!', '   ', '203.0.113.10', 'phpunit');
    }

    // ------------------------------------------------------- IP allowlisting

    public function test_super_admin_is_blocked_from_an_ip_outside_the_allowlist(): void
    {
        $service = $this->makeService($this->adminRow(), [
            'super_admin_ip_allowlist_enabled' => true,
            'super_admin_ip_allowlist' => ['198.51.100.0/24'],
        ]);

        try {
            $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');
            $this->fail('Expected the login to be refused for this IP.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Super admin login is not allowed from this IP.', $exception->getMessage());
        }

        $this->assertArrayNotHasKey('auth', $_SESSION);
        $this->assertContains('auth.admin_login.ip_denied', $this->auditActions());
    }

    public function test_super_admin_is_allowed_from_an_ip_inside_the_allowlist(): void
    {
        $service = $this->makeService($this->adminRow(), [
            'super_admin_ip_allowlist_enabled' => true,
            'super_admin_ip_allowlist' => ['198.51.100.0/24'],
        ]);

        $identity = $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '198.51.100.7', 'phpunit');

        $this->assertSame(1, $identity['id']);
    }

    /**
     * The allowlist is a super admin control. A tenant admin logging in from
     * elsewhere must not be caught by it.
     */
    public function test_allowlist_does_not_apply_to_tenant_admins(): void
    {
        $service = $this->makeService(
            $this->adminRow(['role' => 'tenant_admin', 'tenant_id' => 4]),
            [
                'super_admin_ip_allowlist_enabled' => true,
                'super_admin_ip_allowlist' => ['198.51.100.0/24'],
            ]
        );

        $identity = $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');

        $this->assertSame('tenant_admin', $identity['role']);
    }

    public function test_allowlist_is_ignored_when_disabled(): void
    {
        $service = $this->makeService($this->adminRow(), [
            'super_admin_ip_allowlist_enabled' => false,
            'super_admin_ip_allowlist' => ['198.51.100.0/24'],
        ]);

        $identity = $service->loginAdmin('opsadmin', 'CorrectHorse1!', null, '203.0.113.10', 'phpunit');

        $this->assertSame(1, $identity['id']);
    }
}
