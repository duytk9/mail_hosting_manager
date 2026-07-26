<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\TenantLifecyclePolicy;
use PHPUnit\Framework\TestCase;

final class TenantLifecyclePolicyTest extends TestCase
{
    public function test_active_tenant_without_expiry_can_provision_and_use_mail(): void
    {
        $tenant = ['status' => 'active', 'billing_status' => 'active'];

        $this->assertSame('active', TenantLifecyclePolicy::effectiveStatus($tenant, 1000));
        $this->assertTrue(TenantLifecyclePolicy::canProvision($tenant));
        $this->assertTrue(TenantLifecyclePolicy::canUseMail($tenant));
    }

    public function test_grace_tenant_keeps_existing_mail_but_cannot_provision(): void
    {
        $now = time();
        $tenant = [
            'status' => 'active',
            'billing_status' => 'active',
            'expires_at' => date('Y-m-d H:i:s', $now - 86400),
            'grace_until' => date('Y-m-d H:i:s', $now + (7 * 86400)),
        ];

        $this->assertSame('grace', TenantLifecyclePolicy::effectiveStatus($tenant, $now));
        $this->assertFalse(TenantLifecyclePolicy::canProvision($tenant));
        $this->assertTrue(TenantLifecyclePolicy::canUseMail($tenant));
    }

    public function test_expired_tenant_cannot_provision_or_use_mail(): void
    {
        $tenant = [
            'status' => 'active',
            'billing_status' => 'active',
            'expires_at' => '2026-01-01 00:00:00',
            'grace_until' => '2026-01-02 00:00:00',
        ];
        $now = strtotime('2026-01-05 00:00:00');

        $this->assertSame('expired', TenantLifecyclePolicy::effectiveStatus($tenant, $now));
        $this->assertFalse(TenantLifecyclePolicy::canProvision($tenant));
        $this->assertFalse(TenantLifecyclePolicy::canUseMail($tenant));
    }

    public function test_grace_window_requires_expiry_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Grace date requires an expiry date.');

        TenantLifecyclePolicy::assertGraceWindow(null, '2026-01-02 23:59:59');
    }

    public function test_grace_window_is_required_when_expiry_is_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Grace date is required when an expiry date is set.');

        TenantLifecyclePolicy::assertGraceWindow('2026-01-01 23:59:59', null);
    }

    public function test_grace_window_must_be_at_least_one_day_after_expiry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Grace date must be at least 1 day after the expiry date.');

        TenantLifecyclePolicy::assertGraceWindow('2026-01-01 23:59:59', '2026-01-02 00:00:00');
    }

    public function test_grace_window_accepts_next_day_or_later(): void
    {
        TenantLifecyclePolicy::assertGraceWindow('2026-01-01 23:59:59', '2026-01-02 23:59:59');

        $this->addToAssertionCount(1);
    }

    public function test_sql_mail_access_condition_rejects_unsafe_alias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid SQL table alias.');

        TenantLifecyclePolicy::sqlMailAccessCondition('t; DROP TABLE tenants');
    }

    public function test_sql_mail_access_condition_accepts_safe_alias(): void
    {
        $condition = TenantLifecyclePolicy::sqlMailAccessCondition('mailbox_tenant');

        $this->assertStringContainsString('mailbox_tenant.status', $condition);
        $this->assertStringContainsString("COALESCE(mailbox_tenant.billing_status, 'active')", $condition);
    }
}
