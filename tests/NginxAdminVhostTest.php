<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Services\NginxConfigRenderer;
use MailPanel\Services\TlsCertificateInventory;
use PHPUnit\Framework\TestCase;

final class NginxAdminVhostTest extends TestCase
{
    private function renderer(
        string $adminHostname = '',
        array $adminIpAllowlist = [],
        int $adminPort = 443,
    ): NginxConfigRenderer {
        return new NginxConfigRenderer(
            sys_get_temp_dir() . '/mailpanel-nginx-test',
            '/opt/mailpanel/public',
            'panel.example.com',
            '/webmail',
            '/var/www/webmail',
            '/run/php/php8.3-fpm.sock',
            '/etc/ssl/certs/ssl-cert-snakeoil.pem',
            '/etc/ssl/private/ssl-cert-snakeoil.key',
            '/var/www/acme',
            new TlsCertificateInventory('/nonexistent/mailpanel-test-sni'),
            $adminHostname,
            $adminIpAllowlist,
            $adminPort,
        );
    }

    public function test_no_admin_vhost_is_emitted_by_default(): void
    {
        $content = $this->renderer()->render()['content'];

        $this->assertStringNotContainsString('Super admin console', $content);
        $this->assertStringContainsString('server_name panel.example.com;', $content);
    }

    public function test_admin_vhost_is_emitted_when_a_hostname_is_configured(): void
    {
        $content = $this->renderer('admin.example.com')->render()['content'];

        $this->assertStringContainsString('server_name admin.example.com;', $content);
        $this->assertStringContainsString('Super admin console', $content);
    }

    public function test_admin_vhost_denies_everything_outside_the_allowlist(): void
    {
        $content = $this->renderer('admin.example.com', ['198.51.100.0/24', '2001:db8::/32'])->render()['content'];

        $this->assertStringContainsString('allow 198.51.100.0/24;', $content);
        $this->assertStringContainsString('allow 2001:db8::/32;', $content);
        $this->assertStringContainsString('deny all;', $content);
    }

    public function test_empty_allowlist_emits_no_deny_rule_but_says_so(): void
    {
        $content = $this->renderer('admin.example.com')->render()['content'];

        $this->assertStringContainsString('ADMIN_IP_ALLOWLIST is empty', $content);
    }

    /**
     * Webmail is a tenant-facing surface; exposing it on the admin hostname would
     * widen the very surface this split is meant to narrow.
     */
    public function test_webmail_and_qa_are_blocked_on_the_admin_vhost(): void
    {
        $content = $this->renderer('admin.example.com')->render()['content'];

        $adminBlock = substr($content, (int) strpos($content, 'Super admin console'));

        $this->assertStringContainsString('location ^~ /webmail {', $adminBlock);
        $this->assertStringContainsString('return 404;', $adminBlock);
        $this->assertStringContainsString('location ^~ /qa/ {', $adminBlock);
    }

    public function test_admin_vhost_forbids_framing_entirely(): void
    {
        $content = $this->renderer('admin.example.com')->render()['content'];
        $adminBlock = substr($content, (int) strpos($content, 'Super admin console'));

        $this->assertStringContainsString('X-Frame-Options "DENY"', $adminBlock);
        $this->assertStringContainsString("frame-ancestors 'none'", $adminBlock);
    }

    public function test_custom_admin_port_is_honoured(): void
    {
        $content = $this->renderer('admin.example.com', [], 8443)->render()['content'];

        $this->assertStringContainsString('listen 8443 ssl http2;', $content);
    }

    // --------------------------------------------------------- injection guards

    public function test_malformed_admin_hostname_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer("admin.example.com;\n    root /etc;")->render();
    }

    public function test_malformed_allowlist_entry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer('admin.example.com', ["198.51.100.0/24;\n    root /etc;"])->render();
    }

    public function test_out_of_range_admin_port_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->renderer('admin.example.com', [], 70000)->render();
    }
}
