<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AcmeTlsScriptTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        $this->script = (string) file_get_contents(__DIR__ . '/../deploy/manage_acme_tls.sh');
    }

    public function test_uses_standard_letsencrypt_paths_and_existing_sync_script(): void
    {
        $this->assertStringContainsString(
            'ACME_MANIFEST_ROOT="${ACME_MANIFEST_ROOT:-/etc/mailpanel/letsencrypt/domains}"',
            $this->script
        );
        $this->assertStringContainsString(
            'ACME_HOOK_PATH="${ACME_HOOK_PATH:-/etc/letsencrypt/renewal-hooks/deploy/20-mailpanel-sync.sh}"',
            $this->script
        );
        $this->assertStringContainsString(
            '"${APP_ROOT}/deploy/manage_acme_tls.sh" sync-all "${APP_ROOT}"',
            $this->script
        );
        $this->assertStringNotContainsString('manage_letsencrypt.sh', $this->script);
    }

    public function test_manifest_is_parsed_without_shell_source_and_certificate_is_verified(): void
    {
        $this->assertStringNotContainsString('source "$manifest"', $this->script);
        $this->assertStringContainsString('manifest_value "$manifest" DOMAIN', $this->script);
        $this->assertStringContainsString(
            'openssl x509 -in "${live_root}/fullchain.pem" -noout -checkhost "$host"',
            $this->script
        );
        $this->assertStringContainsString(
            'Certificate and private key do not match for $host.',
            $this->script
        );
    }

    public function test_sync_is_locked_and_applies_through_current_container(): void
    {
        $this->assertStringContainsString('flock -w 60 9', $this->script);
        $this->assertStringContainsString(
            '$application = require $realRoot . \'/bootstrap/app.php\';',
            $this->script
        );
        $this->assertStringContainsString(
            '$deployment = $application->resolve(ConfigDeploymentService::class);',
            $this->script
        );
    }

    public function test_existing_certbot_renewal_webroot_is_repaired(): void
    {
        $this->assertStringContainsString('repair_certbot_renewal_webroot()', $this->script);
        $this->assertStringContainsString('webroot_path = {webroot},', $this->script);
        $this->assertStringContainsString('MANAGED_HOSTS="$hosts_csv"', $this->script);
        $this->assertStringContainsString('mailpanel-bak-', $this->script);
        $this->assertStringContainsString('repair_certbot_renewal_webroot "$cert_name" "$hosts_csv"', $this->script);
    }
}
