<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use InvalidArgumentException;
use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Services\EximConfigRenderer;
use MailPanel\Services\TlsCertificateInventory;
use PHPUnit\Framework\TestCase;

/**
 * EximConfigRenderer is the largest service in the codebase and it writes the
 * configuration that governs which mail the server accepts and relays. It had no
 * dedicated test. A silent regression here is an open relay or a mail outage, so
 * the policy decisions are pinned down explicitly.
 */
final class EximConfigRendererTest extends TestCase
{
    private string $generatedRoot;

    protected function setUp(): void
    {
        $this->generatedRoot = sys_get_temp_dir() . '/mailpanel-exim-test';
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<int, array<string, mixed>> $domains
     * @param array<int, array<string, mixed>> $mailboxes
     * @param array<int, array<string, mixed>> $tenants
     * @param array<int, array<string, mixed>> $aliases
     * @param array<int, array<string, mixed>> $packages
     */
    private function render(
        array $domains,
        array $mailboxes,
        array $tenants,
        array $aliases = [],
        array $packages = [],
    ): array {
        $domainRepo = new class ($domains) extends DomainRepository {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function all(?int $tenantId = null): array
            {
                return $this->rows;
            }
        };

        $mailboxRepo = new class ($mailboxes) extends MailboxRepository {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function all(?int $tenantId = null): array
            {
                return $this->rows;
            }
        };

        $aliasRepo = new class ($aliases) extends AliasRepository {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function all(?int $tenantId = null): array
            {
                return $this->rows;
            }
        };

        $tenantRepo = new class ($tenants) extends TenantRepository {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function all(?int $tenantId = null): array
            {
                return $this->rows;
            }
        };

        $packageRepo = new class ($packages) extends PackageRepository {
            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(private array $rows)
            {
            }

            public function all(): array
            {
                return $this->rows;
            }
        };

        // MailGroupMemberRepository / QuotaUsageRepository / DkimKeyRepository are
        // optional collaborators; passing null exercises the "nothing configured"
        // path without needing stubs. QuotaUsageRepository is final and cannot be
        // subclassed anyway.
        $groupRepo = new class extends MailGroupRepository {
            public function __construct()
            {
            }

            public function all(?int $tenantId = null): array
            {
                return [];
            }
        };

        // TlsCertificateInventory is final. Point it at a directory that does not
        // exist so entries() legitimately returns an empty inventory.
        $inventory = new TlsCertificateInventory('/nonexistent/mailpanel-test-sni');

        $renderer = new EximConfigRenderer(
            $this->generatedRoot,
            $domainRepo,
            $mailboxRepo,
            $aliasRepo,
            $tenantRepo,
            '/etc/exim4/ssl/mailpanel.pem',
            '/etc/exim4/ssl/mailpanel.key',
            '25 : 465 : 587',
            '465',
            $inventory,
            $groupRepo,
            null,
            null,
            $packageRepo,
            null,
        );

        return $renderer->render();
    }

    /**
     * @param array<string, mixed> $result
     */
    private function extra(array $result, string $filename): string
    {
        foreach ($result['extras'] ?? [] as $extra) {
            if (str_ends_with((string) $extra['path'], '/' . $filename)) {
                return (string) $extra['content'];
            }
        }

        $this->fail("Renderer did not emit {$filename}");
    }

    private function activeTenant(int $id = 3, int $packageId = 9): array
    {
        return ['id' => $id, 'status' => 'active', 'package_id' => $packageId];
    }

    private function activeDomain(int $id = 8, int $tenantId = 3, string $name = 'example.test'): array
    {
        return [
            'id' => $id,
            'tenant_id' => $tenantId,
            'domain' => $name,
            'status' => 'active',
            'inbound_enabled' => 1,
            'outbound_enabled' => 1,
        ];
    }

    private function activeMailbox(int $id = 5, string $email = 'admin@example.test'): array
    {
        return [
            'id' => $id,
            'tenant_id' => 3,
            'domain_id' => 8,
            'email' => $email,
            'status' => 'active',
            'smtp_enabled' => 1,
        ];
    }

    // ------------------------------------------------------------ local domains

    public function test_active_domain_is_treated_as_local(): void
    {
        $result = $this->render([$this->activeDomain()], [$this->activeMailbox()], [$this->activeTenant()]);

        $this->assertSame('exim', $result['service']);
        $this->assertStringContainsString('example.test', $this->extra($result, 'local_domains.map'));
    }

    public function test_domain_with_inbound_disabled_is_not_local(): void
    {
        $domain = $this->activeDomain();
        $domain['inbound_enabled'] = 0;

        $result = $this->render([$domain], [$this->activeMailbox()], [$this->activeTenant()]);

        $this->assertStringNotContainsString('example.test', $this->extra($result, 'local_domains.map'));
    }

    public function test_domain_of_suspended_tenant_is_not_local(): void
    {
        $tenant = $this->activeTenant();
        $tenant['status'] = 'suspended';

        $result = $this->render([$this->activeDomain()], [$this->activeMailbox()], [$tenant]);

        $this->assertStringNotContainsString('example.test', $this->extra($result, 'local_domains.map'));
    }

    // ---------------------------------------------------------- outbound policy

    public function test_active_mailbox_may_submit(): void
    {
        $result = $this->render([$this->activeDomain()], [$this->activeMailbox()], [$this->activeTenant()]);

        $this->assertStringContainsString('admin@example.test:1', $this->extra($result, 'smtp_submit_enabled.map'));
    }

    public function test_mailbox_with_smtp_disabled_may_not_submit(): void
    {
        $mailbox = $this->activeMailbox();
        $mailbox['smtp_enabled'] = 0;

        $result = $this->render([$this->activeDomain()], [$mailbox], [$this->activeTenant()]);

        $this->assertStringNotContainsString('admin@example.test', $this->extra($result, 'smtp_submit_enabled.map'));
    }

    public function test_suspended_mailbox_may_neither_send_nor_receive(): void
    {
        $mailbox = $this->activeMailbox();
        $mailbox['status'] = 'suspended';

        $result = $this->render([$this->activeDomain()], [$mailbox], [$this->activeTenant()]);

        $this->assertStringNotContainsString('admin@example.test', $this->extra($result, 'smtp_submit_enabled.map'));
        $this->assertStringNotContainsString('admin@example.test', $this->extra($result, 'mailboxes.map'));
    }

    public function test_mailbox_can_still_receive_when_outbound_is_disabled(): void
    {
        $domain = $this->activeDomain();
        $domain['outbound_enabled'] = 0;

        $result = $this->render([$domain], [$this->activeMailbox()], [$this->activeTenant()]);

        $this->assertStringNotContainsString('admin@example.test', $this->extra($result, 'smtp_submit_enabled.map'));
        $this->assertStringContainsString('admin@example.test:1', $this->extra($result, 'mailboxes.map'));
    }

    public function test_mailbox_without_a_known_tenant_is_dropped(): void
    {
        $result = $this->render([$this->activeDomain()], [$this->activeMailbox()], []);

        $this->assertStringNotContainsString('admin@example.test', $this->extra($result, 'mailboxes.map'));
    }

    // ------------------------------------------------------------- rate limits

    public function test_package_limits_drive_the_generated_rate_limit_maps(): void
    {
        $result = $this->render(
            [$this->activeDomain()],
            [$this->activeMailbox()],
            [$this->activeTenant()],
            [],
            [['id' => 9, 'outbound_per_hour' => 120, 'outbound_per_day' => 1200, 'max_message_size_mb' => 50]]
        );

        $this->assertStringContainsString('admin@example.test:120', $this->extra($result, 'outbound_mailbox_hourly.map'));
        $this->assertStringContainsString('admin@example.test:1200', $this->extra($result, 'outbound_mailbox_daily.map'));
        $this->assertStringContainsString(
            'admin@example.test:' . (50 * 1024 * 1024),
            $this->extra($result, 'message_size_limit.map')
        );
    }

    public function test_domain_limit_takes_the_highest_mailbox_limit(): void
    {
        $result = $this->render(
            [$this->activeDomain()],
            [
                $this->activeMailbox(5, 'a@example.test'),
                $this->activeMailbox(6, 'b@example.test'),
            ],
            [$this->activeTenant()],
            [],
            [['id' => 9, 'outbound_per_hour' => 70, 'outbound_per_day' => 700, 'max_message_size_mb' => 10]]
        );

        $this->assertStringContainsString('example.test:70', $this->extra($result, 'outbound_domain_hourly.map'));
    }

    // ------------------------------------------------------- injection defence

    public function test_alias_with_newline_injection_is_dropped_from_the_map(): void
    {
        $result = $this->render(
            [$this->activeDomain()],
            [$this->activeMailbox()],
            [$this->activeTenant()],
            [
                ['source_address' => 'sales@example.test', 'destination_mailbox_id' => 5],
                ['source_address' => "evil@example.test\ninjected:1", 'destination_mailbox_id' => 5],
            ]
        );

        $map = $this->extra($result, 'allowed_senders.map');

        $this->assertStringContainsString('sales@example.test:admin@example.test', $map);
        $this->assertStringNotContainsString('injected:1', $map);
    }

    public function test_alias_containing_a_colon_is_dropped(): void
    {
        $result = $this->render(
            [$this->activeDomain()],
            [$this->activeMailbox()],
            [$this->activeTenant()],
            [['source_address' => 'a:b@example.test', 'destination_mailbox_id' => 5]]
        );

        $this->assertStringNotContainsString('a:b@example.test', $this->extra($result, 'allowed_senders.map'));
    }

    public function test_alias_pointing_at_an_unknown_mailbox_is_dropped(): void
    {
        $result = $this->render(
            [$this->activeDomain()],
            [$this->activeMailbox()],
            [$this->activeTenant()],
            [['source_address' => 'ghost@example.test', 'destination_mailbox_id' => 4242]]
        );

        $this->assertStringNotContainsString('ghost@example.test', $this->extra($result, 'allowed_senders.map'));
    }

    // ------------------------------------------------------ constructor guards

    public function test_invalid_tls_certificate_path_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EximConfigRenderer(
            $this->generatedRoot,
            new class extends DomainRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            new class extends MailboxRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            new class extends AliasRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            new class extends TenantRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            "/etc/exim4/ssl/mailpanel.pem\nfoo",
        ))->render();
    }

    public function test_invalid_submission_port_list_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new EximConfigRenderer(
            $this->generatedRoot,
            new class extends DomainRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            new class extends MailboxRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            new class extends AliasRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            new class extends TenantRepository {
                public function __construct()
                {
                }

                public function all(?int $tenantId = null): array
                {
                    return [];
                }
            },
            '/etc/exim4/ssl/mailpanel.pem',
            '/etc/exim4/ssl/mailpanel.key',
            '25 : 99999',
        ))->render();
    }

    // ------------------------------------------------------------- empty state

    public function test_empty_installation_still_produces_a_valid_domain_list(): void
    {
        $result = $this->render([], [], []);

        // Never emit an empty local domain list: Exim would treat every domain as
        // remote and the box could act as an open relay.
        $this->assertStringContainsString('localhost', $result['content']);
    }
}
