<?php

declare(strict_types=1);

namespace MailPanel\Services;

use DateTimeImmutable;
use InvalidArgumentException;

final class TenantLifecyclePolicy
{
    public const ACTIVE = 'active';
    public const GRACE = 'grace';
    public const EXPIRED = 'expired';
    public const SUSPENDED = 'suspended';
    public const TERMINATED = 'terminated';

    private const ALLOWED = [
        self::ACTIVE,
        self::GRACE,
        self::EXPIRED,
        self::SUSPENDED,
        self::TERMINATED,
    ];

    public static function allowedStatuses(): array
    {
        return self::ALLOWED;
    }

    public static function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return self::ACTIVE;
        }

        if (!in_array($status, self::ALLOWED, true)) {
            throw new InvalidArgumentException('Invalid tenant billing status.');
        }

        return $status;
    }

    public static function effectiveStatus(array $tenant, ?int $now = null): string
    {
        $now ??= time();
        $configured = self::normalizeStatus((string) ($tenant['billing_status'] ?? self::ACTIVE));

        if ($configured === self::TERMINATED || self::timestamp($tenant['terminated_at'] ?? null) !== null) {
            return self::TERMINATED;
        }

        if ($configured === self::SUSPENDED || self::timestamp($tenant['suspended_at'] ?? null) !== null) {
            return self::SUSPENDED;
        }

        if ($configured === self::EXPIRED) {
            return self::EXPIRED;
        }

        $expiresAt = self::timestamp($tenant['expires_at'] ?? null);
        if ($expiresAt === null || $expiresAt > $now) {
            return self::ACTIVE;
        }

        $graceUntil = self::timestamp($tenant['grace_until'] ?? null);
        if ($graceUntil !== null && $graceUntil > $now) {
            return self::GRACE;
        }

        return self::EXPIRED;
    }

    public static function canProvision(array $tenant): bool
    {
        return (string) ($tenant['status'] ?? '') === 'active'
            && self::effectiveStatus($tenant) === self::ACTIVE;
    }

    public static function canUseMail(array $tenant): bool
    {
        return (string) ($tenant['status'] ?? '') === 'active'
            && in_array(self::effectiveStatus($tenant), [self::ACTIVE, self::GRACE], true);
    }

    public static function assertCanProvision(array $tenant): void
    {
        if (!self::canProvision($tenant)) {
            throw new InvalidArgumentException('Tenant subscription is not active; new mail resources are disabled.');
        }
    }

    public static function sqlMailAccessCondition(string $alias = 't'): string
    {
        if (preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $alias) !== 1) {
            throw new InvalidArgumentException('Invalid SQL table alias.');
        }

        return sprintf(
            "(%1\$s.status = 'active' AND %1\$s.suspended_at IS NULL AND %1\$s.terminated_at IS NULL AND COALESCE(%1\$s.billing_status, 'active') IN ('active', 'grace') AND (%1\$s.expires_at IS NULL OR %1\$s.expires_at > NOW() OR (%1\$s.grace_until IS NOT NULL AND %1\$s.grace_until > NOW())))",
            $alias
        );
    }

    public static function assertGraceWindow(?string $expiresAt, ?string $graceUntil): void
    {
        $expiresAtTimestamp = self::timestamp($expiresAt);
        $graceUntilTimestamp = self::timestamp($graceUntil);

        if ($expiresAtTimestamp === null && $graceUntilTimestamp !== null) {
            throw new InvalidArgumentException('Grace date requires an expiry date.');
        }

        if ($expiresAtTimestamp !== null && $graceUntilTimestamp === null) {
            throw new InvalidArgumentException('Grace date is required when an expiry date is set.');
        }

        if ($expiresAtTimestamp !== null && $graceUntilTimestamp !== null && ($graceUntilTimestamp - $expiresAtTimestamp) < 86400) {
            throw new InvalidArgumentException('Grace date must be at least 1 day after the expiry date.');
        }
    }

    public static function timestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    public static function sqlTimestamp(mixed $value, bool $endOfDay = false): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= $endOfDay ? ' 23:59:59' : ' 00:00:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) === 1) {
            $value = str_replace('T', ' ', $value) . ':00';
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new InvalidArgumentException('Invalid tenant lifecycle date.');
        }

        return (new DateTimeImmutable('@' . $timestamp))->format('Y-m-d H:i:s');
    }
}
