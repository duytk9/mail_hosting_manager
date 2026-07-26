<?php

declare(strict_types=1);

namespace MailPanel\Services;

use PDO;
use RuntimeException;

// Not final: tests substitute an in-memory limiter. The enforcement logic lives
// here, but callers already treat a thrown RuntimeException as the contract.
class RateLimiterService
{
    public function __construct(private readonly PDO $pdo)
    {
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
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE bucket = ?');
        $stmt->execute([hash('sha256', $bucket)]);
    }

    private function withLockedState(string $bucket, int $windowSeconds, callable $mutate): array
    {
        $hash = hash('sha256', $bucket);
        
        $this->pdo->beginTransaction();
        try {
            // Clean up expired buckets generically
            $this->pdo->exec('DELETE FROM rate_limits WHERE expires_at <= ' . time());

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

            $this->pdo->commit();
            return $state;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('Rate limiter failure: ' . $e->getMessage(), 0, $e);
        }
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
