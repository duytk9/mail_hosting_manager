<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AgentWrapperSecurityTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/agent/mailpanel-system-wrapper');
        $this->assertIsString($source);
        $this->source = $source;
    }

    public function test_privileged_wrapper_canonicalizes_generated_config_paths(): void
    {
        $this->assertStringContainsString('GENERATED_ROOT="/var/lib/mailpanel/generated"', $this->source);
        $this->assertStringContainsString('ACTIVE_ROOT="/var/lib/mailpanel/generated/active"', $this->source);
        $this->assertStringContainsString('canonical_generated_child()', $this->source);
        $this->assertStringContainsString('root_real="$(readlink -m "$GENERATED_ROOT")"', $this->source);
        $this->assertStringContainsString('path_real="$(readlink -m "$path")"', $this->source);
        $this->assertStringContainsString('validate_generated_file()', $this->source);
        $this->assertStringContainsString('validate_active_child_path()', $this->source);
    }

    public function test_wrapper_no_longer_trusts_simple_generated_path_prefix_checks(): void
    {
        $this->assertStringNotContainsString('case "$SERVICE" in /var/lib/mailpanel/generated/*) ;; *) exit 65 ;; esac', $this->source);
        $this->assertStringNotContainsString('case "$ARG1" in /var/lib/mailpanel/generated/*) ;; *) exit 65 ;; esac', $this->source);
        $this->assertStringNotContainsString('case "$ARG2" in /var/lib/mailpanel/generated/active/*) ;; *) exit 66 ;; esac', $this->source);
        $this->assertStringContainsString('SERVICE="$(validate_generated_file "$SERVICE")"', $this->source);
        $this->assertStringContainsString('ARG1="$(validate_generated_file "$ARG1")"', $this->source);
        $this->assertStringContainsString('ARG2="$(validate_active_child_path "$ARG2")"', $this->source);
    }

    public function test_mailbox_usage_scan_is_constrained_to_vmail_root(): void
    {
        $this->assertStringContainsString('validate_temp_file()', $this->source);
        $this->assertStringContainsString('mailbox-usage-batch)', $this->source);
        $this->assertStringContainsString('if mailbox_path == root_real or not mailbox_path.startswith(root_real + os.sep):', $this->source);
        $this->assertStringContainsString('maildirsize_bytes(mailbox_path)', $this->source);
        $this->assertStringContainsString('message_file_bytes(mailbox_path)', $this->source);
        $this->assertStringContainsString('"used_mb": int(math.ceil(used_bytes / 1048576))', $this->source);
    }

    public function test_exim_mail_acl_is_versioned_and_injected_before_mail_from_acceptance(): void
    {
        $this->assertStringContainsString('EXIM_ACL_MARKER_BEGIN="# BEGIN MAILPANEL ACL"', $this->source);
        $this->assertStringContainsString('strip_managed_acl()', $this->source);
        $this->assertStringContainsString('EXIM_ACL_SNIPPET="$EXIM_EXTRA_DIR/mailpanel-acl-mail.conf"', $this->source);
        $this->assertStringContainsString('acl_path = Path(sys.argv[5])', $this->source);
        $this->assertStringContainsString('missing \'begin acl\' in exim template', $this->source);
        $this->assertStringContainsString('install -o root -g root -m 0644 "$EXIM_ACL_SNIPPET" /etc/exim4/mailpanel/acl-mail.conf', $this->source);
    }
}
