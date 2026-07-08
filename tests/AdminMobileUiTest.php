<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AdminMobileUiTest extends TestCase
{
    public function test_admin_mobile_styles_keep_forms_buttons_tables_and_modals_usable(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/admin.css');
        $layout = (string) file_get_contents(__DIR__ . '/../src/Views/admin/layout.php');
        $login = (string) file_get_contents(__DIR__ . '/../src/Views/admin/login.php');

        $this->assertStringContainsString('@media (max-width: 767px)', $css);
        $this->assertStringContainsString('font-size: 16px !important', $css);
        $this->assertStringContainsString('min-height: 48px !important', $css);
        $this->assertStringContainsString('min-height: 46px', $css);
        $this->assertStringContainsString('grid-template-columns: minmax(96px, 38%) minmax(0, 1fr) !important', $css);
        $this->assertStringContainsString('.action-menu__content.is-portaled', $css);
        $this->assertStringContainsString('bottom: calc(12px + env(safe-area-inset-bottom)) !important', $css);
        $this->assertStringContainsString('max-height: calc(100dvh - 16px) !important', $css);
        $this->assertStringContainsString('.admin-modal__panel::before', $css);
        $this->assertStringContainsString('@media (max-width: 380px)', $css);
        $this->assertStringContainsString('/assets/admin.css?v=20260708-form-polish', $layout);
        $this->assertStringContainsString('/assets/admin.css?v=20260708-form-polish', $login);
    }
}
