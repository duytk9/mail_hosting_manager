<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\DnsCheckService;
use PHPUnit\Framework\TestCase;

final class DnsCheckServiceTest extends TestCase
{
    public function test_it_builds_dns_report_for_domain(): void
    {
        $service = new DnsCheckService('mail', 'mail', static function (string $hostname, string $type): array {
            return match ($hostname . '|' . $type) {
                'example.test|MX' => [['target' => 'mail.example.test', 'pri' => 10]],
                'example.test|TXT' => [['txt' => 'v=spf1 mx -all']],
                '_dmarc.example.test|TXT' => [['txt' => 'v=DMARC1; p=quarantine']],
                'mail._domainkey.example.test|TXT' => [['txt' => 'v=DKIM1; k=rsa; p=abc123']],
                'mail.example.test|A' => [['ip' => '161.248.4.210']],
                default => [],
            };
        });

        $report = $service->inspectDomain([
            'id' => 1,
            'domain' => 'example.test',
            'dkim_enabled' => 1,
        ]);

        $this->assertSame('mail.example.test', $report['mail_host']);
        $this->assertSame('mail._domainkey.example.test', $report['dkim_host']);
        $this->assertSame(5, $report['summary']['total']);
        $this->assertSame(5, $report['summary']['ok']);
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertSame('ok', $report['checks'][0]['status']);
        $this->assertStringContainsString('161.248.4.210', $report['checks'][4]['observed']);
    }

    public function test_it_skips_dkim_when_domain_dkim_is_disabled(): void
    {
        $service = new DnsCheckService('mail', 'mail', static fn (string $hostname, string $type): array => []);

        $report = $service->inspectDomain([
            'id' => 1,
            'domain' => 'example.test',
            'dkim_enabled' => 0,
        ]);

        $this->assertSame('skipped', $report['checks'][3]['status']);
        $this->assertSame(1, $report['summary']['skipped']);
    }
}
