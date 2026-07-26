<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Container;
use MailPanel\Core\Config;
use MailPanel\Core\Request;
use MailPanel\Core\Response;
use MailPanel\Core\Router;
use MailPanel\Repositories\Pdo\ApiTokenRepository;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\CsrfService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Security\SessionManager;
use MailPanel\Security\TokenGuard;
use MailPanel\Services\RateLimiterService;
use PHPUnit\Framework\TestCase;

final class RouterAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function test_blocks_unauthenticated_route(): void
    {
        $container = $this->makeContainer();

        $router = new Router($container);
        $router->add('GET', '/api/secure', fn () => Response::json(['ok' => true]), ['auth' => true, 'roles' => ['super_admin']]);

        $output = $this->dispatchOutput($router, new Request('GET', '/api/secure', [], [], ['REMOTE_ADDR' => '127.0.0.1']));

        $this->assertStringContainsString('Unauthenticated.', $output);
    }

    public function test_allows_session_authenticated_route(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => ['id' => 1, 'role' => 'super_admin'],
        ];

        $container = $this->makeContainer();

        $router = new Router($container);
        $router->add('GET', '/secure', fn () => Response::json(['ok' => true]), ['auth' => true, 'roles' => ['super_admin']]);

        $output = $this->dispatchOutput($router, new Request('GET', '/secure', [], [], []));

        $this->assertStringContainsString('"ok": true', (string) $output);
    }

    public function test_blocks_authenticated_actor_without_permission(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => ['id' => 2, 'role' => 'support_readonly'],
        ];

        $container = $this->makeContainer();

        $router = new Router($container);
        $router->add('GET', '/secure-write', fn () => Response::json(['ok' => true]), ['auth' => true, 'permission' => 'packages.create']);

        $output = $this->dispatchOutput($router, new Request('GET', '/secure-write', [], [], []));

        $this->assertStringContainsString('Permission denied.', $output);
    }

    public function test_allows_authenticated_actor_with_permission_metadata(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => ['id' => 3, 'role' => 'tenant_admin', 'tenant_id' => 4],
        ];

        $container = $this->makeContainer();

        $router = new Router($container);
        $router->add('GET', '/secure-view', fn () => Response::json(['ok' => true]), ['auth' => true, 'permission' => 'domains.view']);

        $output = $this->dispatchOutput($router, new Request('GET', '/secure-view', [], [], []));

        $this->assertStringContainsString('"ok": true', (string) $output);
    }

    public function test_api_session_write_requires_csrf(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => ['id' => 3, 'role' => 'tenant_admin', 'tenant_id' => 4],
        ];

        $router = new Router($this->makeContainer());
        $router->add('POST', '/api/secure-write', fn () => Response::json(['ok' => true]), ['auth' => true, 'permission' => 'domains.create', 'writable' => true]);

        $output = $this->dispatchOutput($router, new Request('POST', '/api/secure-write', [], [], ['REMOTE_ADDR' => '127.0.0.1']));

        $this->assertStringContainsString('CSRF token mismatch.', $output);
    }

    public function test_invalid_bearer_token_does_not_fallback_to_session(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => ['id' => 1, 'role' => 'super_admin'],
        ];

        $router = new Router($this->makeContainer());
        $router->add('GET', '/api/secure', fn () => Response::json(['ok' => true]), ['auth' => true, 'permission' => 'dashboard.view']);

        $output = $this->dispatchOutput($router, new Request(
            'GET',
            '/api/secure',
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'Bearer invalid-token']
        ));

        $this->assertStringContainsString('Unauthenticated.', $output);
    }

    public function test_invalid_lowercase_bearer_token_does_not_fallback_to_session(): void
    {
        $_SESSION['auth'] = [
            'guard' => 'admin',
            'identity' => ['id' => 1, 'role' => 'super_admin'],
        ];

        $router = new Router($this->makeContainer());
        $router->add('GET', '/api/secure', fn () => Response::json(['ok' => true]), ['auth' => true, 'permission' => 'dashboard.view']);

        $output = $this->dispatchOutput($router, new Request(
            'GET',
            '/api/secure',
            [],
            [],
            ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_AUTHORIZATION' => 'bearer invalid-token']
        ));

        $this->assertStringContainsString('Unauthenticated.', $output);
    }

    public function test_html_not_found_response_uses_utf8_vietnamese_text(): void
    {
        $router = new Router($this->makeContainer());

        $output = $this->dispatchOutput($router, new Request('GET', '/missing', [], [], []));

        $this->assertStringContainsString('Trang kh&#244;ng t&#7891;n t&#7841;i.', $output);
        $this->assertStringNotContainsString('khÃ', $output);
        $this->assertStringNotContainsString('tá»', $output);
        $this->assertStringNotContainsString('kh?ng', $output);
    }

    public function test_api_security_failure_response_redacts_sensitive_message_parts(): void
    {
        $router = new Router($this->makeContainer());
        $method = new \ReflectionMethod(Router::class, 'securityFailureResponse');

        $response = $method->invoke(
            $router,
            new Request('GET', '/api/secure', [], [], []),
            'Too many requests. password=Secret123 Authorization: Bearer hidden-token',
            429
        );

        ob_start();
        $response->send();
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('Too many requests. password=[REDACTED] Authorization: Bearer [REDACTED]', $payload['error']['message'] ?? null);
        $this->assertStringNotContainsString('Secret123', $payload['error']['message'] ?? '');
        $this->assertStringNotContainsString('hidden-token', $payload['error']['message'] ?? '');
    }

    private function makeContainer(): Container
    {
        $container = new Container();
        $container->set(Config::class, fn () => new Config(__DIR__, [
            'app' => [
                'rate_limits' => [
                    'api' => ['max_attempts' => 1000, 'window_seconds' => 60],
                ],
            ],
        ]));
        $container->set(RateLimiterService::class, fn () => new RateLimiterService(sys_get_temp_dir() . '/mailpanel-router-test-rate-limits'));
        $container->set(SessionManager::class, fn () => new SessionManager());
        $container->set(CsrfService::class, fn ($c) => new CsrfService($c->get(SessionManager::class)));
        $container->set(TokenGuard::class, fn () => new TokenGuard(new class extends ApiTokenRepository {
            public function __construct() {}
            public function findByHash(string $tokenHash): ?array { return null; }
            public function touch(int $id): void {}
        }));
        $container->set(RequestActorResolver::class, fn ($c) => new RequestActorResolver($c->get(SessionManager::class), $c->get(TokenGuard::class)));
        $container->set(AuthorizationService::class, fn () => new AuthorizationService());

        return $container;
    }

    private function dispatchOutput(Router $router, Request $request): string
    {
        $response = $router->dispatch($request);
        ob_start();
        $response->send();

        return (string) ob_get_clean();
    }
}
