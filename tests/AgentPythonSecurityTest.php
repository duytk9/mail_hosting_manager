<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AgentPythonSecurityTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/agent/mailpanel_agent.py');
        $this->assertIsString($source);
        $this->source = $source;
    }

    public function test_agent_logs_are_redacted_before_written(): void
    {
        $this->assertStringContainsString('"payload": redact_sensitive(payload or {})', $this->source);
        $this->assertStringContainsString('encoded = json.dumps(line, ensure_ascii=False)', $this->source);
        $this->assertStringContainsString('def redact_sensitive(value):', $this->source);
        $this->assertStringContainsString('Authorization:', $this->source);
        $this->assertStringContainsString('is_secret_key', $this->source);
    }

    public function test_agent_logging_failure_does_not_abort_execution_or_leak_raw_payload(): void
    {
        $this->assertMatchesRegularExpression(
            '/def log_event.*?try:.*?LOG_PATH\\.parent\\.mkdir.*?LOG_PATH\\.open.*?except OSError:.*?print\\(encoded, file=sys\\.stderr\\)/s',
            $this->source
        );
        $this->assertStringNotContainsString('print(line, file=sys.stderr)', $this->source);
        $this->assertStringNotContainsString('print(payload', $this->source);
    }

    public function test_agent_clamps_timeouts_and_validates_system_inputs(): void
    {
        $this->assertStringContainsString('MAX_TIMEOUT_SECONDS = 900', $this->source);
        $this->assertStringContainsString('def normalize_timeout', $this->source);
        $this->assertStringContainsString('def require_message_id', $this->source);
        $this->assertStringContainsString('def require_jail', $this->source);
        $this->assertStringContainsString('def require_ip', $this->source);
        $this->assertStringContainsString('def require_decimal', $this->source);
        $this->assertStringContainsString('VALID_ACME_PROFILES = {"mail_only", "mail_and_web"}', $this->source);
    }

    public function test_agent_rejects_reserved_linux_usernames_before_wrapper(): void
    {
        $this->assertStringContainsString('RESERVED_LINUX_USERNAMES = {', $this->source);
        $this->assertStringContainsString('"root"', $this->source);
        $this->assertStringContainsString('"vmail"', $this->source);
        $this->assertStringContainsString('"mailpanel-agent"', $this->source);
        $this->assertStringContainsString('if username in RESERVED_LINUX_USERNAMES:', $this->source);
        $this->assertStringContainsString('raise ValueError("Reserved linux username")', $this->source);
    }

    public function test_rspamd_temp_files_are_removed_in_finally_blocks(): void
    {
        $this->assertMatchesRegularExpression(
            '/elif action == "rspamd-set-multimap":.*?try:.*?write_secure_temp.*?finally:.*?safe_unlink/s',
            $this->source
        );
        $this->assertMatchesRegularExpression(
            '/elif action == "rspamd-sync-tenant-rules":.*?try:.*?write_secure_temp.*?finally:.*?safe_unlink/s',
            $this->source
        );
    }

    public function test_mail_storage_and_domain_management_are_validated_before_wrapper(): void
    {
        $this->assertStringContainsString('old_domain = require_domain', $this->source);
        $this->assertStringContainsString('new_domain = require_domain', $this->source);
        $this->assertStringContainsString('email = require_email', $this->source);
        $this->assertStringContainsString('domain = require_domain', $this->source);
        $this->assertStringContainsString('vmail_uid = require_numeric_id', $this->source);
        $this->assertStringContainsString('vmail_gid = require_numeric_id', $this->source);
        $this->assertStringContainsString('vmail_root = require_absolute_path', $this->source);
    }

    public function test_privileged_agent_paths_are_restricted_before_sudo_wrapper(): void
    {
        $this->assertStringContainsString('def require_absolute_path', $this->source);
        $this->assertStringContainsString('path.is_absolute()', $this->source);
        $this->assertStringContainsString('".." in path.parts', $this->source);
        $this->assertStringContainsString('path == Path(path.anchor)', $this->source);
        $this->assertStringContainsString('raise ValueError("Path cannot be generated root")', $this->source);
        $this->assertStringContainsString('app_root = require_absolute_path', $this->source);
    }
}
