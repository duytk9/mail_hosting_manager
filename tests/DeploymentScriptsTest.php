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
}
