<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Repositories\Pdo\ConfigVersionRepository;
use MailPanel\Support\SafePath;

final class ConfigDeploymentService
{
    private const GENERATED_CONFIG_GROUP = 'mailpanel-agent';

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

    /**
     * @return array{deleted_rows: int, deleted_artifacts: int, artifact_errors: int, kept_ids: array<int, int>, deleted_ids: array<int, int>}
     */
    public function pruneOldVersions(int $keepPerService = 10, bool $deleteArtifacts = true, int $actorId = 0): array
    {
        $keepPerService = max(1, min(100, $keepPerService));
        $versions = $this->versions->all();
        $keptByService = [];
        $latestAppliedByService = [];
        $keepIds = [];

        foreach ($versions as $version) {
            $id = (int) ($version['id'] ?? 0);
            $service = (string) ($version['service'] ?? '');
            if ($id <= 0 || $service === '') {
                continue;
            }

            $serviceCount = $keptByService[$service] ?? 0;
            if ($serviceCount < $keepPerService) {
                $keepIds[$id] = $id;
                $keptByService[$service] = $serviceCount + 1;
            }

            if ((string) ($version['status'] ?? '') === 'applied' && !isset($latestAppliedByService[$service])) {
                $keepIds[$id] = $id;
                $latestAppliedByService[$service] = $id;
            }
        }

        $initialKeepIds = $keepIds;
        foreach ($versions as $version) {
            $id = (int) ($version['id'] ?? 0);
            if ($id <= 0 || !isset($initialKeepIds[$id])) {
                continue;
            }

            $previousId = (int) ($version['previous_version_id'] ?? 0);
            if ($previousId > 0) {
                $keepIds[$previousId] = $previousId;
            }
        }

        $deleteIds = [];
        $deleteRows = [];
        foreach ($versions as $version) {
            $id = (int) ($version['id'] ?? 0);
            if ($id <= 0 || isset($keepIds[$id])) {
                continue;
            }

            $deleteIds[] = $id;
            $deleteRows[] = $version;
        }

        $deletedArtifacts = 0;
        $artifactErrors = 0;
        if ($deleteArtifacts) {
            foreach ($deleteRows as $version) {
                try {
                    if ($this->deleteVersionArtifacts($version)) {
                        $deletedArtifacts++;
                    }
                } catch (\Throwable) {
                    $artifactErrors++;
                }
            }
        }

        $deletedRows = $this->versions->deleteByIds($deleteIds);

        $result = [
            'deleted_rows' => $deletedRows,
            'deleted_artifacts' => $deletedArtifacts,
            'artifact_errors' => $artifactErrors,
            'kept_ids' => array_values($keepIds),
            'deleted_ids' => $deleteIds,
        ];

        $this->auditLog->log([
            'actor_id' => $actorId,
            'action' => 'config_versions.pruned',
            'target_type' => 'config_version',
            'new_values' => [
                'keep_per_service' => $keepPerService,
                'delete_artifacts' => $deleteArtifacts,
                'deleted_rows' => $deletedRows,
                'deleted_artifacts' => $deletedArtifacts,
                'artifact_errors' => $artifactErrors,
                'deleted_ids' => $deleteIds,
            ],
        ]);

        return $result;
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
                $this->auditConfigAction('config.validation_failed', $version, $result, true);
                throw new \RuntimeException('Config validation failed: ' . $this->agentErrorMessage($result));
            }

