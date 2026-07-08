<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DkimKeyRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailGroupMemberRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Services\DovecotConfigRenderer;
use MailPanel\Services\EximConfigRenderer;
use MailPanel\Services\TlsCertificateInventory;
use PHPUnit\Framework\TestCase;

final class ConfigRendererTest extends TestCase
{
    public function test_exim_renderer_contains_domains(): void
    {
        $domains = new class extends DomainRepository {
            public function __construct() {}
            public function all(): array { return [['id' => 8, 'tenant_id' => 3, 'domain' => 'example.test', 'status' => 'active', 'inbound_enabled' => 1, 'outbound_enabled' => 1]]; }
        };
        $mailboxes = new class extends MailboxRepository {
            public function __construct() {}
            public function all(): array
            {
                return [[
                    'id' => 5,
                    'tenant_id' => 3,
                    'domain_id' => 8,
                    'email' => 'admin@example.test',
                    'status' => 'active',
                    'smtp_enabled' => 1,
                ]];
            }
        };
        $aliases = new class extends AliasRepository {
            public function __construct() {}
            public function all(): array
            {
                return [
                    [
                        'source_address' => 'sales@example.test',
                        'destination_mailbox_id' => 5,
                    ],
                    [
                        'source_address' => "evil@example.test\ninjected:1",
                        'destination_mailbox_id' => 5,
                    ],
                ];
            }
        };
        $tenants = new class extends TenantRepository {
            public function __construct() {}
            public function all(): array
            {
                return [['id' => 3, 'status' => 'active', 'package_id' => 9, 'max_total_quota_mb' => 1024]];
            }
        };
        $packages = new class extends PackageRepository {
            public function __construct() {}
            public function all(): array
            {
                return [['id' => 9, 'max_message_size_mb' => 50, 'outbound_per_hour' => 120, 'outbound_per_day' => 1200, 'dkim_enabled' => 1]];
            }
        };
        $dkimKeys = new class extends DkimKeyRepository {
            public function __construct() {}
            public function activeSigningKeys(): array
            {
                return [[
                    'domain' => 'example.test',
                    'selector_name' => 'mail',
                    'private_key_path' => '/etc/mailpanel/dkim/example.test/mail.private',
                ], [
                    'domain' => 'badpath.test',
                    'selector_name' => 'mail',
                    'private_key_path' => '/etc/mailpanel/dkim/..',
                ], [
                    'domain' => 'rootpath.test',
                    'selector_name' => 'mail',
                    'private_key_path' => '/',
                ]];
            }
        };
        $groups = new class extends MailGroupRepository {
            public function __construct() {}
            public function all(): array
            {
                return [[
                    'id' => 11,
                    'tenant_id' => 3,
                    'domain_id' => 8,
                    'email' => 'team@example.test',
                    'status' => 'active',
                ]];
            }
        };
        $groupMembers = new class extends MailGroupMemberRepository {
            public function __construct() {}
            public function forGroupIds(array $groupIds): array
            {
                return [
                    ['group_id' => 11, 'recipient_address' => 'beta@example.net'],
                    ['group_id' => 11, 'recipient_address' => 'alpha@example.net'],
                    ['group_id' => 11, 'recipient_address' => 'team@example.test'],
                ];
            }
        };

        $sniRoot = sys_get_temp_dir() . '/mailpanel-sni-' . bin2hex(random_bytes(4));
        mkdir($sniRoot . '/mail.example.test', 0775, true);
        file_put_contents($sniRoot . '/mail.example.test/fullchain.pem', 'cert');
        file_put_contents($sniRoot . '/mail.example.test/privkey.pem', 'key');

        $renderer = new EximConfigRenderer(
            __DIR__,
            $domains,
            $mailboxes,
            $aliases,
            $tenants,
            '/etc/exim4/ssl/mailpanel.pem',
            '/etc/exim4/ssl/mailpanel.key',
            '25 : 465 : 587',
            '465',
            new TlsCertificateInventory($sniRoot),
            $groups,
            $groupMembers,
            null,
            $packages,
            $dkimKeys,
            null,
            '587',
        );
        $draft = $renderer->render();
        $extras = [];

        foreach ($draft['extras'] as $extra) {
            $extras[basename((string) $extra['path'])] = (string) $extra['content'];
        }

        $this->assertStringContainsString('sender address is not allowed', $draft['content']);
        $this->assertStringContainsString('552 MailPanel policy: tenant quota exceeded', $draft['content']);
        $this->assertStringContainsString('MailPanel policy: unknown local domain', $draft['content']);
        $this->assertStringContainsString('eq{$authenticated_id}{}', $draft['content']);
        $this->assertCount(28, $draft['extras']);
        $this->assertStringContainsString('sales@example.test:admin@example.test', $extras['allowed_senders.map']);
        $this->assertStringNotContainsString('evil@example.test', $extras['allowed_senders.map']);
        $this->assertStringNotContainsString('injected:1', $extras['allowed_senders.map']);
        $this->assertStringContainsString('admin@example.test:1', $extras['smtp_submit_enabled.map']);
        $this->assertStringContainsString('example.test:1', $extras['local_domains.map']);
        $this->assertArrayHasKey('tenant_quota_exceeded_domains.map', $extras);
        $this->assertStringContainsString('admin@example.test:120', $extras['outbound_mailbox_hourly.map']);
        $this->assertStringContainsString('admin@example.test:1200', $extras['outbound_mailbox_daily.map']);
        $this->assertStringContainsString('admin@example.test:52428800', $extras['message_size_limit.map']);
        $this->assertStringContainsString('example.test:120', $extras['outbound_domain_hourly.map']);
        $this->assertStringContainsString('3:1200', $extras['outbound_tenant_daily.map']);
        $this->assertStringContainsString('admin@example.test:example.test', $extras['authenticated_domain.map']);
        $this->assertStringContainsString('example.test:/etc/mailpanel/dkim/example.test/mail.private', $extras['dkim_privatekeys.map']);
        $this->assertStringNotContainsString('badpath.test', $extras['dkim_privatekeys.map']);
        $this->assertStringNotContainsString('rootpath.test', $extras['dkim_privatekeys.map']);
        $this->assertStringContainsString('example.test:mail', $extras['dkim_selectors.map']);
        $this->assertStringContainsString('team@example.test:alpha@example.net,beta@example.net', $extras['mail_groups.map']);
        $this->assertStringContainsString('mailpanel_group_redirect:', $extras['mailpanel-router.conf']);
        $this->assertStringContainsString('mailpanel_remote_smtp:', $extras['mailpanel-router.conf']);
        $this->assertStringContainsString('domains = ! +local_domains', $extras['mailpanel-router.conf']);
        $this->assertStringContainsString('dkim_private_key = ${lookup{${lc:$sender_address_domain}}lsearch{/etc/exim4/mailpanel/dkim_privatekeys.map}{$value}{}}', $extras['mailpanel-transport.conf']);
        $this->assertStringContainsString('outbound daily limit exceeded for this tenant', $draft['content']);
        $this->assertStringContainsString('message exceeds package size limit', $draft['content']);
        $this->assertStringContainsString('mail.example.test:' . str_replace('\\', '/', $sniRoot) . '/mail.example.test/fullchain.pem', str_replace('\\', '/', $extras['tls_certificates.map']));
        $this->assertStringContainsString('mail.example.test:' . str_replace('\\', '/', $sniRoot) . '/mail.example.test/privkey.pem', str_replace('\\', '/', $extras['tls_privatekeys.map']));
        $this->assertStringContainsString('MAIN_TLS_CERTIFICATE = ${lookup{${sg{$tls_in_sni}{[^A-Za-z0-9.-]}{}}}lsearch{/etc/exim4/mailpanel/tls_certificates.map}{$value}{/etc/exim4/ssl/mailpanel.pem}}', $extras['exim4.conf.localmacros.managed']);
        $this->assertStringContainsString('MAIN_TLS_PRIVATEKEY = ${lookup{${sg{$tls_in_sni}{[^A-Za-z0-9.-]}{}}}lsearch{/etc/exim4/mailpanel/tls_privatekeys.map}{$value}{/etc/exim4/ssl/mailpanel.key}}', $extras['exim4.conf.localmacros.managed']);
        $this->assertStringContainsString('MAIN_ACL_CHECK_MAIL = acl_mailpanel_check_mail', $extras['exim4.conf.localmacros.managed']);
        $this->assertStringContainsString('acl_mailpanel_check_mail:', $extras['mailpanel-acl-mail.conf']);
        $this->assertStringContainsString('sender address is not allowed for this mailbox', $extras['mailpanel-acl-mail.conf']);
        $this->assertStringContainsString('daemon_smtp_ports = 25 : 465 : 587', $extras['exim4.conf.localmacros.managed']);
        $this->assertStringContainsString('driver = dovecot', $extras['mailpanel-auth.conf']);
        $this->assertStringContainsString('received_port', $extras['mailpanel-auth.conf']);
        $this->assertStringContainsString('^(?:587)$', $extras['mailpanel-auth.conf']);
    }

