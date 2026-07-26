<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

use MailPanel\Core\Database;
use PDO;

final class TenantPurgeRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findIncludingDeleted(int $tenantId): ?array
    {
        return $this->fetchOne('SELECT * FROM tenants WHERE id = :tenant_id', ['tenant_id' => $tenantId]);
    }

    public function findBySlugIncludingDeleted(string $slug): ?array
    {
        return $this->fetchOne('SELECT * FROM tenants WHERE slug = :slug ORDER BY id DESC LIMIT 1', ['slug' => $slug]);
    }

    public function deletedTenantIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $this->fetchAll('SELECT id FROM tenants WHERE deleted_at IS NOT NULL ORDER BY id ASC')
        );
    }

    public function mailboxEmailsForTenant(int $tenantId): array
    {
        return array_map(
            static fn (array $row): string => (string) ($row['email'] ?? ''),
            $this->fetchAll('SELECT email FROM mailboxes WHERE tenant_id = :tenant_id ORDER BY id ASC', ['tenant_id' => $tenantId])
        );
    }

    public function domainNamesForTenant(int $tenantId): array
    {
        return array_map(
            static fn (array $row): string => (string) ($row['domain'] ?? ''),
            $this->fetchAll('SELECT domain FROM domains WHERE tenant_id = :tenant_id ORDER BY id ASC', ['tenant_id' => $tenantId])
        );
    }

    public function purgeTenant(int $tenantId, bool $dryRun = false): array
    {
        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $tenant = $this->findIncludingDeleted($tenantId);
            if ($tenant === null) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }

                return [
                    'tenant_id' => $tenantId,
                    'found' => false,
                    'dry_run' => $dryRun,
                    'deleted' => [],
                ];
            }

            $deleted = [];
            foreach ($this->deleteStatements() as $table => $sql) {
                if (!$this->tableExists($table)) {
                    $deleted[$table] = 0;
                    continue;
                }

                $deleted[$table] = $this->executeCount($sql, array_fill(0, substr_count($sql, '?'), $tenantId));
            }

            if ($dryRun) {
                if ($startedTransaction) {
                    $pdo->rollBack();
                }
            } elseif ($startedTransaction) {
                $pdo->commit();
            }

            return [
                'tenant_id' => $tenantId,
                'found' => true,
                'dry_run' => $dryRun,
                'tenant' => $this->redactTenant($tenant),
                'deleted' => $deleted,
            ];
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    public function purgeDomain(int $domainId, bool $dryRun = false): array
    {
        return $this->withTransaction($dryRun, function () use ($domainId, $dryRun): array {
            $domain = $this->fetchOne('SELECT * FROM domains WHERE id = :id', ['id' => $domainId]);
            if ($domain === null) {
                return $this->notFound('domain_id', $domainId, $dryRun);
            }

            $deleted = [];
            foreach ($this->domainDeleteStatements() as $table => $sql) {
                if (!$this->tableExists($table)) {
                    $deleted[$table] = 0;
                    continue;
                }

                $deleted[$table] = $this->executeCount($sql, array_fill(0, substr_count($sql, '?'), $domainId));
            }

            return [
                'domain_id' => $domainId,
                'found' => true,
                'dry_run' => $dryRun,
                'domain' => [
                    'id' => (int) ($domain['id'] ?? $domainId),
                    'tenant_id' => isset($domain['tenant_id']) ? (int) $domain['tenant_id'] : null,
                    'domain' => $domain['domain'] ?? null,
                    'deleted_at' => $domain['deleted_at'] ?? null,
                ],
                'deleted' => $deleted,
            ];
        });
    }

    public function purgeMailbox(int $mailboxId, bool $dryRun = false): array
    {
        return $this->withTransaction($dryRun, function () use ($mailboxId, $dryRun): array {
            $mailbox = $this->fetchOne('SELECT * FROM mailboxes WHERE id = :id', ['id' => $mailboxId]);
            if ($mailbox === null) {
                return $this->notFound('mailbox_id', $mailboxId, $dryRun);
            }

            $deleted = [];
            foreach ($this->mailboxDeleteStatements() as $table => $sql) {
                if (!$this->tableExists($table)) {
                    $deleted[$table] = 0;
                    continue;
                }

                $deleted[$table] = $this->executeCount($sql, array_fill(0, substr_count($sql, '?'), $mailboxId));
            }

            return [
                'mailbox_id' => $mailboxId,
                'found' => true,
                'dry_run' => $dryRun,
                'mailbox' => [
                    'id' => (int) ($mailbox['id'] ?? $mailboxId),
                    'tenant_id' => isset($mailbox['tenant_id']) ? (int) $mailbox['tenant_id'] : null,
                    'domain_id' => isset($mailbox['domain_id']) ? (int) $mailbox['domain_id'] : null,
                    'email' => $mailbox['email'] ?? null,
                    'deleted_at' => $mailbox['deleted_at'] ?? null,
                ],
                'deleted' => $deleted,
            ];
        });
    }

    public function purgeMailGroup(int $groupId, bool $dryRun = false): array
    {
        return $this->purgeSimpleRecord(
            'mail_group_id',
            $groupId,
            'mail_groups',
            'id',
            [
                'mail_group_members' => 'DELETE FROM mail_group_members WHERE group_id = ?',
                'mail_groups' => 'DELETE FROM mail_groups WHERE id = ?',
            ],
            $dryRun
        );
    }

    public function purgeAlias(int $aliasId, bool $dryRun = false): array
    {
        return $this->purgeSimpleRecord(
            'alias_id',
            $aliasId,
            'aliases',
            'id',
            ['aliases' => 'DELETE FROM aliases WHERE id = ?'],
            $dryRun
        );
    }

    public function purgeForward(int $forwardId, bool $dryRun = false): array
    {
        return $this->purgeSimpleRecord(
            'forward_id',
            $forwardId,
            'forwards',
            'id',
            ['forwards' => 'DELETE FROM forwards WHERE id = ?'],
            $dryRun
        );
    }

    public function purgeUser(int $userId, bool $dryRun = false): array
    {
        return $this->purgeSimpleRecord(
            'user_id',
            $userId,
            'users',
            'id',
            [
                'api_tokens' => 'DELETE FROM api_tokens WHERE user_id = ?',
                'user_password_history' => 'DELETE FROM user_password_history WHERE user_id = ?',
                'tenants' => 'UPDATE tenants SET admin_user_id = NULL, updated_at = NOW() WHERE admin_user_id = ?',
                'users' => 'DELETE FROM users WHERE id = ?',
            ],
            $dryRun
        );
    }

    public function purgePackage(int $packageId, bool $dryRun = false): array
    {
        return $this->purgeSimpleRecord(
            'package_id',
            $packageId,
            'packages',
            'id',
            ['packages' => 'DELETE FROM packages WHERE id = ?'],
            $dryRun
        );
    }

    public function purgeSoftDeletedRows(bool $dryRun = false): array
    {
        $results = [
            'dry_run' => $dryRun,
            'tenants' => [],
            'domains' => [],
            'mailboxes' => [],
            'mail_groups' => [],
            'aliases' => [],
            'forwards' => [],
            'users' => [],
            'packages' => [],
        ];

        foreach ($this->deletedTenantIds() as $tenantId) {
            $results['tenants'][] = $this->purgeTenant($tenantId, $dryRun);
        }

        foreach ($this->softDeletedIds('domains') as $domainId) {
            $results['domains'][] = $this->purgeDomain($domainId, $dryRun);
        }

        foreach ($this->softDeletedIds('mailboxes') as $mailboxId) {
            $results['mailboxes'][] = $this->purgeMailbox($mailboxId, $dryRun);
        }

        foreach ($this->softDeletedIds('mail_groups') as $groupId) {
            $results['mail_groups'][] = $this->purgeMailGroup($groupId, $dryRun);
        }

        foreach ($this->softDeletedIds('aliases') as $aliasId) {
            $results['aliases'][] = $this->purgeAlias($aliasId, $dryRun);
        }

        foreach ($this->softDeletedIds('forwards') as $forwardId) {
            $results['forwards'][] = $this->purgeForward($forwardId, $dryRun);
        }

        foreach ($this->softDeletedIds('users') as $userId) {
            $results['users'][] = $this->purgeUser($userId, $dryRun);
        }

        foreach ($this->softDeletedIds('packages') as $packageId) {
            $results['packages'][] = $this->purgePackage($packageId, $dryRun);
        }

        return $results;
    }

    private function deleteStatements(): array
    {
        return [
            'api_tokens' => 'DELETE FROM api_tokens
                WHERE tenant_id = ?
                   OR user_id IN (SELECT id FROM users WHERE tenant_id = ?)
                   OR mailbox_id IN (SELECT id FROM mailboxes WHERE tenant_id = ?)',
            'user_password_history' => 'DELETE FROM user_password_history
                WHERE tenant_id = ?
                   OR user_id IN (SELECT id FROM users WHERE tenant_id = ?)',
            'mailbox_password_history' => 'DELETE FROM mailbox_password_history
                WHERE tenant_id = ?
                   OR mailbox_id IN (SELECT id FROM mailboxes WHERE tenant_id = ?)',
            'mailbox_app_passwords' => 'DELETE FROM mailbox_app_passwords
                WHERE mailbox_id IN (SELECT id FROM mailboxes WHERE tenant_id = ?)',
            'quota_usage' => 'DELETE FROM quota_usage
                WHERE tenant_id = ?
                   OR mailbox_id IN (SELECT id FROM mailboxes WHERE tenant_id = ?)',
            'mail_group_members' => 'DELETE FROM mail_group_members
                WHERE group_id IN (SELECT id FROM mail_groups WHERE tenant_id = ?)',
            'aliases' => 'DELETE FROM aliases
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)
                   OR destination_mailbox_id IN (SELECT id FROM mailboxes WHERE tenant_id = ?)',
            'forwards' => 'DELETE FROM forwards
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)',
            'spam_policies' => 'DELETE FROM spam_policies
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)
                   OR mailbox_id IN (SELECT id FROM mailboxes WHERE tenant_id = ?)',
            'dns_checks' => 'DELETE FROM dns_checks
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)',
            'dkim_keys' => 'DELETE FROM dkim_keys
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)',
            'mail_groups' => 'DELETE FROM mail_groups
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)',
            'mailboxes' => 'DELETE FROM mailboxes
                WHERE tenant_id = ?
                   OR domain_id IN (SELECT id FROM domains WHERE tenant_id = ?)',
            'domains' => 'DELETE FROM domains WHERE tenant_id = ?',
            'users' => 'DELETE FROM users WHERE tenant_id = ?',
            'tenant_lifecycle_events' => 'DELETE FROM tenant_lifecycle_events WHERE tenant_id = ?',
            'tenant_subscriptions' => 'DELETE FROM tenant_subscriptions WHERE tenant_id = ?',
            'tenants' => 'DELETE FROM tenants WHERE id = ?',
        ];
    }

    private function domainDeleteStatements(): array
    {
        return [
            'api_tokens' => 'DELETE FROM api_tokens
                WHERE mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)',
            'mailbox_password_history' => 'DELETE FROM mailbox_password_history
                WHERE mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)',
            'mailbox_app_passwords' => 'DELETE FROM mailbox_app_passwords
                WHERE mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)',
            'quota_usage' => 'DELETE FROM quota_usage
                WHERE mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)',
            'mail_group_members' => 'DELETE FROM mail_group_members
                WHERE group_id IN (SELECT id FROM mail_groups WHERE domain_id = ?)',
            'aliases' => 'DELETE FROM aliases
                WHERE domain_id = ?
                   OR destination_mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)',
            'forwards' => 'DELETE FROM forwards WHERE domain_id = ?',
            'spam_policies' => 'DELETE FROM spam_policies
                WHERE domain_id = ?
                   OR mailbox_id IN (SELECT id FROM mailboxes WHERE domain_id = ?)',
            'dns_checks' => 'DELETE FROM dns_checks WHERE domain_id = ?',
            'dkim_keys' => 'DELETE FROM dkim_keys WHERE domain_id = ?',
            'mail_groups' => 'DELETE FROM mail_groups WHERE domain_id = ?',
            'mailboxes' => 'DELETE FROM mailboxes WHERE domain_id = ?',
            'domains' => 'DELETE FROM domains WHERE id = ?',
        ];
    }

    private function mailboxDeleteStatements(): array
    {
        return [
            'api_tokens' => 'DELETE FROM api_tokens WHERE mailbox_id = ?',
            'mailbox_password_history' => 'DELETE FROM mailbox_password_history WHERE mailbox_id = ?',
            'mailbox_app_passwords' => 'DELETE FROM mailbox_app_passwords WHERE mailbox_id = ?',
            'quota_usage' => 'DELETE FROM quota_usage WHERE mailbox_id = ?',
            'domains' => 'UPDATE domains SET catchall_mailbox_id = NULL, updated_at = NOW() WHERE catchall_mailbox_id = ?',
            'mail_group_members' => 'DELETE FROM mail_group_members
                WHERE LOWER(recipient_address) = (SELECT LOWER(email) FROM mailboxes WHERE id = ?)',
            'aliases' => 'DELETE FROM aliases
                WHERE destination_mailbox_id = ?
                   OR LOWER(source_address) = (SELECT LOWER(email) FROM mailboxes WHERE id = ?)',
            'forwards' => 'DELETE FROM forwards
                WHERE LOWER(source_address) = (SELECT LOWER(email) FROM mailboxes WHERE id = ?)
                   OR LOWER(destination_address) = (SELECT LOWER(email) FROM mailboxes WHERE id = ?)',
            'spam_policies' => 'DELETE FROM spam_policies WHERE mailbox_id = ?',
            'mailboxes' => 'DELETE FROM mailboxes WHERE id = ?',
        ];
    }

    /**
     * @param array<string, string> $statements
     */
    private function purgeSimpleRecord(
        string $idKey,
        int $id,
        string $table,
        string $column,
        array $statements,
        bool $dryRun
    ): array {
        return $this->withTransaction($dryRun, function () use ($idKey, $id, $table, $column, $statements, $dryRun): array {
            $table = $this->safeIdentifier($table);
            $column = $this->safeIdentifier($column);

            if (!$this->tableExists($table)) {
                return $this->notFound($idKey, $id, $dryRun);
            }

            $record = $this->fetchOne("SELECT * FROM {$table} WHERE {$column} = :id", ['id' => $id]);
            if ($record === null) {
                return $this->notFound($idKey, $id, $dryRun);
            }

            $deleted = [];
            foreach ($statements as $statementTable => $sql) {
                if (!$this->tableExists($statementTable)) {
                    $deleted[$statementTable] = 0;
                    continue;
                }

                $deleted[$statementTable] = $this->executeCount($sql, array_fill(0, substr_count($sql, '?'), $id));
            }

            return [
                $idKey => $id,
                'found' => true,
                'dry_run' => $dryRun,
                'deleted' => $deleted,
            ];
        });
    }

    private function softDeletedIds(string $table): array
    {
        $table = $this->safeIdentifier($table);

        if (!$this->tableExists($table)) {
            return [];
        }

        return array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $this->fetchAll("SELECT id FROM {$table} WHERE deleted_at IS NOT NULL ORDER BY id ASC")
        );
    }

    private function notFound(string $key, int $id, bool $dryRun): array
    {
        return [
            $key => $id,
            'found' => false,
            'dry_run' => $dryRun,
            'deleted' => [],
        ];
    }

    /**
     * @template T of array
     * @param callable():T $callback
     * @return T
     */
    private function withTransaction(bool $dryRun, callable $callback): array
    {
        $pdo = $this->pdo();
        $startedTransaction = !$pdo->inTransaction();

        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $result = $callback();

            if ($dryRun) {
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } elseif ($startedTransaction) {
                $pdo->commit();
            }

            return $result;
        } catch (\Throwable $exception) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }

    private function redactTenant(array $tenant): array
    {
        return [
            'id' => isset($tenant['id']) ? (int) $tenant['id'] : null,
            'name' => $tenant['name'] ?? null,
            'slug' => $tenant['slug'] ?? null,
            'status' => $tenant['status'] ?? null,
            'deleted_at' => $tenant['deleted_at'] ?? null,
        ];
    }

    private function tableExists(string $table): bool
    {
        $table = $this->safeIdentifier($table);
        $driver = (string) $this->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            return $this->fetchOne(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table",
                ['table' => $table]
            ) !== null;
        }

        return $this->fetchOne(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table',
            ['table' => $table]
        ) !== null;
    }

    private function safeIdentifier(string $identifier): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $identifier) !== 1) {
            throw new \InvalidArgumentException('Unsafe SQL identifier.');
        }

        return $identifier;
    }

    private function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    private function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();

        return $row ?: null;
    }

    private function executeCount(string $sql, array $params = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    private function pdo(): PDO
    {
        return $this->database->connection();
    }
}