            $this->auditConfigAction('config.validated', $version, $result, true);
        } else {
            if ($stage !== 'reload') {
                $this->versions->markStatus($versionId, 'failed', json_encode($this->redactedAgentResult($result), JSON_UNESCAPED_SLASHES));
                $this->auditConfigAction('config.apply_failed', $version, $result, false);
                throw new \RuntimeException('Config apply failed at stage [' . $stage . ']: ' . $this->agentErrorMessage($result));
            }

            $this->versions->markStatus($versionId, 'applied');
            $this->auditConfigAction('config.applied', $version, $result, false);
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
                $this->auditConfigAction('config.rollback_validation_failed', $previous, $result, true, $versionId);
                throw new \RuntimeException('Rollback validation failed: ' . $this->agentErrorMessage($result));
            }

            $this->auditConfigAction('config.rollback_validated', $previous, $result, true, $versionId);

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
            $this->auditConfigAction('config.rolled_back', $previous, $result, false, $versionId);
        } else {
            $this->auditConfigAction('config.rollback_failed', $previous, $result, false, $versionId);
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
            if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Unable to create generated config directory.');
            }
        }

        $this->assertNoSymlinkPath($directory);
        $this->hardenGeneratedDirectoryPermissions($directory);
        if (is_link($path)) {
            throw new \RuntimeException('Unsafe generated config file path.');
        }

        $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($temporaryPath, $content, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write generated config file.');
        }

        @chgrp($temporaryPath, self::GENERATED_CONFIG_GROUP);
        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish generated config file atomically.');
        }
    }

    private function hardenGeneratedDirectoryPermissions(string $directory): void
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->generatedRoot), '/');
        $normalizedDirectory = rtrim(str_replace('\\', '/', $directory), '/');

        if (!str_starts_with($normalizedDirectory, $normalizedRoot . '/') && $normalizedDirectory !== $normalizedRoot) {
            throw new \RuntimeException('Generated config directory is outside generated root.');
        }

        $relative = trim(substr($normalizedDirectory, strlen($normalizedRoot)), '/');
        $current = $this->generatedRoot;
        @chgrp($current, self::GENERATED_CONFIG_GROUP);
        @chmod($current, 02750);

        if ($relative === '') {
            return;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '..') {
                throw new \RuntimeException('Unsafe generated config directory path.');
            }

            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_dir($current) && !is_link($current)) {
                @chgrp($current, self::GENERATED_CONFIG_GROUP);
                @chmod($current, 02750);
            }
        }
    }

    private function auditConfigAction(string $action, array $version, array $agentResult, bool $simulate, ?int $rolledBackVersionId = null): void
    {
        $values = [
            'service' => $version['service'] ?? null,
            'version_id' => $version['id'] ?? null,
            'version' => $version['version'] ?? null,
            'simulate' => $simulate,
            'stage' => $agentResult['stage'] ?? null,
            'agent' => $this->redactedAgentResult($agentResult),
        ];

        if ($rolledBackVersionId !== null) {
            $values['rolled_back_version_id'] = $rolledBackVersionId;
        }

        $this->auditLog->log([
            'actor_id' => $version['created_by'] ?? null,
            'actor_role' => 'system',
            'action' => $action,
            'target_type' => 'config_version',
            'target_id' => $version['id'] ?? null,
            'new_values' => $values,
        ]);
    }

    private function deleteVersionArtifacts(array $version): bool
    {
        $renderedPath = (string) ($version['rendered_path'] ?? '');
        if ($renderedPath === '') {
            return false;
        }

        $versionDirectory = dirname($renderedPath);
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->generatedRoot), '/');
        $normalizedDirectory = rtrim(str_replace('\\', '/', $versionDirectory), '/');

        if (
            $normalizedDirectory === $normalizedRoot
            || str_starts_with($normalizedDirectory, $normalizedRoot . '/active')
            || !str_starts_with($normalizedDirectory, $normalizedRoot . '/')
        ) {
            throw new \RuntimeException('Unsafe generated config cleanup path.');
        }

        $relative = substr($normalizedDirectory, strlen($normalizedRoot) + 1);
        $segments = array_values(array_filter(explode('/', $relative), static fn (string $segment): bool => $segment !== ''));
        if (count($segments) < 2 || in_array('..', $segments, true)) {
            throw new \RuntimeException('Unsafe generated config cleanup path.');
        }

        $this->assertGeneratedChildPath($versionDirectory . DIRECTORY_SEPARATOR . '.cleanup-marker');
        $this->assertNoSymlinkPath($versionDirectory);

        if (!is_dir($versionDirectory)) {
            return false;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($versionDirectory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                if (!@rmdir($path) && is_dir($path)) {
                    throw new \RuntimeException('Unable to remove generated config directory.');
                }
                continue;
            }

            if (!@unlink($path) && file_exists($path)) {
                throw new \RuntimeException('Unable to remove generated config file.');
            }
        }

        if (!@rmdir($versionDirectory) && is_dir($versionDirectory)) {
            throw new \RuntimeException('Unable to remove generated config version directory.');
        }

        return true;
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
