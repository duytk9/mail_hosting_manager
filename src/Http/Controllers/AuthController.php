<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers;

use MailPanel\Core\Request;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TokenGuard;
use MailPanel\Services\ApiTokenService;
use MailPanel\Services\AdminSecurityService;
use MailPanel\Services\AuthService;
use MailPanel\Support\ApiResponse;
use InvalidArgumentException;
use Throwable;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly SessionManager $sessions,
        private readonly RequestActorResolver $actorResolver,
        private readonly AuthorizationService $authorization,
        private readonly ApiTokenService $apiTokenService,
        private readonly AdminSecurityService $adminSecurityService
    ) {
    }

    public function adminLogin(Request $request)
    {
        try {
            return ApiResponse::success($this->authService->loginAdmin(
                $request->body['login'] ?? '',
                $request->body['password'] ?? '',
                $request->body['otp'] ?? null,
                $request->ip(),
                $request->userAgent()
            ));
        } catch (Throwable $exception) {
            return ApiResponse::exception($exception, 401, 'Invalid credentials.');
        }
    }

    public function mailboxLogin(Request $request)
    {
        try {
            return ApiResponse::success($this->authService->loginMailbox(
                $request->body['email'] ?? '',
                $request->body['password'] ?? '',
                $request->ip(),
                $request->userAgent()
            ));
        } catch (Throwable $exception) {
            return ApiResponse::exception($exception, 401, 'Invalid credentials.');
        }
    }

    public function mailboxPasswordChange(Request $request)
    {
        try {
            return ApiResponse::success($this->authService->changeMailboxPasswordByCredentials(
                (string) ($request->body['email'] ?? ''),
                (string) ($request->body['current_password'] ?? ''),
                (string) ($request->body['new_password'] ?? ''),
                $request->ip(),
                $request->userAgent()
            ));
        } catch (Throwable $exception) {
            $isInvalidCredentials = $exception instanceof InvalidArgumentException
                && $exception->getMessage() === 'Invalid credentials.';

            return ApiResponse::exception(
                $exception,
                $isInvalidCredentials ? 401 : 422,
                $isInvalidCredentials ? 'Invalid credentials.' : 'Unable to change password.'
            );
        }
    }

    public function webmailMailboxPasswordChange(Request $request)
    {
        try {
            $this->assertWebmailPasswordBridgeRequest($request);
        } catch (Throwable) {
            return ApiResponse::error('Invalid webmail password-change request.', 403);
        }

        return $this->mailboxPasswordChange($request);
    }

    public function logout(Request $request)
    {
        $this->sessions->clear();

        return ApiResponse::success(['logged_out' => true]);
    }

    public function issueToken(Request $request)
    {
        return $this->issueLoginCredential($request);
    }

    public function issueLoginKey(Request $request)
    {
        return $this->issueLoginCredential($request);
    }

    private function issueLoginCredential(Request $request)
    {
        try {
            if (TokenGuard::hasBearerCredential($request->header('Authorization', '') ?? '')) {
                throw new \RuntimeException('Login Key issuance requires an active admin session.');
            }

            $actor = $this->actorResolver->resolve($request);
            $this->authorization->requirePermission($actor, 'api_tokens.create');
            $this->assertSessionCredentialIssuance($request, $actor->id);

            $issued = $this->apiTokenService->issueAdminToken(
                $actor->id,
                $actor->tenantId,
                $actor->role,
                (string) ($request->body['login_key_name'] ?? $request->body['name'] ?? 'default'),
                $request->body['scopes'] ?? [],
                $request->body['expires_at'] ?? null
            );

            $plainCredential = (string) ($issued['plain_text_token'] ?? '');
            if ($plainCredential !== '') {
                $issued['login_key'] = $plainCredential;
                $issued['plain_text_login_key'] = $plainCredential;
            }
            $issued['credential_label'] = 'Login Key';

            return ApiResponse::success($issued, [], 201);
        } catch (Throwable $exception) {
            return ApiResponse::exception(
                $exception,
                422,
                'Unable to issue Login Key.',
                [\InvalidArgumentException::class, \RuntimeException::class]
            );
        }
    }

    private function assertSessionCredentialIssuance(Request $request, int $userId): void
    {
        if (TokenGuard::hasBearerCredential($request->header('Authorization', '') ?? '')) {
            throw new \RuntimeException('Login Key issuance requires an active admin session.');
        }

        $identity = $this->sessions->identity();
        if ($this->sessions->guard() !== 'admin' || !is_array($identity) || (int) ($identity['id'] ?? 0) !== $userId) {
            throw new \RuntimeException('Login Key issuance requires an active admin session.');
        }

        $this->adminSecurityService->assertSensitiveActionAllowed(
            $userId,
            (string) ($request->body['current_password'] ?? ''),
            isset($request->body['otp']) ? (string) $request->body['otp'] : null
        );
    }

    private function assertWebmailPasswordBridgeRequest(Request $request): void
    {
        if (trim((string) $request->header('X-MailPanel-Webmail-Bridge', '')) !== '1') {
            throw new \RuntimeException('Missing webmail bridge header.');
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if (!str_contains($contentType, 'application/json')) {
            throw new \RuntimeException('Webmail bridge requires JSON.');
        }

        $requestHost = $this->normalizedAuthority((string) $request->header('Host', ''));
        if ($requestHost === '') {
            throw new \RuntimeException('Missing request host.');
        }

        $origin = trim((string) $request->header('Origin', ''));
        $referer = trim((string) $request->header('Referer', ''));
        if ($origin === '' && $referer === '') {
            throw new \RuntimeException('Missing same-origin proof.');
        }

        if ($origin !== '' && !$this->isSameOriginUrl($origin, $requestHost)) {
            throw new \RuntimeException('Invalid webmail bridge origin.');
        }

        if ($origin === '' && $referer !== '' && !$this->isSameOriginUrl($referer, $requestHost)) {
            throw new \RuntimeException('Invalid webmail bridge referer.');
        }
    }

    private function isSameOriginUrl(string $url, string $requestHost): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        $port = parse_url($url, PHP_URL_PORT);
        $authority = $host . (is_int($port) ? ':' . $port : '');

        return $this->normalizedAuthority($authority) === $requestHost;
    }

    private function normalizedAuthority(string $authority): string
    {
        $authority = strtolower(trim($authority));
        if ($authority === '' || preg_match('/[\x00-\x1F\x7F]/', $authority) === 1) {
            return '';
        }

        if (str_starts_with($authority, '[')) {
            if (preg_match('/\A\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?\z/i', $authority, $matches) !== 1) {
                return '';
            }

            $port = $this->normalizedPort($matches[2] ?? null);
            if ($port === null) {
                return '';
            }

            return '[' . $matches[1] . ']' . $port;
        }

        if (preg_match('/\A([a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?)(?::([0-9]{1,5}))?\z/i', $authority, $matches) !== 1) {
            return '';
        }

        $port = $this->normalizedPort($matches[2] ?? null);
        if ($port === null) {
            return '';
        }

        return $matches[1] . $port;
    }

    private function normalizedPort(mixed $port): ?string
    {
        if ($port === null || $port === '') {
            return '';
        }

        $portNumber = filter_var($port, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);

        return is_int($portNumber) ? ':' . $portNumber : null;
    }
}
