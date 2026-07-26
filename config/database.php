<?php

declare(strict_types=1);

return [
    'driver' => $_ENV['DB_DRIVER'] ?? 'mysql',
    'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
    'port' => (int) ($_ENV['DB_PORT'] ?? 3306),
    'database' => $_ENV['DB_DATABASE'] ?? 'mailpanel',
    'username' => $_ENV['DB_USERNAME'] ?? 'mailpanel',
    'password' => $_ENV['DB_PASSWORD'] ?? '',
    'charset' => 'utf8mb4',
    'sqlite_path' => $_ENV['DB_SQLITE_PATH'] ?? dirname(__DIR__) . '/storage/database.sqlite',
];
