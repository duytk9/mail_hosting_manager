<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Repositories\Pdo\ConfigVersionRepository;
use MailPanel\Support\SafePath;

final class ConfigDeploymentService
{
    private readonly string $generatedRoot;

    public function __construct(
        string $generatedRoot,
        private readonly ConfigVersionRepository $versions,
        private readonly NginxConfigRenderer $nginxRenderer,
        private readonly EximConfigRenderer $eximRenderer,
        private readonly DovecotConfigRenderer $dovecotRenderer,
        private readonly RspamdConfigRenderer $rspamdRenderer,
        private readonly Fail2banConfigRenderer $fail2banRenderer,
        private readonly ConfigValidatorService $validator,
        private readonly AgentClientService $agentClient,
        private readonly AuditLogService $auditLog
    ) {
        $this->generatedRoot = $this->safeAbsolutePath($generatedRoot, 'generated root');
    }

    public function generateDrafts(int $actorId = 0): array
    {
        $drafts = [
            $this->nginxRenderer->render(),
            $this->eximRenderer->render(),
            $this->dovecotRenderer->render(),
            $this->rspamdRenderer->render(),
            $this->fail2banRenderer->render(),
        ];
        $created = [];

        foreach ($drafts as $draft) {
            $version = $this->createVersionIdentifier();
            $versionedPaths = $this->versionedPaths($draft, $version);
            $previous = $this->versions->latestAppliedByService($draft['service']);
            $this->writeFile($versionedPaths['rendered_path'], $draft['content']);

            foreach ($versionedPaths['extras'] as $extra) {
                $this->writeFile($extra['path'], $extra['content']);
            }

            $entry = $this->versions->create([
                'service' => $draft['service'],
                'version' => $version,
                'rendered_path' => $versionedPaths['rendered_path'],
                'active_path' => $versionedPaths['active_path'],
                'checksum' => hash('sha256', $draft['content']),
                'status' => 'generated',
                'error_message' => null,
                'created_by' => $actorId,
                'previous_version_id' => $previous['id'] ?? null,
            ]);

            $created[] = $entry;
        }

        $this->auditLog->log([
            'action' => 'config.generated',
            'target_type' => 'config_version',
            'target_id' => null,
            'new_values' => $created,
        ]);

        return $created;
    }

    public function generateValidationPlan(int $actorId = 0): array
    {
        $drafts = $this->generateDrafts($actorId);
        $services = [];
        foreach ($drafts as $draft) {
            $services[$draft['service']] = $draft['rendered_path'] ?? $draft['path'] ?? null;
        }
        $plan = $this->validator->validationPlan($services);

        $this->auditLog->log([
            'actor_id' => $actorId,
            'action' => 'config.validation_plan.generated',
            'target_type' => 'config_version',
            'new_values' => ['services' => $services, 'plan' => $plan],
        ]);

        return [
            'drafts' => $drafts,
            'validation_plan' => $plan,
            'next_step' => 'system_agent_apply',
        ];
    }

    public function listVersions(): array
    {
        return $this->versions->all();
    }

    public function applyVersion(int $versionId, bool $simulate = true): array
    {
        $version = $this->versions->find($versionId);

        if ($version === null) {
            throw new \InvalidArgumentException('Config version not found.');
        }

        $this->assertChecksumMatches($version);

        $previous = !empty($version['previous_version_id']) ? $this->versions->find((int) $version['previous_version_id']) : null;
        $result = $this->agentClient->applyConfig([
            'service' => $version['service'],
            'rendered_path' => $version['rendered_path'],
            'active_path' => $version['active_path'],
            'previous_rendered_path' => $previous['rendered_path'] ?? null,
            'dry_run' => $simulate,
        ]);

        $stage = (string) ($result['stage'] ?? '');
        if ($simulate) {
            $status = $stage === 'validate' ? 'failed' : 'validated';
            $error = $stage === 'validate' ? json_encode($this->redactedAgentResult($result), JSON_UNESCAPED_SLASHES) : null;
            $this->versions->markStatus($versionId, $status, $error);

            if ($stage === 'validate') {
                throw new \RuntimeException('Config validation failed: ' . $this->agentErrorMessage($result));
            }
        } else {
            if ($stage !== 'reload') {
                $this->versions->markStatus($versionId, 'failed', json_encode($this->redactedAgentResult($result), JSON_UNESCAPED_SLASHES));
                throw new \RuntimeException('Config apply failed at stage [' . $stage . ']: ' . $this->agentErrorMessage($result));
            }

            $this->versions->markStatus($versionId, 'applied');
        }

        return ['version' => $this->versions->find($versionId), 'agent' => $result, 'simulate' => $simulate];
    }

