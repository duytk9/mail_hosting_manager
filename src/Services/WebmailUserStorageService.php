<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Support\Validator;
use MailPanel\Support\SafePath;

final class WebmailUserStorageService
{
    private readonly string $webmailRoot;

    public function __construct(
        private readonly bool $enabled,
        string $webmailRoot,
    ) {
        $this->webmailRoot = SafePath::absolute($webmailRoot, 'webmail root');
    }

    /**
     * @return array{enabled:bool,storage_path:string,settings_path:string,settings_local_path:string,identities_path:string,updated:bool}
     */
    public function bootstrapMailbox(string $email, ?string $displayName = null): array
    {
        [$domain, $localPart] = $this->splitEmail($email);
        $storagePath = $this->mailboxStoragePath($domain, $localPart);
        $settingsPath = $storagePath . '/settings';
        $settingsLocalPath = $storagePath . '/settings_local';
        $identitiesPath = $storagePath . '/identities';

        if (!$this->enabled) {
            return [
                'enabled' => false,
                'storage_path' => $storagePath,
                'settings_path' => $settingsPath,
                'settings_local_path' => $settingsLocalPath,
                'identities_path' => $identitiesPath,
                'updated' => false,
            ];
        }

        $this->assertSafeMailboxPath($storagePath);

        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0775, true) && !is_dir($storagePath)) {
                throw new \RuntimeException('Unable to create webmail mailbox storage.');
            }
        }

        $updated = false;
        $updated = $this->mergeJsonFile($settingsPath, [
            'ContactsAutosave' => true,
        ]) || $updated;
        $updated = $this->mergeJsonFile($settingsLocalPath, [
            'SentFolder' => 'Sent',
            'DraftsFolder' => 'Drafts',
            'JunkFolder' => 'Junk',
            'TrashFolder' => 'Trash',
            'ArchiveFolder' => 'Archive',
            'CheckableFolder' => '[]',
            'threadAlgorithm' => 'REFS',
            'ShowUnreadCount' => true,
        ]) || $updated;
        $updated = $this->mergeJsonFile($identitiesPath, [
            '---' => [
                'Id' => '',
                'Label' => '',
                'Email' => $localPart . '@' . $domain,
                'Name' => trim((string) $displayName),
                'ReplyTo' => '',
                'Bcc' => '',
                'Signature' => '',
                'SignatureInsertBefore' => false,
                'sentFolder' => '',
                'pgpEncrypt' => false,
                'pgpSign' => false,
                'smimeKey' => '',
                'smimeCertificate' => '',
            ],
        ]) || $updated;

        return [
            'enabled' => true,
            'storage_path' => $storagePath,
            'settings_path' => $settingsPath,
            'settings_local_path' => $settingsLocalPath,
            'identities_path' => $identitiesPath,
            'updated' => $updated,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $mailboxes
     * @return array{enabled:bool,total:int,updated:int,unchanged:int,items:array<int, array<string, mixed>>}
     */
    public function bootstrapMailboxes(array $mailboxes): array
    {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'total' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'items' => [],
            ];
        }

        $items = [];
        $updated = 0;
        $unchanged = 0;

        foreach ($mailboxes as $mailbox) {
            $email = strtolower(trim((string) ($mailbox['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            $result = $this->bootstrapMailbox($email, (string) ($mailbox['display_name'] ?? ''));
            $items[] = [
                'email' => $email,
                'updated' => (bool) ($result['updated'] ?? false),
                'settings_local_path' => (string) ($result['settings_local_path'] ?? ''),
            ];

            if (!empty($result['updated'])) {
                $updated++;
            } else {
                $unchanged++;
            }
        }

        return [
            'enabled' => true,
            'total' => count($items),
            'updated' => $updated,
            'unchanged' => $unchanged,
            'items' => $items,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $mailboxes
     * @return array{enabled:bool,total:int,ready:int,missing:int,items:array<int, array<string, mixed>>}
     */
    public function mailboxCoverage(array $mailboxes): array
    {
        if (!$this->enabled) {
            return [
                'enabled' => false,
                'total' => 0,
                'ready' => 0,
                'missing' => 0,
                'items' => [],
            ];
        }

        $items = [];
        $ready = 0;
        $missing = 0;

        foreach ($mailboxes as $mailbox) {
            $email = strtolower(trim((string) ($mailbox['email'] ?? '')));
            if ($email === '') {
                continue;
            }

            [$domain, $localPart] = $this->splitEmail($email);
            $storagePath = $this->mailboxStoragePath($domain, $localPart);
            $settingsPath = $storagePath . '/settings';
            $settingsLocalPath = $storagePath . '/settings_local';
            $identitiesPath = $storagePath . '/identities';
            $isReady = is_file($settingsPath) && is_file($settingsLocalPath) && is_file($identitiesPath);

            $items[] = [
                'email' => $email,
                'storage_path' => $storagePath,
                'ready' => $isReady,
            ];

            if ($isReady) {
                $ready++;
            } else {
                $missing++;
            }
        }

        return [
            'enabled' => true,
            'total' => count($items),
            'ready' => $ready,
            'missing' => $missing,
            'items' => $items,
        ];
    }

    /**
     * @return array{enabled:bool,storage_path:string,purged:bool}
     */
    public function purgeMailbox(string $email): array
    {
        [$domain, $localPart] = $this->splitEmail($email);
        $storagePath = $this->mailboxStoragePath($domain, $localPart);

        if (!$this->enabled) {
            return [
                'enabled' => false,
                'storage_path' => $storagePath,
                'purged' => false,
            ];
        }

        if (is_link($storagePath)) {
            if (!@unlink($storagePath) && is_link($storagePath)) {
                @rmdir($storagePath);
            }

            return [
                'enabled' => true,
                'storage_path' => $storagePath,
                'purged' => true,
            ];
        }

        if (!is_dir($storagePath)) {
            return [
                'enabled' => true,
                'storage_path' => $storagePath,
                'purged' => false,
            ];
        }

        $this->deleteDirectory($storagePath);

        return [
            'enabled' => true,
            'storage_path' => $storagePath,
            'purged' => true,
        ];
    }

    private function mailboxStoragePath(string $domain, string $localPart): string
    {
        return rtrim($this->webmailRoot, '/\\') . '/data/_data_/_default_/storage/' . $domain . '/' . $localPart;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Mailbox email is invalid.');
        }

        [$localPart, $domain] = explode('@', $email, 2);
        Validator::localPart($localPart);
        Validator::fqdn($domain);

        return [$domain, $localPart];
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function mergeJsonFile(string $path, array $defaults): bool
    {
        $current = [];
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded)) {
                $current = $decoded;
            }
        }

        $merged = $this->mergeDistinct($current, $defaults);
        if ($merged === $current) {
            return false;
        }

        $encoded = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('Unable to encode webmail mailbox settings.');
        }

        $this->writeAtomic($path, $encoded . "\n");

        return true;
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write webmail mailbox settings.');
        }

        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish webmail mailbox settings.');
        }
    }

    private function assertSafeMailboxPath(string $path): void
    {
        $root = rtrim($this->webmailRoot, '/\\');
        $relative = substr($path, strlen($root));
        $segments = array_values(array_filter(
            preg_split('#[\\\\/]+#', $relative) ?: [],
            static fn (string $segment): bool => $segment !== ''
        ));

        $current = $root;
        foreach ($segments as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($current)) {
                throw new \RuntimeException('Unsafe webmail mailbox storage symlink detected.');
            }
        }
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function mergeDistinct(array $current, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (is_array($value)) {
                $nestedCurrent = isset($current[$key]) && is_array($current[$key]) ? $current[$key] : [];
                $current[$key] = $this->mergeDistinct($nestedCurrent, $value);
                continue;
            }

            if (!array_key_exists($key, $current) || $current[$key] === '' || $current[$key] === null) {
                $current[$key] = $value;
            }
        }

        return $current;
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $childPath = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($childPath) && !is_link($childPath)) {
                $this->deleteDirectory($childPath);
                continue;
            }

            @unlink($childPath);
        }

        @rmdir($path);
    }
}
