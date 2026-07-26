<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

class PackageRepository extends AbstractPdoRepository
{
    public function all(): array
    {
        return $this->fetchAll('SELECT * FROM packages WHERE deleted_at IS NULL ORDER BY id DESC');
    }

    public function find(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM packages WHERE id = :id AND deleted_at IS NULL', ['id' => $id]);
    }

    public function create(array $data): array
    {
        $this->execute(
            'INSERT INTO packages (name, description, max_domains, max_mailboxes, max_aliases, max_forwarders, max_total_quota_mb, default_mailbox_quota_mb, max_mailbox_quota_mb, max_message_size_mb, outbound_per_hour, outbound_per_day, enable_pop3, enable_imap, enable_managesieve, enable_catchall, enable_external_forwarding, spam_level_default, quarantine_enabled, antivirus_enabled, dkim_enabled, custom_smtp_banner_allowed, retention_days, created_at, updated_at) VALUES (:name, :description, :max_domains, :max_mailboxes, :max_aliases, :max_forwarders, :max_total_quota_mb, :default_mailbox_quota_mb, :max_mailbox_quota_mb, :max_message_size_mb, :outbound_per_hour, :outbound_per_day, :enable_pop3, :enable_imap, :enable_managesieve, :enable_catchall, :enable_external_forwarding, :spam_level_default, :quarantine_enabled, :antivirus_enabled, :dkim_enabled, :custom_smtp_banner_allowed, :retention_days, NOW(), NOW())',
            $data
        );

        return $this->find($this->lastInsertId()) ?? [];
    }
    public function update(int $id, array $data): void
    {
        $this->execute(
            'UPDATE packages SET name = :name, description = :description, max_domains = :max_domains, max_mailboxes = :max_mailboxes, max_aliases = :max_aliases, max_forwarders = :max_forwarders, max_total_quota_mb = :max_total_quota_mb, default_mailbox_quota_mb = :default_mailbox_quota_mb, max_mailbox_quota_mb = :max_mailbox_quota_mb, max_message_size_mb = :max_message_size_mb, outbound_per_hour = :outbound_per_hour, outbound_per_day = :outbound_per_day, enable_pop3 = :enable_pop3, enable_imap = :enable_imap, enable_managesieve = :enable_managesieve, enable_catchall = :enable_catchall, enable_external_forwarding = :enable_external_forwarding, spam_level_default = :spam_level_default, quarantine_enabled = :quarantine_enabled, antivirus_enabled = :antivirus_enabled, dkim_enabled = :dkim_enabled, custom_smtp_banner_allowed = :custom_smtp_banner_allowed, retention_days = :retention_days, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL',
            $data + ['id' => $id]
        );
    }

    public function delete(int $id): void
    {
        $this->execute('DELETE FROM packages WHERE id = :id', ['id' => $id]);
    }
}
