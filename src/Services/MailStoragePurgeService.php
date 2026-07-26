<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\MailStoragePurger;
use MailPanel\Support\Validator;
use RuntimeException;

final class MailStoragePurgeService implements MailStoragePurger
{
    public function __construct(
        private readonly AgentClientService $agentClient,
        private readonly string $vmailRoot
    ) {
    }

    public function purgeMailbox(string $email): array
    {
        $email = strtolower(trim($email));
        $this->assertMailboxEmail($email);

        return $this->checkedResult($this->agentClient->manageMailStorage([
            'action' => 'purge-mailbox',
            'vmail_root' => $this->vmailRoot,
            'email' => $email,
        ]));
    }

    public function purgeDomain(string $domain): array
    {
        $domain = strtolower(trim($domain));
        Validator::fqdn($domain);

        return $this->checkedResult($this->agentClient->manageMailStorage([
            'action' => 'purge-domain',
            'vmail_root' => $this->vmailRoot,
            'domain' => $domain,
        ]));
    }

    public function purgeMailboxes(array $emails): array
    {
        $results = [];

        foreach (array_values(array_unique($emails)) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }

            $results[] = $this->purgeMailbox($email);
        }

        return $results;
    }

    private function assertMailboxEmail(string $email): void
    {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Mailbox email is invalid.');
        }

        [$localPart, $domain] = explode('@', $email, 2);
        Validator::localPart($localPart);
        Validator::fqdn($domain);
    }

    private function checkedResult(array $result): array
    {
        $commandResult = $result['result'] ?? [];
        if ((int) ($commandResult['returncode'] ?? 1) !== 0) {
            throw new RuntimeException('Mail storage purge failed.');
        }

        return $result;
    }
}
