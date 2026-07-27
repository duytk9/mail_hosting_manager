<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class DeploymentScriptsTest extends TestCase
{
    public function test_release_deployments_share_runtime_storage_outside_releases(): void
    {
        $pushDeploy = (string) file_get_contents(dirname(__DIR__) . '/deploy/deploy.sh');
        $pullDeploy = (string) file_get_contents(dirname(__DIR__) . '/deploy/deploy-from-git.sh');

        foreach ([$pushDeploy, $pullDeploy] as $script) {
            $this->assertStringContainsString('SHARED_STORAGE_ROOT', $script);
            $this->assertStringContainsString('logs sessions cache generated rate_limits app_settings', $script);
            $this->assertStringContainsString('ln -s', $script);
            $this->assertStringContainsString('fetch-fonts.sh', $script);
            $this->assertStringContainsString('readlink -f', $script);
        }
    }

    public function test_agent_permissions_do_not_take_ownership_of_shared_runtime_storage(): void
    {
        $installer = (string) file_get_contents(dirname(__DIR__) . '/deploy/install_agent.sh');

        $this->assertStringContainsString('chown -R "$AGENT_USER":"$AGENT_USER" /var/lib/mailpanel/generated', $installer);
        $this->assertDoesNotMatchRegularExpression(
            '/^chown -R "\$AGENT_USER":"\$AGENT_USER" \/var\/lib\/mailpanel\s*$/m',
            $installer
        );
        $this->assertStringContainsString('chmod 2750 /var/lib/mailpanel', $installer);
    }

    public function test_pull_deployment_does_not_export_composers_reserved_config_variable(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/deploy/deploy-from-git.sh');

        $this->assertStringContainsString('COMPOSER_BIN', $script);
        $this->assertStringNotContainsString('COMPOSER="', $script);
    }

    public function test_git_deployment_resolves_an_immutable_commit_and_is_serialized(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/deploy/deploy-from-git.sh');

        $this->assertStringContainsString('REVISION_FULL=', $script);
        $this->assertStringContainsString('refs/remotes/origin/$GIT_REF', $script);
        $this->assertStringContainsString('git -C "$REPO_CACHE" archive --format=tar "$REVISION_FULL"', $script);
        $this->assertStringContainsString('flock -n 9', $script);
        $this->assertStringNotContainsString('merge --quiet --ff-only', $script);
    }

    public function test_failed_releases_restore_the_application_and_agent(): void
    {
        foreach (['deploy.sh', 'deploy-from-git.sh'] as $filename) {
            $script = (string) file_get_contents(dirname(__DIR__) . '/deploy/' . $filename);

            $this->assertStringContainsString('PREVIOUS_LINK', $script, $filename);
            $this->assertStringContainsString('AGENT_REFRESHED', $script, $filename);
            $this->assertStringContainsString('ACTIVATED', $script, $filename);
            $this->assertStringContainsString('healthcheck.sh', $script, $filename);
            $this->assertStringContainsString('rm -rf --', $script, $filename);
        }
    }

    public function test_roundcube_upgrade_is_pinned_backed_up_and_rollback_capable(): void
    {
        $upgrade = (string) file_get_contents(dirname(__DIR__) . '/deploy/upgrade_roundcube.sh');
        $installer = (string) file_get_contents(dirname(__DIR__) . '/deploy/install.sh');

        $this->assertStringContainsString(
            'e1f6c437959cb8dffda1a3e59f0c0a2160b3d669948db69bb02edb218c8e69a1',
            $upgrade
        );
        $this->assertStringContainsString('mysqldump --single-transaction', $upgrade);
        $this->assertStringContainsString('installto.sh', $upgrade);
        $this->assertStringContainsString('rollback_upgrade', $upgrade);
        $this->assertStringContainsString('roundcube-db.sql.gz', $upgrade);
        $this->assertStringContainsString('upgrade_roundcube.sh', $installer);
    }

    public function test_fresh_install_generates_a_totp_encryption_key(): void
    {
        $installer = (string) file_get_contents(dirname(__DIR__) . '/deploy/install.sh');

        $this->assertStringContainsString('TOTP_ENCRYPTION_KEY="base64:$(openssl rand -base64 32)"', $installer);
        $this->assertStringContainsString('TOTP_ENCRYPTION_KEY=${TOTP_ENCRYPTION_KEY}', $installer);
        $this->assertStringNotContainsString("TOTP_ENCRYPTION_KEY=\n", $installer);
        $this->assertStringContainsString("if [[ \"\$CHECK_ONLY\" == \"1\" ]]; then\n  LOG_FILE=/dev/null", $installer);
        $this->assertStringContainsString('Configuring pull deployment', $installer);
        $this->assertStringContainsString("printf 'GIT_REMOTE=%q\\n'", $installer);
        $this->assertStringContainsString('refusing to write a Git remote containing credentials', $installer);
    }
}
