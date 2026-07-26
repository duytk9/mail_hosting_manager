<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Contracts\SuperAdminLinuxAccountManager;

final class SuperAdminLinuxAccountService implements SuperAdminLinuxAccountManager
{
    public function __construct(
        private readonly AgentClientService $agentClient,
        private readonly LinuxPasswordHashService $passwordHasher
    ) {
    }

    public function syncAccount(
        string $linuxUsername,
        bool $sshEnabled,
        bool $sshSudoEnabled,
        ?string $sshPublicKey = null,
        ?string $password = null
    ): void {
        $payload = [
            'action' => 'provision',
            'linux_username' => $linuxUsername,
            'ssh_enabled' => $sshEnabled,
            'ssh_sudo_enabled' => $sshSudoEnabled,
            'ssh_public_key' => trim((string) ($sshPublicKey ?? '')),
        ];

        if ($password !== null && $password !== '') {
            $payload['linux_password_hash'] = $this->passwordHasher->hash($password);
        }

        $this->agentClient->manageSuperAdmin($payload);
    }

    public function revoke(string $linuxUsername): void
    {
        $this->agentClient->manageSuperAdmin([
            'action' => 'revoke',
            'linux_username' => $linuxUsername,
        ]);
    }

    public function purge(string $linuxUsername): void
    {
        $this->agentClient->manageSuperAdmin([
            'action' => 'purge',
            'linux_username' => $linuxUsername,
        ]);
    }

    public function verifyPassword(string $linuxUsername, string $password): bool
    {
        $result = $this->agentClient->manageSuperAdmin([
            'action' => 'verify-password',
            'linux_username' => $linuxUsername,
            'password' => $password,
        ]);

        return (bool) ($result['verified'] ?? false);
    }
}
