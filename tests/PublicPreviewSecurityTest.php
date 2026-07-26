<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;

final class PublicPreviewSecurityTest extends TestCase
{
    public function test_admin_preview_has_explicit_production_and_local_request_guards(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../public/qa/admin-preview.php');

        $this->assertStringContainsString('Environment::load', $source);
        $this->assertStringContainsString('MAILPANEL_ENABLE_QA_PREVIEW', $source);
        $this->assertStringContainsString('$appEnv === \'production\'', $source);
        $this->assertStringContainsString('REMOTE_ADDR', $source);
        $this->assertStringContainsString('$isLocalRequest', $source);
        $this->assertStringContainsString('HTTP_X_FORWARDED_FOR', $source);
        $this->assertStringContainsString('HTTP_X_FORWARDED_HOST', $source);
        $this->assertStringContainsString('MAILPANEL_QA_PREVIEW_KEY', $source);
        $this->assertStringContainsString('hash_equals($previewKey, $requestPreviewKey)', $source);
        $this->assertStringContainsString('$strictLocalPreview', $source);
        $this->assertStringContainsString('http_response_code(404)', $source);
        $this->assertStringContainsString('X-Robots-Tag: noindex, nofollow, noarchive', $source);
    }
}
