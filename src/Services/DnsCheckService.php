<?php

declare(strict_types=1);

namespace MailPanel\Services;

final class DnsCheckService
{
    /** @var callable */
    private $resolver;

    public function __construct(
        private readonly string $dkimSelector = 'mail',
        private readonly string $mailHostLabel = 'mail',
        ?callable $resolver = null
    ) {
        $this->resolver = $resolver ?? static function (string $hostname, string $type): array {
            $dnsType = match ($type) {
                'MX' => DNS_MX,
                'TXT' => DNS_TXT,
                'A' => DNS_A,
                'AAAA' => defined('DNS_AAAA') ? DNS_AAAA : 0,
                default => 0,
            };

            if ($dnsType === 0) {
                return [];
            }

            $records = @dns_get_record($hostname, $dnsType);

            return is_array($records) ? $records : [];
        };
    }

    public function inspectDomain(array $domain): array
    {
        $domainName = strtolower(trim((string) ($domain['domain'] ?? '')));
        $mailHost = sprintf('%s.%s', $this->mailHostLabel, $domainName);
        $dkimHost = sprintf('%s._domainkey.%s', $this->dkimSelector, $domainName);

        $checks = [
            $this->buildMxCheck($domainName, $this->resolve($domainName, 'MX'), $mailHost),
            $this->buildSpfCheck($domainName, $this->resolveTxtValues($domainName), $mailHost),
            $this->buildTxtPrefixCheck('dmarc', 'DMARC', '_dmarc.' . $domainName, $this->resolveTxtValues('_dmarc.' . $domainName), 'v=DMARC1', 'Publish a DMARC TXT record.'),
            $this->buildDkimCheck($domain, $dkimHost),
            $this->buildMailHostCheck($mailHost, array_merge($this->resolve($mailHost, 'A'), $this->resolve($mailHost, 'AAAA'))),
        ];

        return [
            'domain' => $domainName,
            'mail_host' => $mailHost,
            'dkim_selector' => $this->dkimSelector,
            'dkim_host' => $dkimHost,
            'checks' => $checks,
            'summary' => [
                'total' => count($checks),
                'ok' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'ok')),
                'failed' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'failed')),
                'skipped' => count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'skipped')),
            ],
            'checked_at' => gmdate('c'),
        ];
    }

    private function buildMxCheck(string $domainName, array $records, string $mailHost): array
    {
        $observed = [];
        $hasMailHost = false;

        foreach ($records as $record) {
            $priority = isset($record['pri']) ? (int) $record['pri'] . ' ' : '';
            $target = strtolower(trim((string) ($record['target'] ?? '')));

            if ($target !== '') {
                $observed[] = trim($priority . $target);
                // MX target could have a trailing dot, so we trim it for comparison
                $targetClean = rtrim($target, '.');
                if ($targetClean === $mailHost) {
                    $hasMailHost = true;
                }
            }
        }

        return [
            'key' => 'mx',
            'label' => 'MX',
            'hostname' => $domainName,
            'status' => $hasMailHost ? 'ok' : 'failed',
            'expected' => sprintf('At least one MX record pointing to %s.', $mailHost),
            'observed' => $this->formatObserved($observed),
        ];
    }

    private function buildTxtPrefixCheck(string $key, string $label, string $hostname, array $records, string $prefix, string $expected): array
    {
        $matches = array_values(array_filter(
            $records,
            static fn (string $record): bool => str_starts_with(strtoupper($record), strtoupper($prefix))
        ));

        return [
            'key' => $key,
            'label' => $label,
            'hostname' => $hostname,
            'status' => $matches === [] ? 'failed' : 'ok',
            'expected' => $expected,
            'observed' => $this->formatObserved($matches !== [] ? $matches : $records),
        ];
    }

    private function buildDkimCheck(array $domain, string $hostname): array
    {
        if (empty($domain['dkim_enabled'])) {
            return [
                'key' => 'dkim',
                'label' => 'DKIM',
                'hostname' => $hostname,
                'status' => 'skipped',
                'expected' => 'Enable DKIM for this domain to validate selector records.',
                'observed' => 'DKIM disabled on domain.',
            ];
        }

        return $this->buildTxtPrefixCheck(
            'dkim',
            'DKIM',
            $hostname,
            $this->resolveTxtValues($hostname),
            'v=DKIM1',
            sprintf('Publish DKIM TXT record for selector %s.', $this->dkimSelector)
        );
    }

    private function buildMailHostCheck(string $hostname, array $records): array
    {
        $observed = [];

        foreach ($records as $record) {
            $address = trim((string) ($record['ip'] ?? $record['ipv6'] ?? ''));

            if ($address !== '') {
                $observed[] = $address;
            }
        }

        return [
            'key' => 'mail_host',
            'label' => 'Mail Host',
            'hostname' => $hostname,
            'status' => $observed === [] ? 'failed' : 'ok',
            'expected' => 'Resolve the mail host with A or AAAA.',
            'observed' => $this->formatObserved($observed),
        ];
    }

    private function resolve(string $hostname, string $type): array
    {
        return array_values(array_filter(
            (($this->resolver)($hostname, $type) ?: []),
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    /**
     * @return array<int, string>
     */
    private function resolveTxtValues(string $hostname): array
    {
        $values = [];

        foreach ($this->resolve($hostname, 'TXT') as $record) {
            $value = trim((string) ($record['txt'] ?? $record['entries'][0] ?? ''));

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<int, string> $observed
     */
    private function formatObserved(array $observed): string
    {
        return $observed === [] ? 'No matching record found.' : implode("\n", $observed);
    }

    private function buildSpfCheck(string $hostname, array $records, string $mailHost): array
    {
        $prefix = 'v=spf1';
        $matches = array_values(array_filter(
            $records,
            static fn (string $record): bool => str_starts_with(strtoupper($record), strtoupper($prefix))
        ));

        $status = 'failed';
        if ($matches !== []) {
            foreach ($matches as $match) {
                $matchLower = strtolower($match);
                // Valid if it contains 'mx', or explicitly includes the mail host
                if (str_contains($matchLower, 'mx') || str_contains($matchLower, $mailHost)) {
                    $status = 'ok';
                    break;
                }
            }
        }

        return [
            'key' => 'spf',
            'label' => 'SPF',
            'hostname' => $hostname,
            'status' => $status,
            'expected' => sprintf('Publish an SPF TXT record containing "mx" or "%s".', $mailHost),
            'observed' => $this->formatObserved($matches !== [] ? $matches : $records),
        ];
    }
}
