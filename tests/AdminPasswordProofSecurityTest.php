<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AdminPasswordProofSecurityTest extends TestCase
{
    public function test_recent_admin_password_proof_is_hmac_not_password_hash(): void
    {
        $authService = (string) file_get_contents(__DIR__ . '/../src/Services/AuthService.php');
        $adminSecurityService = (string) file_get_contents(__DIR__ . '/../src/Services/AdminSecurityService.php');

        $this->assertStringContainsString("'proof' => \$passwordProof", $authService);
        $this->assertStringContainsString("'proof' => \$this->passwordProof(\$userId, \$password)", $adminSecurityService);
        $this->assertStringContainsString('hash_hmac(\'sha256\'', $authService);
        $this->assertStringContainsString('hash_hmac(\'sha256\'', $adminSecurityService);
        $this->assertStringContainsString('hash_equals($storedProof, $this->passwordProof($userId, $password))', $adminSecurityService);

        $this->assertStringNotContainsString("'hash' => \$this->passwordHasher->hash(\$password)", $authService);
        $this->assertStringNotContainsString("'hash' => \$this->passwordHasher->hash(\$password)", $adminSecurityService);
        $this->assertStringNotContainsString('$this->passwordHasher->verify($password, $proofHash)', $adminSecurityService);
    }

    public function test_application_factory_passes_app_key_to_sensitive_action_proof(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../config/app.php');
        $factory = (string) file_get_contents(__DIR__ . '/../src/Bootstrap/ApplicationFactory.php');

        $this->assertStringContainsString("'key' => \$appKey", $config);
        $this->assertStringContainsString('APP_KEY must be configured with at least 32 characters in production.', $config);
        $this->assertStringContainsString("(string) \$config->get('app.key', '')", $factory);
        $this->assertStringNotContainsString("\$this->appConfig['totp']['encryption_key']", $authService = (string) file_get_contents(__DIR__ . '/../src/Services/AuthService.php'));
    }

    public function test_production_config_rejects_missing_or_weak_app_key(): void
    {
        $originalEnv = $_ENV;
        try {
            $_ENV['APP_ENV'] = 'production';
            $_ENV['APP_KEY'] = 'too-short';

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('APP_KEY must be configured with at least 32 characters in production.');
            require __DIR__ . '/../config/app.php';
        } finally {
            $_ENV = $originalEnv;
        }
    }

    public function test_production_config_accepts_strong_app_key(): void
    {
        $originalEnv = $_ENV;
        try {
            $_ENV['APP_ENV'] = 'production';
            $_ENV['APP_KEY'] = str_repeat('a', 32);
            $_ENV['APP_URL'] = 'https://panel.example.test';

            $config = require __DIR__ . '/../config/app.php';

            $this->assertSame(str_repeat('a', 32), $config['key']);
            $this->assertTrue($config['session']['cookie_secure']);
        } finally {
            $_ENV = $originalEnv;
        }
    }
}
