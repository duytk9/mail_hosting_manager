<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Repositories\Pdo\SpamPolicyRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use InvalidArgumentException;

class SpamPolicyService
{
    public function __construct(
        private readonly SpamPolicyRepository $spamPolicies,
        private readonly DomainRepository $domains,
        private readonly ?AgentClientService $agentClient = null,
        private readonly ?AuditLogService $auditLog = null
    ) {}

    public function getSpamPoliciesForTenant(int $tenantId): array
    {
        $domains = $this->domains->findAllByTenantId($tenantId);
        $policies = $this->spamPolicies->findByTenantId($tenantId);
        
        $policyMap = [];
        foreach ($policies as $policy) {
            if ($policy['domain_id']) {
                $policyMap[$policy['domain_id']] = $policy;
            }
        }

        $result = [];
        foreach ($domains as $domain) {
            $result[] = [
                'domain_id' => $domain['id'],
                'domain' => $domain['domain'],
                'allowlist_senders' => $policyMap[$domain['id']]['allowlist_senders'] ?? '',
                'blocklist_senders' => $policyMap[$domain['id']]['blocklist_senders'] ?? '',
            ];
        }

        return $result;
    }

    public function updatePolicy(int $tenantId, int $domainId, string $allowlist, string $blocklist): void
    {
        $domain = $this->domains->find($domainId);
        if (!$domain || (int) ($domain['tenant_id'] ?? 0) !== $tenantId) {
            throw new \RuntimeException('Domain not found or permission denied.');
        }

        $allowlistEntries = $this->normalizeList($allowlist, 'allowlist');
        $blocklistEntries = $this->normalizeList($blocklist, 'blocklist');
        $allowlist = implode("\n", $allowlistEntries);
        $blocklist = implode("\n", $blocklistEntries);

        $existing = $this->spamPolicies->findByDomainId($domainId);
        
        if ($existing) {
            $this->spamPolicies->updateListsByDomainId($domainId, $allowlist, $blocklist);
        } else {
            $this->spamPolicies->createForDomain($tenantId, $domainId, $allowlist, $blocklist);
        }

        $this->syncToAgent();

        $this->auditLog?->log([
            'action' => 'spam_policy.updated',
            'target_type' => 'domain',
            'target_id' => $domainId,
            'tenant_id' => $tenantId,
            'new_values' => [
                'allowlist_senders_count' => count($allowlistEntries),
                'blocklist_senders_count' => count($blocklistEntries),
            ],
        ]);
    }

    public function syncToAgent(): void
    {
        if ($this->agentClient === null) {
            return;
        }

        $allPolicies = $this->spamPolicies->findAll();
        $domainMap = [];

        foreach ($this->domains->all() as $domain) {
            $domainMap[(int) ($domain['id'] ?? 0)] = (string) ($domain['domain'] ?? '');
        }

        $jsonMap = ['domains' => []];
        foreach ($allPolicies as $policy) {
            if ($policy['domain_id'] && isset($domainMap[$policy['domain_id']])) {
                $domainName = strtolower(trim($domainMap[$policy['domain_id']]));
                
                $wl = $this->normalizeList((string) ($policy['allowlist_senders'] ?? ''), 'allowlist');
                $bl = $this->normalizeList((string) ($policy['blocklist_senders'] ?? ''), 'blocklist');
                
                if (!empty($wl) || !empty($bl)) {
                    $jsonMap['domains'][$domainName] = [
                        'wl_senders' => array_values($wl),
                        'bl_senders' => array_values($bl)
                    ];
                }
            }
        }

        $encoded = json_encode($jsonMap, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode spam policy map.');
        }

        $result = $this->agentClient->securitySystem([
            'action' => 'rspamd-sync-tenant-rules',
            'json_data' => $encoded,
        ]);

        if ((int) ($result['result']['returncode'] ?? 1) !== 0) {
            throw new \RuntimeException('Failed to sync tenant spam rules to Rspamd.');
        }
    }

    private function normalizeList(string $raw, string $label): array
    {
        if (strlen($raw) > 20000) {
            throw new InvalidArgumentException(sprintf('Spam policy %s is too large.', $label));
        }

        $entries = [];
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $entry = strtolower(trim($line));
            if ($entry === '') {
                continue;
            }

            if (str_contains($entry, "\0") || preg_match('/[\x00-\x1F\x7F]/', $entry) === 1) {
                throw new InvalidArgumentException(sprintf('Spam policy %s contains an invalid entry.', $label));
            }

            if (!$this->isValidSenderEntry($entry)) {
                throw new InvalidArgumentException(sprintf('Spam policy %s accepts only email addresses or @domain entries.', $label));
            }

            $entries[] = $entry;
        }

        $entries = array_values(array_unique($entries));
        if (count($entries) > 500) {
            throw new InvalidArgumentException(sprintf('Spam policy %s may contain at most 500 entries.', $label));
        }

        return $entries;
    }

    private function isValidSenderEntry(string $entry): bool
    {
        if (strlen($entry) > 254) {
            return false;
        }

        if (str_starts_with($entry, '@')) {
            return $this->isValidDomain(substr($entry, 1));
        }

        return filter_var($entry, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isValidDomain(string $domain): bool
    {
        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            $domain
        ) === 1;
    }
}
