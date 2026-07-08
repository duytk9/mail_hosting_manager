<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\PermissionMap;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthorizationServiceTest extends TestCase
{
    public function test_rejects_cross_tenant_access(): void
    {
        $this->expectException(RuntimeException::class);

        (new AuthorizationService())->requireTenantScope(
            new Actor(10, 'tenant_admin', 5),
            6
        );
    }

    public function test_rejects_readonly_write(): void
    {
        $this->expectException(RuntimeException::class);

        (new AuthorizationService())->assertWritable(
            new Actor(11, 'support_readonly')
        );
    }

    public function test_rejects_missing_api_scope(): void
    {
        $this->expectException(RuntimeException::class);

        (new AuthorizationService())->requireScopes(
            ['scopes' => json_encode(['dashboard.read'])],
            ['config.write']
        );
    }

    public function test_super_admin_has_all_registered_permissions(): void
    {
        $service = new AuthorizationService();
        $actor = new Actor(1, 'super_admin');

        $this->assertNotEmpty(PermissionMap::allPermissions());
        $this->assertTrue($service->can($actor, 'packages.create'));
        $this->assertTrue($service->can($actor, 'system_settings.bulk_action'));
    }

    public function test_support_readonly_can_view_but_cannot_create_packages(): void
    {
        $service = new AuthorizationService();
        $actor = new Actor(12, 'support_readonly');

        $this->assertTrue($service->can($actor, 'packages.view'));
        $this->assertFalse($service->can($actor, 'packages.create'));
    }

    public function test_tenant_admin_cannot_access_super_admin_permissions(): void
    {
        $service = new AuthorizationService();
        $actor = new Actor(13, 'tenant_admin', 5);

        $this->assertFalse($service->can($actor, 'super_admins.view'));
        $this->assertTrue($service->can($actor, 'mailboxes.create'));
        $this->assertFalse($service->can($actor, 'tenants.view'));
        $this->assertFalse($service->can($actor, 'api_tokens.create'));
        $this->assertFalse($service->can($actor, 'quota.update'));
    }

    public function test_domain_admin_is_not_tenant_wide_until_domain_scoping_exists(): void
    {
        $service = new AuthorizationService();
        $actor = new Actor(14, 'domain_admin', 5);

        $this->assertTrue($service->can($actor, 'security.view'));
        $this->assertTrue($service->can($actor, 'security.update'));
        $this->assertFalse($service->can($actor, 'dashboard.view'));
        $this->assertFalse($service->can($actor, 'domains.view'));
        $this->assertFalse($service->can($actor, 'domains.create'));
        $this->assertFalse($service->can($actor, 'mailboxes.create'));
        $this->assertFalse($service->can($actor, 'aliases.create'));
        $this->assertFalse($service->can($actor, 'forwards.create'));
        $this->assertFalse($service->can($actor, 'quota.update'));
    }
}
