<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

use MailPanel\Core\Database;
use PDO;

abstract class AbstractPdoRepository
{
    public function __construct(protected readonly Database $database)
    {
    }

    protected function pdo(): PDO
    {
        return $this->database->connection();
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row ?: null;
    }

    protected function execute(string $sql, array $params = []): bool
    {
        $statement = $this->pdo()->prepare($sql);

        return $statement->execute($params);
    }

    protected function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }
}
