<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\Fail2banConfigRenderer;
use MailPanel\Services\NginxConfigRenderer;
use MailPanel\Services\RspamdConfigRenderer;
use MailPanel\Services\TlsCertificateInventory;
use PHPUnit\Framework\TestCase;

final class Phase2RendererTest extends TestCase
{
    public function test_rspamd_renderer_outputs_actions_and_antivirus_configs(): void
    {
        $draft = (new RspamdConfigRenderer(__DIR__))->render();

        $this->assertStringContainsString('reject = 15;', $draft['content']);
        $this->assertSame(__DIR__ . '/rspamd/actions.conf', $draft['path']);
        $this->assertStringContainsString('clamav {', $draft['extras'][0]['content']);
        $this->assertStringContainsString('action = "reject";', $draft['extras'][0]['content']);
        $this->assertSame('rspamd', $draft['service']);
    }

    public function test_fail2ban_renderer_outputs_dovecot_jail(): void
    {
        $draft = (new Fail2banConfigRenderer(__DIR__))->render();
        $extras = [];

        foreach ($draft['extras'] as $extra) {
            $extras[basename((string) $extra['path'])] = (string) $extra['content'];
        }

        $this->assertStringContainsString('[dovecot]', $draft['content']);
        $this->assertStringContainsString('[webmail-auth]', $draft['content']);
        $this->assertStringContainsString('enabled = false', $draft['content']);
        $this->assertStringContainsString('[exim-smtp-auth]', $draft['content']);
        $this->assertStringContainsString('[exim-reject]', $draft['content']);
        $this->assertStringContainsString('logpath = /var/log/exim4/mainlog', $draft['content']);
        $this->assertStringNotContainsString('journalmatch = _SYSTEMD_UNIT=exim4.service', $draft['content']);
        $this->assertStringContainsString('auth failed|invalid credentials|Password mismatch', $extras['mailpanel-dovecot.conf']);
        $this->assertStringContainsString('authenticator failed for .*\\[<HOST>\\]: 535 Incorrect authentication data', $extras['mailpanel-exim-auth.conf']);
        $this->assertStringContainsString('relay not permitted|Sender verify failed|Unknown user|Unrouteable address', $extras['mailpanel-exim-reject.conf']);
        $this->assertSame('fail2ban', $draft['service']);
    }

    public function test_fail2ban_renderer_can_enable_webmail_jail(): void
    {
        $draft = (new Fail2banConfigRenderer(__DIR__, true, '/var/log/webmail/auth.log'))->render();

        $this->assertStringContainsString('[webmail-auth]', $draft['content']);
        $this->assertStringContainsString('enabled = true', $draft['content']);
        $this->assertStringContainsString('logpath = /var/log/webmail/auth.log', $draft['content']);
        $this->assertSame(
            "[Definition]\nfailregex = ^.*(?:Admin )?Auth failed:\\s*ip=<HOST>\\s+user=.*$\n            ^.*(?:Failed login|php: Login failed for).*from <HOST>.*$\nignoreregex =\n",
            $draft['extras'][3]['content']
        );
    }

    public function test_fail2ban_renderer_rejects_unsafe_paths(): void
    {
        try {
            (new Fail2banConfigRenderer(__DIR__, true, "/var/log/webmail/auth.log\nenabled = true"))->render();
            $this->fail('Unsafe Fail2ban webmail log path was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('webmail log path', $exception->getMessage());
        }

        foreach (["/var/lib/mailpanel/generated\n[sshd]", '/var/lib/mailpanel/generated/..'] as $generatedRoot) {
            try {
                (new Fail2banConfigRenderer($generatedRoot))->render();
                $this->fail('Unsafe Fail2ban generated root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('generated root', $exception->getMessage());
            }
        }
    }

    public function test_rspamd_renderer_rejects_unsafe_generated_root(): void
    {
        foreach (["/var/lib/mailpanel/generated\nreject = 0;", '/var/lib/mailpanel/generated/..'] as $generatedRoot) {
            try {
                (new RspamdConfigRenderer($generatedRoot))->render();
                $this->fail('Unsafe Rspamd generated root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('generated root', $exception->getMessage());
            }
        }
    }

    public function test_nginx_renderer_uses_external_webmail_root_alias(): void
    {
        $draft = (new NginxConfigRenderer(
            __DIR__,
            '/opt/mailpanel/public',
            '_',
            '/webmail',
            '/var/www/webmail',
            '/run/php/php8.3-fpm.sock'
        ))->render();

        $this->assertStringContainsString('alias /var/www/webmail/;', $draft['content']);
        $this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME /var/www/webmail/$1;', $draft['content']);
        $this->assertStringContainsString('location @mailpanel_webmail_index', $draft['content']);
        $this->assertStringContainsString('location ^~ /qa/', $draft['content']);
        $this->assertStringContainsString('return 404;', $draft['content']);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $draft['content']);
        $this->assertStringContainsString("style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;", $draft['content']);
        $this->assertStringContainsString('Keep this relaxed policy scoped to the webmail PHP location only', $draft['content']);
        $this->assertSame(1, substr_count($draft['content'], 'add_header Content-Security-Policy'));
        $this->assertSame(1, substr_count($draft['content'], 'add_header X-Frame-Options'));
        $this->assertGreaterThanOrEqual(1, substr_count($draft['content'], 'add_header Strict-Transport-Security'));
        $this->assertSame('nginx', $draft['service']);
    }

    public function test_nginx_renderer_rejects_unsafe_dynamic_config_values(): void
    {
        foreach ([
            "/opt/mailpanel/public\nroot /tmp;",
            '/opt/mailpanel/public/..',
        ] as $root) {
            try {
                (new NginxConfigRenderer(
                    __DIR__,
                    $root,
                    '_',
                    '/webmail',
                    '/var/www/webmail',
                    '/run/php/php8.3-fpm.sock'
                ))->render();
                $this->fail('Unsafe Nginx root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('nginx root', $exception->getMessage());
            }
        }
    }

    public function test_nginx_renderer_rejects_unsafe_generated_root(): void
    {
        foreach ([
            "/var/lib/mailpanel/generated\nserver {",
            '/var/lib/mailpanel/../generated',
            '/var/lib/mailpanel/generated/..',
            '/',
        ] as $path) {
            try {
                (new NginxConfigRenderer(
                    $path,
                    '/opt/mailpanel/public',
                    '_',
                    '/webmail',
                    '/var/www/webmail',
                    '/run/php/php8.3-fpm.sock'
                ))->render();
                $this->fail('Unsafe Nginx generated root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('generated root', $exception->getMessage());
            }
        }
    }

    public function test_tls_inventory_rejects_unsafe_root_and_hostname(): void
    {
        foreach ([
            "/etc/mailpanel/tls/sni\nssl_certificate /tmp/x;",
            '/etc/mailpanel/tls/sni/..',
        ] as $root) {
            try {
                new TlsCertificateInventory($root);
                $this->fail('Unsafe TLS SNI root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('TLS SNI root', $exception->getMessage());
            }
        }
    }
}
