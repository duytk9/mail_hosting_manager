<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Security\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function test_totp_secret_encryption_round_trip_is_backward_compatible(): void
    {
        $service = new TotpService('MailPanel', 1, 'test-encryption-key');
        $secret = 'JBSWY3DPEHPK3PXP';

        $encrypted = $service->protectSecret($secret);

        $this->assertStringStartsWith('enc:v1:', $encrypted);
        $this->assertNotSame($secret, $encrypted);
        $this->assertSame($secret, $service->revealSecret($encrypted));
        $this->assertSame($secret, $service->revealSecret($secret));
    }

    public function test_generate_secret_length_is_clamped_to_safe_range(): void
    {
        $service = new TotpService();

        $this->assertSame(16, strlen($service->generateSecret(1)));
        $this->assertSame(64, strlen($service->generateSecret(512)));
    }

    public function test_negative_totp_window_is_clamped_without_locking_valid_code(): void
    {
        $service = new TotpService('MailPanel', -99);

        $this->assertTrue($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59));
        $this->assertFalse($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '081804', 59));
    }

    public function test_large_totp_window_is_clamped_to_two_slices(): void
    {
        $service = new TotpService('MailPanel', 999);

        $this->assertTrue($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 119));
        $this->assertFalse($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 150));
    }

    public function test_issuer_is_sanitized_for_otpauth_uri(): void
    {
        $service = new TotpService('', 1);
        $uri = $service->otpauthUri('admin@example.test', 'JBSWY3DPEHPK3PXP');

        $this->assertStringStartsWith('otpauth://totp/MailPanel:admin%40example.test', $uri);
        $this->assertStringContainsString('issuer=MailPanel', $uri);
    }
}
