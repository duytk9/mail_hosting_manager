<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class AdminUiClarityTest extends TestCase
{
    public function test_admin_clarity_styles_reduce_form_button_and_action_menu_clutter(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/admin.css');

        $this->assertStringContainsString('Admin UI clarity pass', $css);
        $this->assertStringContainsString('--control-height: 42px', $css);
        $this->assertStringContainsString('--control-height-sm: 34px', $css);
        $this->assertStringContainsString('.action-menu__content form:has(input[name="current_password"])', $css);
        $this->assertStringContainsString('.action-menu__content form:has(input[name="otp"])', $css);
        $this->assertStringContainsString('width: min(100% - 40px, 780px) !important', $css);
        $this->assertStringContainsString('width: min(100% - 40px, 440px) !important', $css);
        $this->assertStringContainsString('position: sticky', $css);
        $this->assertStringContainsString('grid-template-columns: repeat(2, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('Admin form polish final cascade', $css);
        $this->assertStringContainsString('.security-step-card', $css);
        $this->assertStringContainsString('.totp-secret-row', $css);
        $this->assertStringContainsString('.otp-input', $css);
    }
}
