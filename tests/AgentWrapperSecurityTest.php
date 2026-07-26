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

    /**
     * The rspamd map / tenant rule actions used to accept any readable path and
     * install it into /etc/rspamd/local.d as mode 0644. The agent only ever passes
     * files it created itself, but the wrapper runs as root and must not depend on
     * its caller behaving.
     */
    public function test_agent_payload_files_are_confined_to_the_temp_directory(): void
    {
        $this->assertStringContainsString('validate_agent_payload_file()', $this->source);
        $this->assertStringContainsString('tmp_real="$(readlink -m "$tmp_root")"', $this->source);
        $this->assertStringContainsString('[[ "$path_real" == "$tmp_real"/* ]] || exit 84', $this->source);

        // Symlinks and hard-linked files must be rejected.
        $this->assertStringContainsString('[[ ! -L "$path" ]] || exit 84', $this->source);
        $this->assertStringContainsString('stat -c \'%h\' "$path_real"', $this->source);
    }

    public function test_rspamd_actions_validate_every_payload_path(): void
    {
        foreach ([
            'MAP_IP_WL="$(validate_agent_payload_file "$SERVICE")"',
            'MAP_IP_BL="$(validate_agent_payload_file "$ARG1")"',
            'MAP_SENDER_WL="$(validate_agent_payload_file "$ARG2")"',
            'MAP_SENDER_BL="$(validate_agent_payload_file "$ARG3")"',
            'MAP_RCPT_WL="$(validate_agent_payload_file "$ARG4")"',
            'MAP_RCPT_BL="$(validate_agent_payload_file "${6:-}")"',
            'SOURCE_FILE="$(validate_agent_payload_file "$ARG1")"',
        ] as $expected) {
            $this->assertStringContainsString($expected, $this->source);
        }

        // The old unchecked "is it a file?" guard must be gone.
        $this->assertStringNotContainsString('[[ -n "$FILE" && -f "$FILE" ]] || exit 83', $this->source);
        $this->assertStringNotContainsString('[[ -n "$SOURCE_FILE" && -f "$SOURCE_FILE" ]] || exit 83', $this->source);
    }

    public function test_super_admin_credential_files_are_confined(): void
    {
        $this->assertStringContainsString('PASSWORD_HASH_FILE="$(validate_agent_payload_file "$PASSWORD_HASH_FILE")"', $this->source);
        $this->assertStringContainsString('KEY_FILE="$(validate_agent_payload_file "$KEY_FILE")"', $this->source);
        $this->assertStringContainsString('PASSWORD_FILE="$(validate_agent_payload_file "$ARG1")"', $this->source);

        $this->assertStringNotContainsString('[[ -n "$PASSWORD_FILE" && -f "$PASSWORD_FILE" ]] || exit 68', $this->source);
    }

    /**
     * Files installed into /etc by the wrapper must be owned by root, not by
     * whatever uid happened to create the temp file.
     */
    public function test_rspamd_maps_are_installed_as_root(): void
    {
        preg_match_all('/install [^\n]*\/etc\/rspamd\/local\.d\/[^\n]*/', $this->source, $matches);

        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $line) {
            if (!str_contains($line, 'local.d/')) {
                continue;
            }

            $this->assertStringContainsString(
                '-o root -g root',
                $line,
                'rspamd config installed without forcing root ownership: ' . $line
            );
        }
    }
}
