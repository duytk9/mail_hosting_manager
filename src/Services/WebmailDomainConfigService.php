<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Support\Validator;
use MailPanel\Support\SafePath;

final class WebmailDomainConfigService
{
    private const MANIFEST_FILE = '.mailpanel-managed.json';

    private readonly string $webmailRoot;

    public function __construct(
        private readonly bool $enabled,
        string $webmailRoot,
        private readonly int $imapPort = 993,
        private readonly int $smtpPort = 465,
        private readonly int $sievePort = 4190,
        private readonly bool $sieveEnabled = true,
    ) {
        $this->webmailRoot = SafePath::absolute($webmailRoot, 'webmail root');
    }

    /**
     * @param array<int, array<string, mixed>> $domains
     */
    public function syncManagedDomains(array $domains): void
    {
        if (!$this->enabled) {
            return;
        }

        $domainsRoot = $this->domainsRoot();
        if (!is_dir($domainsRoot)) {
            return;
        }

        $managedDomains = [];
        foreach ($domains as $domain) {
            $name = strtolower(trim((string) ($domain['domain'] ?? '')));
            if ($name === '') {
                continue;
            }

            Validator::fqdn($name);
            $managedDomains[] = $name;
            $this->writeDomainConfig($domainsRoot, $name, false, $this->sieveEnabled);
        }

        sort($managedDomains);

        foreach (array_diff($this->readManifest($domainsRoot), $managedDomains) as $removedDomain) {
            $path = $this->domainConfigPath($domainsRoot, $removedDomain);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->writeDomainConfig($domainsRoot, 'default', false, $this->sieveEnabled, true);
        $this->writeManifest($domainsRoot, $managedDomains);
    }

    private function domainsRoot(): string
    {
        return rtrim($this->webmailRoot, '/\\') . '/data/_data_/_default_/domains';
    }

    private function domainConfigPath(string $domainsRoot, string $domain): string
    {
        return $domainsRoot . '/' . $domain . '.json';
    }

    /**
     * @return array<int, string>
     */
    private function readManifest(string $domainsRoot): array
    {
        $path = $domainsRoot . '/' . self::MANIFEST_FILE;
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => strtolower(trim((string) $item)), $decoded),
            fn (string $item): bool => $this->isManagedDomainName($item)
        ));
    }

    /**
     * @param array<int, string> $managedDomains
     */
    private function writeManifest(string $domainsRoot, array $managedDomains): void
    {
        $this->writeAtomic(
            $domainsRoot . '/' . self::MANIFEST_FILE,
            json_encode(array_values($managedDomains), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function writeDomainConfig(
        string $domainsRoot,
        string $domain,
        bool $shortLogin,
        bool $sieveEnabled,
        bool $isDefault = false,
    ): void {
        $payload = [
            '_mailpanel' => [
                'managed' => true,
                'domain' => $domain,
            ],
            'IMAP' => [
                'host' => $isDefault ? 'localhost' : 'mail.' . $domain,
                'port' => $this->imapPort,
                'type' => 1,
                'timeout' => 300,
                'shortLogin' => $shortLogin,
                'lowerLogin' => true,
                'sasl' => [
                    'SCRAM-SHA3-512',
                    'SCRAM-SHA-512',
                    'SCRAM-SHA-256',
                    'SCRAM-SHA-1',
                    'PLAIN',
                    'LOGIN',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'SNI_enabled' => true,
                    'disable_compression' => true,
                    'security_level' => 1,
                ],
                'disabled_capabilities' => [
                    'METADATA',
                    'OBJECTID',
                    'PREVIEW',
                    'STATUS=SIZE',
                ],
                'use_expunge_all_on_delete' => false,
                'fast_simple_search' => true,
                'force_select' => false,
                'message_all_headers' => false,
                'message_list_limit' => 10000,
                'search_filter' => '',
            ],
            'SMTP' => [
                'host' => $isDefault ? 'localhost' : 'mail.' . $domain,
                'port' => $this->smtpPort,
                'type' => 1,
                'timeout' => 60,
                'shortLogin' => $shortLogin,
                'lowerLogin' => true,
                'sasl' => [
                    'SCRAM-SHA3-512',
                    'SCRAM-SHA-512',
                    'SCRAM-SHA-256',
                    'SCRAM-SHA-1',
                    'PLAIN',
                    'LOGIN',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'SNI_enabled' => true,
                    'disable_compression' => true,
                    'security_level' => 1,
                ],
                'useAuth' => true,
                'setSender' => false,
                'usePhpMail' => false,
            ],
            'Sieve' => [
                'host' => $isDefault ? 'localhost' : 'mail.' . $domain,
                'port' => $this->sievePort,
                'type' => 0,
                'timeout' => 10,
                'shortLogin' => $shortLogin,
                'lowerLogin' => true,
                'sasl' => [
                    'SCRAM-SHA3-512',
                    'SCRAM-SHA-512',
                    'SCRAM-SHA-256',
                    'SCRAM-SHA-1',
                    'PLAIN',
                    'LOGIN',
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                    'SNI_enabled' => true,
                    'disable_compression' => true,
                    'security_level' => 1,
                ],
                'enabled' => $sieveEnabled,
            ],
            'whiteList' => '',
        ];

        $targetPath = $this->domainConfigPath($domainsRoot, $isDefault ? 'default' : $domain);
        $this->writeAtomic($targetPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    private function isManagedDomainName(string $domain): bool
    {
        if ($domain === 'default') {
            return true;
        }

        try {
            Validator::fqdn($domain);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    private function writeAtomic(string $path, string|false $contents): void
    {
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to encode webmail domain config.');
        }

        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write webmail domain config.');
        }

        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish webmail domain config.');
        }
    }
}
