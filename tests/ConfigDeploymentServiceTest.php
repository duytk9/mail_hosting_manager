<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\ConfigDeploymentService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ConfigDeploymentServiceTest extends TestCase
{
    public function test_agent_error_message_redacts_sensitive_values(): void
    {
        $service = (new ReflectionClass(ConfigDeploymentService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ConfigDeploymentService::class, 'agentErrorMessage');

        $message = $method->invoke($service, [
            'result' => [
                'stderr' => 'dovecot failed password=db-pass token=api-token {"private_key":"key-data"}',
            ],
        ]);

        $this->assertSame(
            'dovecot failed password=[redacted] token=[redacted] {"private_key":"[redacted]"}',
            $message
        );
        $this->assertStringNotContainsString('db-pass', $message);
        $this->assertStringNotContainsString('api-token', $message);
        $this->assertStringNotContainsString('key-data', $message);
    }

    public function test_redacted_agent_result_is_recursive(): void
    {
        $service = (new ReflectionClass(ConfigDeploymentService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ConfigDeploymentService::class, 'redactedAgentResult');

        $result = $method->invoke($service, [
            'stage' => 'validate',
            'result' => [
                'stdout' => 'ok',
                'stderr' => 'password=secret',
                'nested' => ['api_secret=hidden'],
            ],
        ]);

        $this->assertSame('password=[redacted]', $result['result']['stderr']);
        $this->assertSame('api_secret=[redacted]', $result['result']['nested'][0]);
    }

    public function test_generated_root_rejects_unsafe_paths(): void
    {
        $service = (new ReflectionClass(ConfigDeploymentService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ConfigDeploymentService::class, 'safeAbsolutePath');

        $this->assertSame('/var/lib/mailpanel/generated', $method->invoke($service, '/var/lib/mailpanel/generated/', 'generated root'));
        $this->assertSame('C:/mailpanel/generated', $method->invoke($service, 'C:\\mailpanel\\generated\\', 'generated root'));

        foreach (['relative/path', "/var/lib/../secret", "/var/lib/mailpanel\nowned", '/', 'C:\\'] as $path) {
            try {
                $method->invoke($service, $path, 'generated root');
                $this->fail('Unsafe generated root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('generated root', $exception->getMessage());
            }
        }
    }

    public function test_config_service_name_rejects_path_like_values(): void
    {
        $service = (new ReflectionClass(ConfigDeploymentService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ConfigDeploymentService::class, 'assertSafeServiceName');

        $method->invoke($service, 'exim4');

        foreach (['../exim4', 'exim/config', 'Exim4', 'exim4;reload'] as $serviceName) {
            try {
                $method->invoke($service, $serviceName);
                $this->fail('Unsafe config service name was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('Unsafe config service name', $exception->getMessage());
            }
        }
    }

    public function test_generated_config_write_guards_reject_paths_outside_root_and_symlink_targets(): void
    {
        $service = (new ReflectionClass(ConfigDeploymentService::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(ConfigDeploymentService::class, 'generatedRoot');
        $property->setValue($service, '/var/lib/mailpanel/generated');

        $pathGuard = new \ReflectionMethod(ConfigDeploymentService::class, 'assertGeneratedChildPath');
        $pathGuard->invoke($service, '/var/lib/mailpanel/generated/exim/version/file.conf');

        foreach ([
            '/var/lib/mailpanel/generated',
            '/var/lib/mailpanel/generated/../outside.conf',
            '/tmp/outside.conf',
        ] as $path) {
            try {
                $pathGuard->invoke($service, $path);
                $this->fail('Unsafe generated config path was accepted.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('generated config path', strtolower($exception->getMessage()));
            }
        }

        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/ConfigDeploymentService.php');
        $this->assertStringContainsString('Unable to create generated config directory.', $source);
        $this->assertStringContainsString('Unsafe generated config directory path.', $source);
        $this->assertStringContainsString('Unsafe generated config file path.', $source);
    }

    public function test_prune_old_versions_preserves_active_revisions_and_limits_cleanup(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/ConfigDeploymentService.php');
        $repository = (string) file_get_contents(dirname(__DIR__) . '/src/Repositories/Pdo/ConfigVersionRepository.php');
        $controller = (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/AdminConfigDeploymentController.php');

        $this->assertStringContainsString('pruneOldVersions', $source);
        $this->assertStringContainsString('$latestAppliedByService', $source);
        $this->assertStringContainsString('!isset($latestAppliedByService[$service])', $source);
        $this->assertStringContainsString('$initialKeepIds = $keepIds', $source);
        $this->assertStringContainsString('previous_version_id', $source);
        $this->assertStringContainsString('deleteVersionArtifacts', $source);
        $this->assertStringContainsString('artifact_errors', $source);
        $this->assertStringContainsString('Too many config versions selected for cleanup.', $repository);
        $this->assertStringContainsString('UPDATE config_versions SET previous_version_id = NULL WHERE previous_version_id IN', $repository);
        $this->assertStringContainsString('clear_old', $controller);
        $this->assertStringContainsString('config_versions.delete', $controller);
    }

    public function test_config_apply_and_rollback_are_audited_with_redacted_agent_result(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/ConfigDeploymentService.php');

        $this->assertStringContainsString('auditConfigAction', $source);
        $this->assertStringContainsString('config.validated', $source);
        $this->assertStringContainsString('config.validation_failed', $source);
        $this->assertStringContainsString('config.applied', $source);
        $this->assertStringContainsString('config.apply_failed', $source);
        $this->assertStringContainsString('config.rolled_back', $source);
        $this->assertStringContainsString('config.rollback_failed', $source);
        $this->assertStringContainsString('$this->redactedAgentResult($agentResult)', $source);
    }
}
