<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Response;
use MailPanel\Http\Controllers\Traits\AdminWebLayoutTrait;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\SessionManager;
use MailPanel\Support\View;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AdminWebGuardSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];

        parent::tearDown();
    }

    public function test_admin_session_guard_redirects_guest_to_login(): void
    {
        $response = $this->guard()->check('/admin/queue');

        $this->assertRedirect($response, '/admin/login');
    }

    public function test_admin_session_guard_forces_password_rotation_before_sensitive_pages(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => [
                'id' => 10,
                'role' => 'super_admin',
                'force_password_change' => 1,
            ],
            'last_activity_at' => time(),
        ];

        $response = $this->guard()->check('/admin/queue');

        $this->assertRedirect($response, '/admin/security');
    }

    public function test_admin_session_guard_allows_authenticated_admin_without_rotation(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => [
                'id' => 10,
                'role' => 'super_admin',
                'force_password_change' => 0,
            ],
            'last_activity_at' => time(),
        ];

        $this->assertNull($this->guard()->check('/admin/queue'));
    }

    public function test_auxiliary_admin_controllers_call_shared_session_guard(): void
    {
        $this->assertControllerUsesGuard('src/Http/Controllers/MonitorController.php', [
            "guardAdminSession('/admin/queue')",
            "guardAdminSession('/admin/logs')",
        ]);
        $this->assertControllerUsesGuard('src/Http/Controllers/SecuritySystemController.php', [
            "guardAdminSession('/admin/webmail')",
            "guardAdminSession('/admin/fail2ban')",
            "guardAdminSession('/admin/rspamd')",
        ]);
        $this->assertControllerUsesGuard('src/Http/Controllers/SpamPolicyController.php', [
            "guardAdminSession('/admin/spam-policies')",
            'TenantLifecyclePolicy::canUseMail',
        ]);
    }

    private function guard(): object
    {
        return new class {
            use AdminWebLayoutTrait;

            private SessionManager $sessions;

            public function __construct()
            {
                $this->sessions = new SessionManager();
            }

            public function check(string $path): ?Response
            {
                return $this->guardAdminSession($path);
            }

            protected function view(): View
            {
                return new View(dirname(__DIR__) . '/src/Views');
            }

            protected function sessions(): SessionManager
            {
                return $this->sessions;
            }

            protected function authorization(): AuthorizationService
            {
                return new AuthorizationService();
            }
        };
    }

    private function assertRedirect(?Response $response, string $location): void
    {
        $this->assertInstanceOf(Response::class, $response);

        $reflection = new ReflectionClass($response);
        $headers = $reflection->getProperty('headers')->getValue($response);
        $status = $reflection->getProperty('status')->getValue($response);

        $this->assertSame(302, $status);
        $this->assertSame($location, $headers['Location'] ?? null);
    }

    /**
     * @param array<int, string> $needles
     */
    private function assertControllerUsesGuard(string $path, array $needles): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/' . $path);
        $this->assertIsString($source);

        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $source);
        }
    }
}
