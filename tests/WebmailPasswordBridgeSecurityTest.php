<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Request;
use MailPanel\Core\Response;
use MailPanel\Http\Controllers\AuthController;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Security\SessionManager;
use MailPanel\Services\AdminSecurityService;
use MailPanel\Services\ApiTokenService;
use MailPanel\Services\AuthService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WebmailPasswordBridgeSecurityTest extends TestCase
{
    public function test_webmail_password_bridge_rejects_requests_without_bridge_header(): void
    {
        $response = $this->controller()->webmailMailboxPasswordChange(new Request(
            'POST',
            '/api/webmail/password-change',
            [],
            [
                'email' => 'user@example.test',
                'current_password' => 'CurrentStrong123!',
                'new_password' => 'NextStrong123!',
            ],
            [
                'HTTP_HOST' => 'portal.example.test',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://portal.example.test',
            ]
        ));

        $payload = $this->responsePayload($response);

        self::assertSame(403, $payload['status']);
        self::assertFalse($payload['body']['success']);
        self::assertSame('Invalid webmail password-change request.', $payload['body']['error']['message']);
    }

    public function test_webmail_password_bridge_rejects_cross_origin_requests(): void
    {
        $response = $this->controller()->webmailMailboxPasswordChange(new Request(
            'POST',
            '/api/webmail/password-change',
            [],
            [
                'email' => 'user@example.test',
                'current_password' => 'CurrentStrong123!',
                'new_password' => 'NextStrong123!',
            ],
            [
                'HTTP_HOST' => 'portal.example.test',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://evil.example.test',
                'HTTP_X_MAILPANEL_WEBMAIL_BRIDGE' => '1',
            ]
        ));

        $payload = $this->responsePayload($response);

        self::assertSame(403, $payload['status']);
        self::assertFalse($payload['body']['success']);
        self::assertSame('Invalid webmail password-change request.', $payload['body']['error']['message']);
    }

    public function test_webmail_password_bridge_rejects_same_host_different_port(): void
    {
        $response = $this->controller()->webmailMailboxPasswordChange(new Request(
            'POST',
            '/api/webmail/password-change',
            [],
            [
                'email' => 'user@example.test',
                'current_password' => 'CurrentStrong123!',
                'new_password' => 'NextStrong123!',
            ],
            [
                'HTTP_HOST' => 'portal.example.test:8443',
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'https://portal.example.test:9443',
                'HTTP_X_MAILPANEL_WEBMAIL_BRIDGE' => '1',
            ]
        ));

        $payload = $this->responsePayload($response);

        self::assertSame(403, $payload['status']);
        self::assertFalse($payload['body']['success']);
        self::assertSame('Invalid webmail password-change request.', $payload['body']['error']['message']);
    }

    public function test_webmail_password_bridge_route_and_plugin_header_stay_bound(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__) . '/routes/api.php');
        $plugin = (string) file_get_contents(dirname(__DIR__) . '/src/Services/WebmailPluginDeploymentService.php');

        self::assertStringContainsString("'/api/webmail/password-change', [AuthController::class, 'webmailMailboxPasswordChange']", $routes);
        self::assertStringContainsString("'X-MailPanel-Webmail-Bridge': '1'", $plugin);
        self::assertStringContainsString('normalizedAuthority', (string) file_get_contents(dirname(__DIR__) . '/src/Http/Controllers/AuthController.php'));
    }

    private function controller(): AuthController
    {
        $sessions = new SessionManager();

        return new AuthController(
            (new ReflectionClass(AuthService::class))->newInstanceWithoutConstructor(),
            $sessions,
            new RequestActorResolver($sessions),
            new AuthorizationService(),
            (new ReflectionClass(ApiTokenService::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(AdminSecurityService::class))->newInstanceWithoutConstructor()
        );
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function responsePayload(Response $response): array
    {
        $reflection = new ReflectionClass($response);
        $status = $reflection->getProperty('status');
        $body = $reflection->getProperty('body');

        return [
            'status' => (int) $status->getValue($response),
            'body' => $body->getValue($response),
        ];
    }
}
