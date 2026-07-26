<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

final class ConfigVersionRepository extends AbstractPdoRepository
{
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM config_versions ORDER BY id DESC');
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM config_versions WHERE id = :id', ['id' => $id]);
    }

    public function latestAppliedByService(string $service): ?array
    {
        return $this->fetchOne(
            'SELECT * FROM config_versions WHERE service = :service AND status = "applied" ORDER BY id DESC LIMIT 1',
            ['service' => $service]
        );
    }

    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO config_versions (service, version, rendered_path, active_path, checksum, status, error_message, created_by, previous_version_id, created_at, updated_at) VALUES (:service, :version, :rendered_path, :active_path, :checksum, :status, :error_message, :created_by, :previous_version_id, NOW(), NOW())',
            $data
        );

        return $this->fetchOne('SELECT * FROM config_versions WHERE id = :id', ['id' => $this->lastInsertId()]) ?? [];
    }

    public function markStatus(int $id, string $status, ?string $errorMessage = null): void
    {
        $this->execute(
            'UPDATE config_versions SET status = :status, error_message = :error_message, applied_at = CASE WHEN :status = "applied" THEN NOW() ELSE applied_at END, updated_at = NOW() WHERE id = :id',
            ['id' => $id, 'status' => $status, 'error_message' => $errorMessage]
        );
    }
}
