<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class MigrationRunnerSecurityTest extends TestCase
{
    public function test_migration_runner_tracks_checksums_and_requires_baseline_for_existing_installs(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/scripts/migrate.php');
        $this->assertIsString($source);

        $this->assertStringContainsString('schema_migrations', $source);
        $this->assertStringContainsString('checksum', $source);
        $this->assertStringContainsString('--baseline-existing', $source);
        $this->assertStringContainsString('Existing database tables were detected without migration history.', $source);
        $this->assertStringContainsString('Applied migration file was modified', $source);
        $this->assertStringContainsString('checksumTransitions', $source);
        $this->assertStringContainsString('applied-compatible', $source);
    }

    public function test_superseded_duplicate_foreign_key_migration_is_not_active(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/scripts/migrate.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("'010_add_foreign_keys.sql'", $source);
        $this->assertStringContainsString('superseded by 010_add_foreign_keys_and_indexes.sql', $source);
    }

    public function test_index_migrations_preserve_foreign_key_support_before_dropping_old_names(): void
    {
        $migration011 = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/011_add_missing_indexes_and_rate_limits.sql');
        $migration014 = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/014_optimize_operational_indexes.sql');
        $migration017 = (string) file_get_contents(dirname(__DIR__) . '/database/migrations/017_remove_redundant_single_column_indexes.sql');

        $this->assertStringNotContainsString('ADD INDEX idx_aliases_tenant_id', $migration011);
        $this->assertStringContainsString('idx_aliases_destination_mailbox_id', $migration014);
        $this->assertStringContainsString('idx_mph_mailbox_id', $migration014);
        $this->assertStringContainsString('idx_dkim_keys_domain_id', $migration014);
        $this->assertStringContainsString('idx_spam_policies_mailbox_id', $migration014);
        $this->assertLessThan(
            strpos($migration014, 'DROP INDEX IF EXISTS idx_aliases_dest_mailbox'),
            strpos($migration014, 'ADD INDEX IF NOT EXISTS idx_aliases_destination_mailbox_id')
        );
        $this->assertStringContainsString('DROP INDEX IF EXISTS idx_aliases_tenant_id', $migration017);
    }
}
