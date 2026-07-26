<?php

declare(strict_types=1);

namespace MailPanel\Security;

final class SessionManager
{
    public function __construct(private readonly int $timeoutSeconds = 1800)
    {
    }

    public function putIdentity(array $identity, string $guard): void
    {
        $existingAdminPasswordProof = $this->adminPasswordProof();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['auth'] = [
            'guard' => $guard,
            'identity' => $identity,
            'last_activity_at' => time(),
        ];

        if (
            $guard === 'admin'
            && is_array($existingAdminPasswordProof)
            && (int) ($existingAdminPasswordProof['user_id'] ?? 0) === (int) ($identity['id'] ?? 0)
        ) {
            $_SESSION['auth']['admin_password_proof'] = $existingAdminPasswordProof;
        }
    }

    public function replaceIdentity(array $identity): void
    {
        if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
            return;
        }

        $_SESSION['auth']['identity'] = $identity;
        $_SESSION['auth']['last_activity_at'] = time();
    }

    public function beginImpersonation(array $impersonatorIdentity, array $targetIdentity): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => $targetIdentity,
            'impersonator_identity' => $impersonatorIdentity,
            'impersonating' => true,
            'last_activity_at' => time(),
        ];
    }

    public function identity(): ?array
    {
        if ($this->isExpired()) {
            $this->clear();

            return null;
        }

        if (isset($_SESSION['auth']['last_activity_at'])) {
            $_SESSION['auth']['last_activity_at'] = time();
        }

        return $_SESSION['auth']['identity'] ?? null;
    }

    public function guard(): ?string
    {
        if ($this->isExpired()) {
            $this->clear();

            return null;
        }

        return $_SESSION['auth']['guard'] ?? null;
    }

    public function isImpersonating(): bool
    {
        if ($this->isExpired()) {
            $this->clear();

            return false;
        }

        return !empty($_SESSION['auth']['impersonating']) && is_array($_SESSION['auth']['impersonator_identity'] ?? null);
    }

    public function impersonatorIdentity(): ?array
    {
        if (!$this->isImpersonating()) {
            return null;
        }

        return $_SESSION['auth']['impersonator_identity'] ?? null;
    }

    public function stopImpersonation(): ?array
    {
        $impersonator = $this->impersonatorIdentity();

        if (!is_array($impersonator)) {
            return null;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => $impersonator,
            'last_activity_at' => time(),
        ];

        return $impersonator;
    }

    public function clear(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            if (PHP_SAPI !== 'cli' && (bool) ini_get('session.use_cookies') && !headers_sent()) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    [
                        'expires' => time() - 42000,
                        'path' => $params['path'] ?? '/',
                        'domain' => $params['domain'] ?? '',
                        'secure' => (bool) ($params['secure'] ?? false),
                        'httponly' => (bool) ($params['httponly'] ?? true),
                        'samesite' => (string) ($params['samesite'] ?? 'Strict'),
                    ]
                );
            }

            session_destroy();
        }
    }

    public function storeAdminPasswordProof(array $proof): void
    {
        if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
            return;
        }

        $_SESSION['auth']['admin_password_proof'] = $proof;
    }

    public function adminPasswordProof(): ?array
    {
        if ($this->isExpired()) {
            $this->clear();

            return null;
        }

        $proof = $_SESSION['auth']['admin_password_proof'] ?? null;

        return is_array($proof) ? $proof : null;
    }

    public function clearAdminPasswordProof(): void
    {
        unset($_SESSION['auth']['admin_password_proof']);
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public function verifyCsrf(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        return hash_equals((string) ($_SESSION['_csrf'] ?? ''), $token);
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);

        return $value;
    }

    private function isExpired(): bool
    {
        $lastActivityAt = (int) ($_SESSION['auth']['last_activity_at'] ?? 0);

        return $lastActivityAt > 0 && $this->timeoutSeconds > 0 && (time() - $lastActivityAt) > $this->timeoutSeconds;
    }
}
