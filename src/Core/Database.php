<?php

declare(strict_types=1);

namespace MailPanel\Core;

use PDO;

final class Database
{
    private ?PDO $connection = null;

    public function __construct(private readonly array $config)
    {
    }

    public function connection(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        if (($this->config['driver'] ?? 'mysql') === 'sqlite') {
            $dsn = 'sqlite:' . $this->config['sqlite_path'];
            $pdo = new PDO($dsn);
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            );
            $pdo = new PDO($dsn, $this->config['username'], $this->config['password']);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $this->connection = $pdo;
    }
}
