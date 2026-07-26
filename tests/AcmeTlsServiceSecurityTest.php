<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\AcmeTlsService;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\TlsCertificateInventory;
use PHPUnit\Framework\TestCase;

final class AcmeTlsServiceSecurityTest extends TestCase
{
    public function test_acme_failure_audit_log_does_not_store_raw_executor_exception(): void
    {
        $auditLog = new class extends AuditLogService {
            /** @var array<int, array<string, mixed>> */
            public array $entries = [];

            public function __construct() {}

            public function log(array $entry): void
            {
                $this->entries[] = $entry;
            }
        };
        $service = new AcmeTlsService(
            $auditLog,
            new TlsCertificateInventory(sys_get_temp_dir() . '/missing-sni-' . bin2hex(random_bytes(4))),
            '/opt/mailpanel',
            static fn (array $payload): array => throw new \RuntimeException('certbot failed password=Secret123 /etc/letsencrypt/live/example.test/privkey.pem'),
            static fn (string $hostname, string $type): array => [['ip' => '127.0.0.1']]
        );

        try {
            $service->issue(
                ['id' => 10, 'tenant_id' => 20, 'domain' => 'example.test'],
                ['email' => 'ops@example.test', 'profile' => 'mail_only'],
                ['actor_id' => 7, 'actor_role' => 'super_admin']
            );
            self::fail('ACME executor failure was expected.');
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $auditLog->entries);
        $newValues = $auditLog->entries[0]['new_values'];
        self::assertSame('ACME operation failed; check service logs.', $newValues['error']);
        self::assertStringNotContainsString('Secret123', json_encode($auditLog->entries, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/etc/letsencrypt', json_encode($auditLog->entries, JSON_THROW_ON_ERROR));
    }
}
