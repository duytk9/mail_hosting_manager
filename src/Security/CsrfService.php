<?php

declare(strict_types=1);

namespace MailPanel\Security;

use MailPanel\Core\Request;
use RuntimeException;

final class CsrfService
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly string $headerName = 'X-CSRF-Token',
    ) {
    }

    public function token(): string
    {
        return $this->sessions->csrfToken();
    }

    public function verifyRequest(Request $request): void
    {
        $headerToken = $request->header($this->headerName);
        $bodyToken = is_string($request->body['_csrf'] ?? null) ? $request->body['_csrf'] : null;
        $token = $bodyToken ?? $headerToken;

        if (!$this->sessions->verifyCsrf($token)) {
            throw new RuntimeException('CSRF token mismatch.');
        }
    }
}
