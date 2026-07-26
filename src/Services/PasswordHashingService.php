<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;

final class PasswordHashingService
{
    private const BCRYPT_SCHEME = 'BLF-CRYPT';
    private const ARGON2ID_SCHEME = 'ARGON2ID';

    public function __construct(private readonly string $algorithm = 'bcrypt')
    {
    }

    public function hash(string $password): string
    {
        $hash = match ($this->algorithm) {
            'argon2id' => $this->hashWithArgon2id($password),
            'bcrypt' => password_hash($password, PASSWORD_BCRYPT),
            default => throw new InvalidArgumentException('Unsupported password algorithm.'),
        };

        return $this->prefixForDovecot($hash);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $this->stripSchemePrefix($hash));
    }

    private function hashWithArgon2id(string $password): string
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            return password_hash($password, PASSWORD_BCRYPT);
        }

        return password_hash($password, PASSWORD_ARGON2ID);
    }

    private function prefixForDovecot(string $hash): string
    {
        if (preg_match('/^\{[A-Z0-9-]+\}/i', $hash) === 1) {
            return $hash;
        }

        return match (true) {
            str_starts_with($hash, '$argon2id$') => '{' . self::ARGON2ID_SCHEME . '}' . $hash,
            str_starts_with($hash, '$2y$'),
            str_starts_with($hash, '$2b$'),
            str_starts_with($hash, '$2a$') => '{' . self::BCRYPT_SCHEME . '}' . $hash,
            default => $hash,
        };
    }

    private function stripSchemePrefix(string $hash): string
    {
        return (string) preg_replace('/^\{[A-Z0-9-]+\}/i', '', $hash, 1);
    }
}