    public function test_dovecot_renderer_contains_vmail_root(): void
    {
        $sniRoot = sys_get_temp_dir() . '/mailpanel-sni-' . bin2hex(random_bytes(4));
        mkdir($sniRoot . '/mail.example.test', 0775, true);
        file_put_contents($sniRoot . '/mail.example.test/fullchain.pem', 'cert');
        file_put_contents($sniRoot . '/mail.example.test/privkey.pem', 'key');

        $renderer = new DovecotConfigRenderer(__DIR__, '/var/vmail', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'mailpanel',
            'username' => 'mailpanel',
            'password' => 'secret',
        ], 2000, 2000, 'BLF-CRYPT', '/etc/exim4/ssl/mailpanel.pem', '/etc/exim4/ssl/mailpanel.key', new TlsCertificateInventory($sniRoot));
        $draft = $renderer->render();

        $this->assertStringContainsString('/var/vmail', $draft['content']);
        $this->assertStringContainsString('ssl_cert = </etc/exim4/ssl/mailpanel.pem', $draft['content']);
        $this->assertStringContainsString('local_name mail.example.test {', $draft['content']);
        $this->assertStringContainsString('sieve = file:~/sieve;active=~/sieve/active.sieve', $draft['content']);
        $this->assertArrayHasKey('extras', $draft);
        $this->assertStringContainsString('connect = host=127.0.0.1 port=3306 dbname=mailpanel user=mailpanel password=secret', $draft['extras'][0]['content']);
        $this->assertSame(2, substr_count($draft['extras'][0]['content'], 'COALESCE(m.force_password_change, 0) = 0'));
        $this->assertStringContainsString("'%s' = 'imap' AND m.imap_enabled = 1", $draft['extras'][0]['content']);
        $this->assertStringContainsString("'%s' = 'pop3' AND m.pop3_enabled = 1", $draft['extras'][0]['content']);
        $this->assertStringContainsString("'%s' = 'sieve' AND m.managesieve_enabled = 1", $draft['extras'][0]['content']);
    }

    public function test_dovecot_renderer_escapes_and_rejects_unsafe_connect_values(): void
    {
        $renderer = new DovecotConfigRenderer(__DIR__, '/var/vmail', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'mailpanel',
            'username' => 'mail panel',
            'password' => 'pa ss\\word',
        ]);
        $draft = $renderer->render();

        $this->assertStringContainsString('user=mail\\ panel', $draft['extras'][0]['content']);
        $this->assertStringContainsString('password=pa\\ ss\\\\word', $draft['extras'][0]['content']);

        $this->expectException(\InvalidArgumentException::class);
        (new DovecotConfigRenderer(__DIR__, '/var/vmail', [
            'driver' => 'mysql',
            'host' => "127.0.0.1\npassword=owned",
            'port' => 3306,
            'database' => 'mailpanel',
            'username' => 'mailpanel',
            'password' => 'secret',
        ]))->render();
    }

    public function test_dovecot_renderer_rejects_unsafe_paths_scheme_and_uid(): void
    {
        try {
            (new DovecotConfigRenderer(
                __DIR__,
                "/var/vmail\nssl = no",
                [
                    'driver' => 'mysql',
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'mailpanel',
                    'username' => 'mailpanel',
                    'password' => 'secret',
                ]
            ))->render();
            $this->fail('Unsafe Dovecot vmail root was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('vmail root', $exception->getMessage());
        }

        try {
            (new DovecotConfigRenderer(
                __DIR__,
                '/var/vmail/..',
                [
                    'driver' => 'mysql',
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'mailpanel',
                    'username' => 'mailpanel',
                    'password' => 'secret',
                ]
            ))->render();
            $this->fail('Unsafe Dovecot vmail traversal was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('vmail root', $exception->getMessage());
        }

        try {
            (new DovecotConfigRenderer(
                __DIR__,
                '/var/vmail',
                [
                    'driver' => 'mysql',
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'mailpanel',
                    'username' => 'mailpanel',
                    'password' => 'secret',
                ],
                0
            ))->render();
            $this->fail('Unsafe Dovecot uid was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('vmail uid', $exception->getMessage());
        }

        try {
            (new DovecotConfigRenderer(
                __DIR__,
                '/var/vmail',
                [
                    'driver' => 'mysql',
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'mailpanel',
                    'username' => 'mailpanel',
                    'password' => 'secret',
                ],
                2000,
                2000,
                "BLF-CRYPT\npassdb hacked"
            ))->render();
            $this->fail('Unsafe Dovecot password scheme was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('password scheme', $exception->getMessage());
        }
    }

    public function test_exim_renderer_rejects_unsafe_macro_paths_and_ports(): void
    {
        $domains = new class extends DomainRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };
        $mailboxes = new class extends MailboxRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };
        $aliases = new class extends AliasRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };
        $tenants = new class extends TenantRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };

        try {
            (new EximConfigRenderer(
                __DIR__,
                $domains,
                $mailboxes,
                $aliases,
                $tenants,
                "/etc/exim4/ssl/mailpanel.pem\nMAIN_TLS_ENABLE = no",
                '/etc/exim4/ssl/mailpanel.key',
                '25 : 587',
                '465'
            ))->render();
            $this->fail('Unsafe Exim TLS path was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('TLS certificate path', $exception->getMessage());
        }

        try {
            (new EximConfigRenderer(
                __DIR__,
                $domains,
                $mailboxes,
                $aliases,
                $tenants,
                '/etc/exim4/ssl/mailpanel.pem',
                '/etc/exim4/ssl/mailpanel.key',
                '25 : 99999',
                '465'
            ))->render();
            $this->fail('Unsafe Exim port list was accepted.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('submission ports', $exception->getMessage());
        }
    }

    public function test_exim_renderer_rejects_unsafe_generated_root(): void
    {
        $domains = new class extends DomainRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };
        $mailboxes = new class extends MailboxRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };
        $aliases = new class extends AliasRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };
        $tenants = new class extends TenantRepository {
            public function __construct() {}
            public function all(): array { return []; }
        };

        foreach ([
            "/var/lib/mailpanel/generated\nacl = owned",
            '/var/lib/mailpanel/../generated',
            '/',
        ] as $generatedRoot) {
            try {
                (new EximConfigRenderer(
                    $generatedRoot,
                    $domains,
                    $mailboxes,
                    $aliases,
                    $tenants,
                ))->render();
                $this->fail('Unsafe Exim generated root was accepted.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringContainsString('generated root', $exception->getMessage());
            }
        }
    }
}
