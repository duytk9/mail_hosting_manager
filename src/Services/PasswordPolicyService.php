<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;

final class PasswordPolicyService
{
    public function __construct(private readonly array $config = [])
    {
    }

    public function assertStrong(string $password): void
    {
        $minLength = (int) ($this->config['min_length'] ?? 12);

        if (mb_strlen($password) < $minLength) {
            throw new InvalidArgumentException("Password must be at least {$minLength} characters.");
        }

        if (($this->config['prevent_whitespace'] ?? true) && preg_match('/\s/', $password)) {
            throw new InvalidArgumentException('Password cannot contain whitespace.');
        }

        if (($this->config['require_uppercase'] ?? true) && !preg_match('/[A-Z]/', $password)) {
            throw new InvalidArgumentException('Password must include at least one uppercase letter.');
        }

        if (($this->config['require_lowercase'] ?? true) && !preg_match('/[a-z]/', $password)) {
            throw new InvalidArgumentException('Password must include at least one lowercase letter.');
        }

        if (($this->config['require_number'] ?? true) && !preg_match('/\d/', $password)) {
            throw new InvalidArgumentException('Password must include at least one number.');
        }

        if (($this->config['require_symbol'] ?? true) && !preg_match('/[^A-Za-z0-9]/', $password)) {
            throw new InvalidArgumentException('Password must include at least one symbol.');
        }
    }

    public function assertNotReused(string $password, array $historyHashes): void
    {
        if (!$this->historyEnabled()) {
            return;
        }

        foreach (array_slice($historyHashes, 0, $this->historyCount()) as $hash) {
            if (is_string($hash) && $hash !== '' && password_verify($password, $this->stripSchemePrefix($hash))) {
                throw new InvalidArgumentException('Password was used recently. Choose a new password.');
            }
        }
    }

    public function historyEnabled(): bool
    {
        return (bool) ($this->config['enforce_history'] ?? false);
    }

    public function historyCount(): int
    {
        return max(0, (int) ($this->config['history_count'] ?? 0));
    }

    private function stripSchemePrefix(string $hash): string
    {
        return (string) preg_replace('/^\{[A-Z0-9-]+\}/i', '', $hash, 1);
    }
}
