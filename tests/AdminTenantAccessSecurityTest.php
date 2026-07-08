<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use PHPUnit\Framework\TestCase;

final class AdminTenantAccessSecurityTest extends TestCase
{
    public function test_tenant_admin_cannot_access_customer_account_management(): void
    {
        $authorization = new AuthorizationService();
        $actor = new Actor(20, 'tenant_admin', 7);

        $this->assertFalse($authorization->can($actor, 'tenants.view'));
        $this->assertFalse($authorization->can($actor, 'tenants.create'));
        $this->assertFalse($authorization->can($actor, 'tenants.update'));
        $this->assertFalse($authorization->can($actor, 'tenants.delete'));
    }

    public function test_tenant_admin_has_only_tenant_mail_operations(): void
    {
        $authorization = new AuthorizationService();
        $actor = new Actor(21, 'tenant_admin', 7);

        $this->assertTrue($authorization->can($actor, 'dashboard.view'));
        $this->assertTrue($authorization->can($actor, 'domains.create'));
        $this->assertTrue($authorization->can($actor, 'mailboxes.create'));
        $this->assertTrue($authorization->can($actor, 'routing.view'));
        $this->assertTrue($authorization->can($actor, 'dns_checks.update'));
        $this->assertFalse($authorization->can($actor, 'api_tokens.create'));
        $this->assertFalse($authorization->can($actor, 'quota.update'));
        $this->assertFalse($authorization->can($actor, 'config_versions.view'));
    }

    public function test_tenant_controller_has_role_guard_before_customer_mutation(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminTenantController.php');

        $this->assertStringContainsString('if (!$this->isSuperAdmin())', $source);
        $this->assertStringContainsString('Chỉ Admin level mới được tạo, sửa hoặc xóa tài khoản khách.', $source);
        $this->assertStringContainsString('Chỉ Admin level mới được sửa tài khoản khách.', $source);
        $this->assertStringContainsString('Chỉ Admin level mới được sửa owner account.', $source);
    }

    public function test_tenant_admin_ui_does_not_render_owner_edit_action(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/tenants.php');

        $this->assertStringContainsString('<?php if ($isSuperAdmin && $editingTenantAdmin): ?>', $source);
        $this->assertStringContainsString('<?php if ($isSuperAdmin): ?><th>Thao tác</th><?php endif; ?>', $source);
        $this->assertMatchesRegularExpression(
            '/<\\?php if \\(\\$isSuperAdmin\\): \\?>\\s*<td>[\\s\\S]*?<a href="\\?edit_tenant_admin=/',
            $source
        );
    }

    public function test_admin_api_tenant_create_is_super_admin_only(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminController.php');

        $this->assertStringContainsString("if (\$actor->role !== 'super_admin')", $source);
        $this->assertStringContainsString('Only Admin level can create customer accounts.', $source);
    }

    public function test_tenant_admin_views_hide_redundant_system_columns(): void
    {
        $domains = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/domains.php');
        $mailboxes = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/mailboxes.php');
        $dashboard = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/dashboard.php');

        $this->assertStringContainsString('$isTenantAdminView', $domains);
        $this->assertStringContainsString('<?php if (!$isTenantAdminView): ?><th>Khách hàng</th><?php endif; ?>', $domains);
        $this->assertStringContainsString('$isTenantAdminView', $mailboxes);
        $this->assertStringContainsString('<?php if (!$isTenantAdminView): ?><th>Khách hàng</th><?php endif; ?>', $mailboxes);
        $this->assertStringContainsString('$metric[\'label\'] !== \'Khách hàng\'', $dashboard);
        $this->assertStringContainsString('$item[\'label\'] !== \'Recent configs\'', $dashboard);
    }

    public function test_layout_skips_empty_permission_groups(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../src/Views/admin/layout.php');

        $this->assertStringContainsString('$visibleItems = array_values(array_filter($items', $layout);
        $this->assertStringContainsString('if ($visibleItems === [])', $layout);
    }

    public function test_admin_login_redirect_uses_permission_aware_landing_page(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Http/Controllers/AdminAuthController.php');

        $this->assertStringContainsString('adminLandingPath()', $source);
        $this->assertStringContainsString("can(\$actor, 'dashboard.view')", $source);
        $this->assertStringContainsString("can(\$actor, 'security.view')", $source);
    }

    public function test_domain_admin_is_not_shown_tenant_wide_quick_actions(): void
    {
        $files = [
            '/../src/Http/Controllers/AdminConfigDeploymentController.php',
            '/../src/Http/Controllers/AdminDashboardController.php',
            '/../src/Http/Controllers/AdminDomainController.php',
            '/../src/Http/Controllers/AdminMailboxController.php',
            '/../src/Http/Controllers/AdminPackageController.php',
            '/../src/Http/Controllers/AdminRoutingController.php',
            '/../src/Http/Controllers/AdminSecurityController.php',
            '/../src/Http/Controllers/AdminTenantController.php',
            '/../src/Http/Controllers/Traits/AdminWebLayoutTrait.php',
        ];

        foreach ($files as $file) {
            $source = (string) file_get_contents(__DIR__ . $file);
            $this->assertStringNotContainsString("['super_admin', 'tenant_admin', 'domain_admin']", $source, $file);
        }
    }
}
