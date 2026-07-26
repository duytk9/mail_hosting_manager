<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class SessionCookieConfigTest extends TestCase
{
    /** @var array<string, string> */
    private array $previousEnv = [];

    protected function tearDown(): void
    {
        foreach ([
            'APP_ENV',
            'APP_URL',
            'APP_KEY',
            'APP_DEBUG',
            'SESSION_NAME',
            'SESSION_TIMEOUT_SECONDS',
            'SESSION_COOKIE_SECURE',
            'SESSION_COOKIE_SAMESITE',
            'SESSION_COOKIE_PATH',
            'SESSION_COOKIE_DOMAIN',
        ] as $key) {
            if (array_key_exists($key, $this->previousEnv)) {
                $_ENV[$key] = $this->previousEnv[$key];
            } else {
                unset($_ENV[$key]);
            }
        }

        parent::tearDown();
    }

    public function test_production_defaults_session_cookie_to_secure_even_without_https_app_url(): void
    {
        $this->withEnv([
            'APP_ENV' => 'production',
            'APP_URL' => 'http://panel.example.test',
            'APP_KEY' => $this->strongAppKey(),
        ]);

        $config = $this->loadConfig();

        $this->assertTrue($config['session']['cookie_secure']);
        $this->assertSame('Strict', $config['session']['cookie_same_site']);
        $this->assertTrue($config['session']['cookie_http_only']);
    }

    public function test_production_defaults_debug_to_false(): void
    {
        $this->withEnv([
            'APP_ENV' => 'production',
            'APP_KEY' => $this->strongAppKey(),
        ]);

        $config = $this->loadConfig();

        $this->assertFalse($config['debug']);
    }

    public function test_production_environment_is_case_insensitive_for_secure_defaults(): void
    {
        $this->withEnv([
            'APP_ENV' => 'Production',
            'APP_URL' => 'http://panel.example.test',
            'APP_KEY' => $this->strongAppKey(),
        ]);

        $config = $this->loadConfig();

        $this->assertSame('production', $config['env']);
        $this->assertTrue($config['session']['cookie_secure']);
        $this->assertFalse($config['debug']);
    }

    public function test_local_defaults_debug_to_true(): void
    {
        $this->withEnv([
            'APP_ENV' => 'local',
        ]);

        $config = $this->loadConfig();

        $this->assertTrue($config['debug']);
    }

    public function test_samesite_none_forces_secure_cookie(): void
    {
        $this->withEnv([
            'APP_ENV' => 'local',
            'APP_URL' => 'http://127.0.0.1:8080',
            'SESSION_COOKIE_SECURE' => 'false',
            'SESSION_COOKIE_SAMESITE' => 'None',
        ]);

        $config = $this->loadConfig();

        $this->assertTrue($config['session']['cookie_secure']);
        $this->assertSame('None', $config['session']['cookie_same_site']);
    }

    public function test_invalid_samesite_value_falls_back_to_strict(): void
    {
        $this->withEnv([
            'SESSION_COOKIE_SAMESITE' => 'Invalid',
        ]);

        $config = $this->loadConfig();

        $this->assertSame('Strict', $config['session']['cookie_same_site']);
    }

    public function test_session_env_values_are_sanitized_and_clamped(): void
    {
        $this->withEnv([
            'SESSION_NAME' => "bad name\r\nSet-Cookie: owned=1",
            'SESSION_TIMEOUT_SECONDS' => '999999999',
            'SESSION_COOKIE_PATH' => '/admin; Secure',
            'SESSION_COOKIE_DOMAIN' => "panel.example.test\r\nSet-Cookie: owned=1",
        ]);

        $config = $this->loadConfig();

        $this->assertSame('mailpanel_session', $config['session_name']);
        $this->assertSame(86400, $config['session']['timeout_seconds']);
        $this->assertSame('/', $config['session']['cookie_path']);
        $this->assertSame('', $config['session']['cookie_domain']);
    }

    public function test_session_timeout_has_safe_minimum(): void
    {
        $this->withEnv([
            'SESSION_TIMEOUT_SECONDS' => '-1',
        ]);

        $config = $this->loadConfig();

        $this->assertSame(60, $config['session']['timeout_seconds']);
    }

    /**
     * @param array<string, string> $values
     */
    private function withEnv(array $values): void
    {
        foreach ([
            'APP_ENV',
            'APP_URL',
            'APP_KEY',
            'APP_DEBUG',
            'SESSION_NAME',
            'SESSION_TIMEOUT_SECONDS',
            'SESSION_COOKIE_SECURE',
            'SESSION_COOKIE_SAMESITE',
            'SESSION_COOKIE_PATH',
            'SESSION_COOKIE_DOMAIN',
        ] as $key) {
            if (!array_key_exists($key, $this->previousEnv) && isset($_ENV[$key])) {
                $this->previousEnv[$key] = (string) $_ENV[$key];
            }

            unset($_ENV[$key]);
        }

        foreach ($values as $key => $value) {
            $_ENV[$key] = $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        return require dirname(__DIR__) . '/config/app.php';
    }

    private function strongAppKey(): string
    {
        return str_repeat('s', 32);
    }
}
