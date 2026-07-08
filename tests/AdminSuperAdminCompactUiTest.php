<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AdminSuperAdminCompactUiTest extends TestCase
{
    public function test_layout_adds_role_class_to_admin_body(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../src/Views/admin/layout.php');

        $this->assertStringContainsString('$layoutRoleClass = \'role-\'', $layout);
        $this->assertStringContainsString('<body class="<?= htmlspecialchars($layoutRoleClass, ENT_QUOTES, \'UTF-8\') ?>">', $layout);
    }

    public function test_super_admin_compact_css_hides_only_helper_copy(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/admin.css');

        $this->assertStringContainsString('body.role-super_admin .page-description', $css);
        $this->assertStringContainsString('body.role-super_admin .panel-header > p:not(.critical-copy)', $css);
        $this->assertStringContainsString('body.role-super_admin .metric-card__hint', $css);
        $this->assertStringNotContainsString('body.role-super_admin .notice', $css);
        $this->assertStringNotContainsString('body.role-super_admin .table-note', $css);
    }

    public function test_critical_portal_warning_stays_visible_for_super_admin(): void
    {
        $portalSettingsView = (string) file_get_contents(__DIR__ . '/../src/Views/admin/pages/portal_settings.php');

        $this->assertStringContainsString('Portal Domain', $portalSettingsView);
        $this->assertStringContainsString('DNS A record', $portalSettingsView);
    }
}