    public function rollbackVersion(int $versionId, bool $simulate = true): array
    {
        $version = $this->versions->find($versionId);

        if ($version === null || empty($version['previous_version_id'])) {
            throw new \InvalidArgumentException('Rollback target is unavailable.');
        }

        $previous = $this->versions->find((int) $version['previous_version_id']);

        if ($previous === null) {
            throw new \InvalidArgumentException('Previous version not found.');
        }

        $this->assertChecksumMatches($previous);

        $result = $this->agentClient->applyConfig([
            'service' => $previous['service'],
            'rendered_path' => $previous['rendered_path'],
            'active_path' => $previous['active_path'],
            'previous_rendered_path' => null,
            'dry_run' => $simulate,
        ]);

        if ($simulate) {
            if (($result['stage'] ?? '') === 'validate') {
                throw new \RuntimeException('Rollback validation failed: ' . $this->agentErrorMessage($result));
            }

            return [
                'rolled_back_version' => $versionId,
                'restored_version' => $previous['id'],
                'agent' => $result,
                'simulate' => true,
            ];
        }

        if (($result['stage'] ?? '') === 'reload') {
            $this->versions->markStatus($versionId, 'rolled_back');
            $this->versions->markStatus((int) $previous['id'], 'applied');
        } else {
            throw new \RuntimeException('Rollback apply failed: ' . $this->agentErrorMessage($result));
        }

        return [
            'rolled_back_version' => $versionId,
            'restored_version' => $previous['id'],
            'agent' => $result,
            'simulate' => $simulate,
        ];
    }

    private function writeFile(string $path, string $content): void
    {
        $this->assertGeneratedChildPath($path);
        $directory = dirname($path);

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create generated config directory.');
            }
        }

        $this->assertNoSymlinkPath($directory);
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe generated config file path.');
        }

        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write generated config file.');
        }

        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish generated config file atomically.');
        }
    }

    private function createVersionIdentifier(): string
    {
        return date('YmdHis') . '-' . bin2hex(random_bytes(2));
    }

    private function versionedPaths(array $draft, string $version): array
    {
        $service = (string) ($draft['service'] ?? 'unknown');
        $this->assertSafeServiceName($service);

        $draftRoot = dirname((string) $draft['path']);
        $versionRoot = $this->generatedRoot . '/' . $service . '/' . $version;
        $extras = [];

        foreach ($draft['extras'] ?? [] as $extra) {
            $relativePath = $this->relativeDraftPath($draftRoot, (string) ($extra['path'] ?? ''));
            $extras[] = [
                'path' => $versionRoot . '/' . $relativePath,
                'content' => (string) ($extra['content'] ?? ''),
            ];
        }

        return [
            'rendered_path' => $versionRoot . '/' . basename((string) $draft['path']),
            'active_path' => $this->generatedRoot . '/active/' . $service . '/' . basename((string) $draft['path']),
            'extras' => $extras,
        ];
    }

    private function relativeDraftPath(string $draftRoot, string $path): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $draftRoot), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return basename($path);
    }

    private function assertSafeServiceName(string $service): void
    {
        if (!preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $service)) {
            throw new \InvalidArgumentException('Unsafe config service name.');
        }
    }

    private function assertGeneratedChildPath(string $path): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->generatedRoot), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if (!str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            throw new \RuntimeException('Generated config path is outside generated root.');
        }

        $relative = substr($normalizedPath, strlen($normalizedRoot) + 1);
        $segments = array_values(array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''));

        if ($segments === [] || in_array('..', $segments, true)) {
            throw new \RuntimeException('Unsafe generated config path.');
        }
    }

    private function assertNoSymlinkPath(string $directory): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->generatedRoot), '/');
        $normalizedDirectory = str_replace('\\', '/', $directory);
        $relative = substr($normalizedDirectory, strlen($normalizedRoot));
        $segments = array_values(array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''));

        $current = $this->generatedRoot;
        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \RuntimeException('Unsafe generated config directory path.');
            }
        }
    }

    private function safeAbsolutePath(string $path, string $label): string
    {
        return SafePath::absolute($path, $label);
    }

    private function assertChecksumMatches(array $version): void
    {
        $path = (string) ($version['rendered_path'] ?? '');
        $expected = (string) ($version['checksum'] ?? '');

        if ($path === '' || !is_file($path)) {
            throw new \RuntimeException('Rendered config file is missing.');
        }

        $actual = hash_file('sha256', $path);
        if (!is_string($actual)) {
            throw new \RuntimeException('Unable to checksum rendered config file.');
        }

        if ($expected !== '' && !hash_equals($expected, $actual)) {
            throw new \RuntimeException('Rendered config checksum mismatch.');
        }
    }

    private function agentErrorMessage(array $result): string
    {
        $output = (string) ($result['result']['stderr'] ?? $result['result']['stdout'] ?? 'unknown error');
        $output = trim($this->redactSensitiveString($output));

        return $output !== '' ? $output : 'unknown error';
    }

    private function redactedAgentResult(array $result): array
    {
        $redacted = [];
        foreach ($result as $key => $value) {
            $redacted[$key] = match (true) {
                is_array($value) => $this->redactedAgentResult($value),
                is_string($value) => $this->redactSensitiveString($value),
                default => $value,
            };
        }

        return $redacted;
    }

    private function redactSensitiveString(string $value): string
    {
        $patterns = [
            '/((?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)\s*[=:]\s*)([^\s,"\']+)/i',
            '/("(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)"\s*:\s*")([^"]+)(")/i',
        ];

        $redacted = preg_replace($patterns[0], '$1[redacted]', $value);
        $redacted = is_string($redacted) ? $redacted : $value;
        $redacted = preg_replace($patterns[1], '$1[redacted]$3', $redacted);

        return is_string($redacted) ? $redacted : '[redacted]';
    }
}
