<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class RateLimiterService
{
    /** Roughly 1-in-N rate-limit checks also sweep expired buckets. */
    private const CLEANUP_PROBABILITY = 200;

    private ?PDO $pdo = null;
    private ?string $storagePath = null;

    public function __construct(PDO|string $storage)
    {
        if ($storage instanceof PDO) {
            $this->pdo = $storage;
            return;
        }

        $this->storagePath = $this->normalizeStoragePath($storage);
    }

    public function assertWithinLimit(string $bucket, int $maxAttempts, int $windowSeconds): void
    {
        [$maxAttempts, $windowSeconds] = $this->safeLimits($maxAttempts, $windowSeconds);
        $state = $this->withLockedState($bucket, $windowSeconds, static fn (array $state): array => $state);

        if (($state['attempts'] ?? 0) >= $maxAttempts) {
            $retryAfter = max(1, (int) (($state['expires_at'] ?? time()) - time()));
            throw new RuntimeException("Too many requests. Retry after {$retryAfter} seconds.");
        }
    }

    public function hit(string $bucket, int $maxAttempts, int $windowSeconds): array
    {
        [$maxAttempts, $windowSeconds] = $this->safeLimits($maxAttempts, $windowSeconds);
        $state = $this->withLockedState($bucket, $windowSeconds, static function (array $state) use ($windowSeconds): array {
            $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
            $state['expires_at'] = (int) ($state['expires_at'] ?? (time() + $windowSeconds));
            return $state;
        });

        if ($state['attempts'] > $maxAttempts) {
            $retryAfter = max(1, $state['expires_at'] - time());
            throw new RuntimeException("Too many requests. Retry after {$retryAfter} seconds.");
        }

        return $state;
    }

    public function clear(string $bucket): void
    {
        if ($this->storagePath !== null) {
            $file = $this->storagePath . DIRECTORY_SEPARATOR . hash('sha256', $bucket) . '.json';
            if (is_file($file)) {
                @unlink($file);
            }

            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?');
        $stmt->execute([hash('sha256', $bucket)]);
    }

    private function withLockedState(string $bucket, int $windowSeconds, callable $mutate): array
    {
        return $this->storagePath !== null
            ? $this->withLockedFileState($bucket, $windowSeconds, $mutate)
            : $this->withLockedDatabaseState($bucket, $windowSeconds, $mutate);
    }

    private function withLockedDatabaseState(string $bucket, int $windowSeconds, callable $mutate): array
    {
        $hash = hash('sha256', $bucket);
        if (!$this->pdo instanceof PDO) {
            throw new RuntimeException('Rate limiter database is not configured.');
        }
        
        // Never nest transactions: PDO::beginTransaction() throws when one is already
        // active, and committing here would silently commit the caller's work.
        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            // Sweep expired buckets occasionally instead of on every single request:
            // running a range DELETE inside every rate-limit transaction causes
            // needless gap locking and deadlocks against the SELECT ... FOR UPDATE below.
            if (random_int(1, self::CLEANUP_PROBABILITY) === 1) {
                $cleanup = $this->pdo->prepare('DELETE FROM rate_limits WHERE expires_at <= ?');
                $cleanup->execute([time()]);
            }

            $stmt = $this->pdo->prepare('SELECT attempts, expires_at FROM rate_limits WHERE bucket = ? FOR UPDATE');
            $stmt->execute([$hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row && $row['expires_at'] > time()) {
                $state = [
                    'attempts' => (int) $row['attempts'],
                    'expires_at' => (int) $row['expires_at'],
                ];
            } else {
                $state = [
                    'attempts' => 0,
                    'expires_at' => time() + $windowSeconds,
                ];
            }

            $state = $mutate($state);

            $stmt = $this->pdo->prepare('
                INSERT INTO rate_limits (bucket, attempts, expires_at) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), expires_at = VALUES(expires_at)
            ');
            $stmt->execute([$hash, $state['attempts'], $state['expires_at']]);

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return $state;
        } catch (\Throwable $e) {
            // rollBack() on an already-closed transaction throws and would mask $e.
            if ($startedTransaction && $this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (\Throwable) {
                    // Ignore: the original failure below is the meaningful one.
                }
            }

            throw new RuntimeException('Rate limiter failure: ' . $e->getMessage(), 0, $e);
        }
    }

    private function withLockedFileState(string $bucket, int $windowSeconds, callable $mutate): array
    {
        $directory = $this->storagePath;
        if ($directory === null) {
            throw new RuntimeException('Rate limit storage is not configured.');
        }

        $this->ensureStorageDirectory($directory);
        $hash = hash('sha256', $bucket);
        $file = $directory . DIRECTORY_SEPARATOR . $hash . '.json';
        $lockFile = $directory . DIRECTORY_SEPARATOR . $hash . '.lock';
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to create rate limit storage.');
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('Unable to lock rate limit storage.');
            }

            $row = null;
            if (is_file($file)) {
                $decoded = json_decode((string) file_get_contents($file), true);
                $row = is_array($decoded) ? $decoded : null;
            }

            if ($row && (int) ($row['expires_at'] ?? 0) > time()) {
                $state = [
                    'attempts' => (int) ($row['attempts'] ?? 0),
                    'expires_at' => (int) ($row['expires_at'] ?? 0),
                ];
            } else {
                $state = [
                    'attempts' => 0,
                    'expires_at' => time() + $windowSeconds,
                ];
            }

            $state = $mutate($state);
            $temporaryFile = $file . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($temporaryFile, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('Unable to write rate limit storage.');
            }
            @chmod($temporaryFile, 0600);
            if (!@rename($temporaryFile, $file)) {
                @unlink($temporaryFile);
                throw new RuntimeException('Unable to update rate limit storage.');
            }

            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function normalizeStoragePath(string $storagePath): string
    {
        $storagePath = trim($storagePath);
        if ($storagePath === '' || str_contains($storagePath, "\0") || preg_match('/(?:^|[\\\\\\/])\\.\\.(?:[\\\\\\/]|$)/', $storagePath)) {
            throw new InvalidArgumentException('Unsafe rate limit storage path.');
        }

        if (!$this->isAbsolutePath($storagePath)) {
            throw new InvalidArgumentException('Unsafe rate limit storage path.');
        }

        return rtrim($storagePath, "\\/");
    }

    private function ensureStorageDirectory(string $directory): void
    {
        $this->assertNoSymlinkPath($directory);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create rate limit storage.');
        }

        $this->assertNoSymlinkPath($directory);
    }

    private function assertNoSymlinkPath(string $directory): void
    {
        $path = $directory;
        $segments = [];
        while ($path !== '' && $path !== dirname($path)) {
            $segments[] = $path;
            $path = dirname($path);
        }

        foreach (array_reverse($segments) as $path) {
            if (is_link($path)) {
                throw new InvalidArgumentException('Unsafe rate limit storage path.');
            }
        }
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function safeLimits(int $maxAttempts, int $windowSeconds): array
    {
        return [
            max(1, min($maxAttempts, 100000)),
            max(1, min($windowSeconds, 86400)),
        ];
    }
}
