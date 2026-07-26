<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Tests\Support\ControllerSource;
use PHPUnit\Framework\TestCase;

final class AdminWebControllerAutoAcmeTest extends TestCase
{
    private string $controller;

    protected function setUp(): void
    {
        $this->controller = ControllerSource::adminWeb();
    }

    public function test_domain_create_runs_acme_best_effort_after_database_create(): void
    {
        $this->assertStringContainsString('$domain = $this->domainService->create([', $this->controller);
        $this->assertStringContainsString('$this->autoIssueCertificateAfterCreate($domain, $request)', $this->controller);
        $this->assertStringContainsString("UiMessage::exception(\$exception, 'SSL SNI chưa cấp tự động.')", $this->controller);
        $this->assertStringNotContainsString('SSL SNI chưa cấp tự động: \' . $exception->getMessage()', $this->controller);
    }

    public function test_domain_action_can_issue_sni_without_direct_shell(): void
    {
        $this->assertStringContainsString("} elseif (\$action === 'issue_acme_tls') {", $this->controller);
        $this->assertStringContainsString('$this->acmeTlsService->issue($domain, [', $this->controller);
        $this->assertStringContainsString('$this->automaticAcmeProfile($domain', $this->controller);
        $this->assertStringContainsString("return 'mail_and_web';", $this->controller);
        $this->assertStringNotContainsString('shell_exec(', $this->controller);
        $this->assertStringNotContainsString('exec(', $this->controller);
    }
}
