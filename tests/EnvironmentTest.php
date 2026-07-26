<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Bootstrap\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    private string $tempDir = '';

    protected function tearDown(): void
    {
        foreach (['MAILPANEL_ENV_TEST_LOADS', 'MAILPANEL_ENV_TEST_EXISTING', 'VALID_ENV_KEY_123'] as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }

        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $envFile = $this->tempDir . '/.env';
            if (is_file($envFile)) {
                unlink($envFile);
            }

            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_loads_missing_environment_values_from_dotenv(): void
    {
        $this->writeDotEnv("MAILPANEL_ENV_TEST_LOADS=from-file\n");

        Environment::load($this->tempDir);

        $this->assertSame('from-file', $_ENV['MAILPANEL_ENV_TEST_LOADS'] ?? null);
        $this->assertSame('from-file', $_SERVER['MAILPANEL_ENV_TEST_LOADS'] ?? null);
        $this->assertSame('from-file', getenv('MAILPANEL_ENV_TEST_LOADS'));
    }

    public function test_existing_process_environment_wins_over_dotenv_file(): void
    {
        $_ENV['MAILPANEL_ENV_TEST_EXISTING'] = 'from-process';
        $_SERVER['MAILPANEL_ENV_TEST_EXISTING'] = 'from-process';
        putenv('MAILPANEL_ENV_TEST_EXISTING=from-process');
        $this->writeDotEnv("MAILPANEL_ENV_TEST_EXISTING=from-file\n");

        Environment::load($this->tempDir);

        $this->assertSame('from-process', $_ENV['MAILPANEL_ENV_TEST_EXISTING'] ?? null);
        $this->assertSame('from-process', $_SERVER['MAILPANEL_ENV_TEST_EXISTING'] ?? null);
        $this->assertSame('from-process', getenv('MAILPANEL_ENV_TEST_EXISTING'));
    }

    public function test_invalid_dotenv_keys_are_ignored(): void
    {
        $this->writeDotEnv("BAD KEY=space\n1BAD=number\nBAD-KEY=dash\nVALID_ENV_KEY_123=ok\n");

        Environment::load($this->tempDir);

        $this->assertSame('ok', $_ENV['VALID_ENV_KEY_123'] ?? null);
        $this->assertArrayNotHasKey('BAD KEY', $_ENV);
        $this->assertArrayNotHasKey('1BAD', $_ENV);
        $this->assertArrayNotHasKey('BAD-KEY', $_ENV);
        $this->assertFalse(getenv('BAD KEY'));
        $this->assertFalse(getenv('1BAD'));
        $this->assertFalse(getenv('BAD-KEY'));
    }

    private function writeDotEnv(string $contents): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mailpanel-env-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir);
        file_put_contents($this->tempDir . '/.env', $contents);
    }
}
