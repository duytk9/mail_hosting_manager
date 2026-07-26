<?php

declare(strict_types=1);

namespace MailPanel\Services;

use InvalidArgumentException;
use MailPanel\Contracts\MailStorageProvisioner;
use MailPanel\Support\Validator;
use RuntimeException;

final class MailStorageProvisionService implements MailStorageProvisioner
{
    public function __construct(
        private readonly AgentClientService $agentClient,
        private readonly string $vmailRoot,
        private readonly int $vmailUid,
        private readonly int $vmailGid,
    ) {
    }

    public function provisionMailboxDefaults(string $email): array
    {
        $email = strtolower(trim($email));
        $this->assertMailboxEmail($email);

        return $this->checkedResult($this->agentClient->manageMailStorage([
            'action' => 'provision-mailbox-defaults',
            'vmail_root' => $this->vmailRoot,
            'vmail_uid' => $this->vmailUid,
            'vmail_gid' => $this->vmailGid,
            'email' => $email,
        ]));
    }

    public function provisionMailboxDefaultsBulk(array $emails): array
    {
        $results = [];

        foreach (array_values(array_unique($emails)) as $email) {
            $email = strtolower(trim((string) $email));
            if ($email === '') {
                continue;
            }

            $results[] = $this->provisionMailboxDefaults($email);
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
            throw new RuntimeException('Mail storage bootstrap failed.');
        }

        return $result;
    }
}
