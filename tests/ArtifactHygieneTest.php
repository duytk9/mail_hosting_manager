<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class ArtifactHygieneTest extends TestCase
{
    public function test_generated_cache_and_bytecode_patterns_are_ignored(): void
    {
        $gitignore = file_get_contents(dirname(__DIR__) . '/.gitignore');
        $this->assertIsString($gitignore);

        foreach ([
            '/.phpunit.result.cache',
            '__pycache__/',
            '/TEMP-CODEX-UPLOAD/',
            '/tmp-sync/',
            '/force_deploy.php',
            '/_apply_exim_version.php',
            '/_server_phase12.sh',
            '*.py[cod]',
            '*.bak',
            '*.tmp',
            '*.log',
            '*.sqlite',
        ] as $pattern) {
            $this->assertStringContainsString($pattern, $gitignore);
        }
    }

    public function test_removed_one_off_deploy_scripts_are_absent(): void
    {
        foreach ([
            'force_deploy.php',
            '_apply_exim_version.php',
            '_server_phase12.sh',
        ] as $path) {
            $this->assertFileDoesNotExist(dirname(__DIR__) . '/' . $path);
        }
    }

    public function test_admin_account_reset_does_not_require_password_argument(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/admin_account.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('--password-stdin', $source);
        $this->assertStringContainsString('--password-file', $source);
        $this->assertStringContainsString('--password-env', $source);
        $this->assertStringContainsString('MAILPANEL_ALLOW_INSECURE_ARG_PASSWORD', $source);
        $this->assertStringContainsString('Refusing --password', $source);
        $this->assertStringNotContainsString("--password='<new-strong-password>'", $source);
        $this->assertStringNotContainsString("--password='<temporary-strong-password>'", $source);
    }
}
