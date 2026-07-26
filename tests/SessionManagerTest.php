<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Security\SessionManager;
use PHPUnit\Framework\TestCase;

final class SessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_clear_removes_auth_csrf_flash_and_password_proof(): void
    {
        $sessions = new SessionManager();
        $_SESSION = [
            'auth' => [
                'guard' => 'admin',
                'identity' => ['id' => 7, 'role' => 'super_admin'],
                'admin_password_proof' => ['user_id' => 7],
                'last_activity_at' => time(),
            ],
            '_csrf' => 'csrf-token',
            '_flash' => ['success' => 'ok'],
        ];

        $sessions->clear();

        $this->assertSame([], $_SESSION);
        $this->assertNull($sessions->identity());
        $this->assertFalse($sessions->verifyCsrf('csrf-token'));
        $this->assertNull($sessions->adminPasswordProof());
    }

    public function test_expired_session_is_cleared_completely(): void
    {
        $sessions = new SessionManager(1);
        $_SESSION = [
            'auth' => [
                'guard' => 'admin',
                'identity' => ['id' => 7, 'role' => 'super_admin'],
                'last_activity_at' => time() - 10,
            ],
            '_csrf' => 'old-csrf-token',
            '_flash' => ['warning' => 'old'],
        ];

        $this->assertNull($sessions->identity());
        $this->assertSame([], $_SESSION);
        $this->assertFalse($sessions->verifyCsrf('old-csrf-token'));
    }

    public function test_clear_rotates_csrf_token_on_next_use(): void
    {
        $sessions = new SessionManager();
        $oldToken = $sessions->csrfToken();

        $sessions->clear();
        $newToken = $sessions->csrfToken();

        $this->assertNotSame($oldToken, $newToken);
        $this->assertFalse($sessions->verifyCsrf($oldToken));
        $this->assertTrue($sessions->verifyCsrf($newToken));
    }

    public function test_clear_uses_samesite_cookie_deletion_options(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Security/SessionManager.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("'samesite' =>", $source);
        $this->assertStringContainsString("'httponly' =>", $source);
        $this->assertStringContainsString("'secure' =>", $source);
        $this->assertStringContainsString("'expires' => time() - 42000", $source);
    }
}
