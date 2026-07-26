<?php

declare(strict_types=1);

namespace MailPanel\Security;

use MailPanel\Core\Request;
use MailPanel\Repositories\Pdo\ApiTokenRepository;

final class TokenGuard
{
    public function __construct(private readonly ApiTokenRepository $tokens)
    {
    }

    public function resolve(Request $request): ?array
    {
        $header = $request->header('Authorization', '') ?? '';

        $plainToken = self::bearerToken($header);
        if ($plainToken === null) {
            return null;
        }

        $token = $this->tokens->findByHash(hash('sha256', $plainToken));

        if ($token === null) {
            return null;
        }

        if (isset($token['expires_at']) && strtotime($token['expires_at']) < time()) {
            return null;
        }

        if (isset($token['id'])) {
            $this->tokens->touch((int) $token['id']);
        }

        return $token;
    }

    public static function hasBearerCredential(?string $header): bool
    {
        return preg_match('/\ABearer(?:[ \t]+|\z)/i', trim((string) $header)) === 1;
    }

    private static function bearerToken(string $header): ?string
    {
        $header = trim($header);
        if (!preg_match('/\ABearer[ \t]+([^\s\x00-\x1F\x7F]{32,512})\z/i', $header, $matches)) {
            return null;
        }

        return $matches[1];
    }
}
