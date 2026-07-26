<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Response;
use PHPUnit\Framework\TestCase;

final class ResponseSecurityHeadersTest extends TestCase
{
    public function test_dynamic_responses_default_to_private_no_store_headers(): void
    {
        $headers = $this->defaultSecurityHeaders();

        $this->assertSame('no-store, no-cache, must-revalidate, max-age=0', $headers['Cache-Control'] ?? null);
        $this->assertSame('no-cache', $headers['Pragma'] ?? null);
        $this->assertSame('0', $headers['Expires'] ?? null);
        $this->assertSame('noindex, nofollow, noarchive', $headers['X-Robots-Tag'] ?? null);
        $this->assertSame('same-origin', $headers['Cross-Origin-Resource-Policy'] ?? null);
    }

    public function test_response_allows_explicit_safe_cache_override_for_special_cases(): void
    {
        $response = new Response(200, '', ['Cache-Control' => 'private, max-age=30']);
        $headers = $this->headersFor($response);

        $this->assertSame('private, max-age=30', $headers['Cache-Control'] ?? null);
    }

    public function test_html_response_csp_does_not_allow_inline_script_or_style(): void
    {
        $response = Response::html('<!doctype html><html lang="vi"><body>OK</body></html>');
        $headers = $this->headersFor($response);
        $csp = (string) ($headers['Content-Security-Policy'] ?? '');

        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("style-src 'self' https://fonts.googleapis.com", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);
    }

    public function test_redirect_allows_only_safe_relative_locations(): void
    {
        $response = Response::redirect('/admin/tenants?edit_tenant=12#tenant-create');
        $headers = $this->headersFor($response);

        $this->assertSame('/admin/tenants?edit_tenant=12#tenant-create', $headers['Location'] ?? null);

        foreach (['https://evil.example/', '//evil.example/', "/admin\r\nX-Test: injected", '/admin\\evil'] as $location) {
            try {
                Response::redirect($location);
                $this->fail('Unsafe redirect location was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('redirect location', $exception->getMessage());
            }
        }
    }

    public function test_response_rejects_header_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP header value');

        new Response(200, '', ['X-Test' => "ok\r\nX-Injected: yes"]);
    }

    public function test_response_rejects_invalid_status_codes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTP status code');

        new Response(99, '');
    }

    private function headersFor(Response $response): array
    {
        $property = new \ReflectionProperty(Response::class, 'headers');

        return $property->getValue($response);
    }

    private function defaultSecurityHeaders(): array
    {
        $constant = new \ReflectionClassConstant(Response::class, 'DEFAULT_SECURITY_HEADERS');

        return $constant->getValue();
    }
}
