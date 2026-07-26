<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Contracts\SuperAdminLinuxAccountManager;

final class AdminPasswordVerifier
{
    public function __construct(
        private readonly PasswordHashingService $passwordHasher,
        private readonly array $appConfig = [],
        private readonly ?SuperAdminLinuxAccountManager $linuxAccounts = null
    ) {
    }

    public function verify(array $user, string $password): array
    {
        $mode = $this->adminAuthMode();
        $linuxUsername = trim((string) ($user['linux_username'] ?? ''));
        $panelHash = (string) ($user['password_hash'] ?? '');

        if ($mode === 'linux') {
            if ($linuxUsername === '') {
                return [
                    'verified' => $this->passwordHasher->verify($password, $panelHash),
                    'source' => 'panel',
                    'migrated' => false,
                ];
            }

            return [
                'verified' => $this->verifyLinuxPassword($linuxUsername, $password),
                'source' => 'linux',
                'migrated' => false,
            ];
        }

        if ($this->passwordHasher->verify($password, $panelHash)) {
            return [
                'verified' => true,
                'source' => 'panel',
                'migrated' => false,
            ];
        }

        if ($mode === 'hybrid' && $linuxUsername !== '' && $this->verifyLinuxPassword($linuxUsername, $password)) {
            return [
                'verified' => true,
                'source' => 'linux',
                'migrated' => true,
            ];
        }

        return [
            'verified' => false,
            'source' => null,
            'migrated' => false,
        ];
    }

    private function verifyLinuxPassword(string $linuxUsername, string $password): bool
    {
        if ($this->linuxAccounts === null) {
            return false;
        }

        try {
            return $this->linuxAccounts->verifyPassword($linuxUsername, $password);
        } catch (\Throwable) {
            return false;
        }
    }

    private function adminAuthMode(): string
    {
        $mode = strtolower(trim((string) ($this->appConfig['admin_auth']['mode'] ?? 'hybrid')));

        return in_array($mode, ['panel', 'hybrid', 'linux'], true)
            ? $mode
            : 'hybrid';
    }
}
