<?php

declare(strict_types=1);

namespace MailPanel\Security;

use InvalidArgumentException;

final class IpAllowlist
{
    /**
     * @return array<int, string>
     */
    public static function normalizeEntries(string|array $entries): array
    {
        $values = is_array($entries)
            ? $entries
            : (preg_split('/(?:\r\n|\r|\n|,|;)+/', $entries) ?: []);

        $normalized = [];
        foreach ($values as $value) {
            $candidate = trim((string) $value);
            if ($candidate === '') {
                continue;
            }

            $normalized[] = self::normalizeEntry($candidate);
        }

        return array_values(array_unique($normalized));
    }

    public static function normalizeEntry(string $entry): string
    {
        $entry = trim($entry);
        if ($entry === '') {
            throw new InvalidArgumentException('IP allowlist entry cannot be empty.');
        }

        if (!str_contains($entry, '/')) {
            return self::normalizeIp($entry);
        }

        [$ip, $prefix] = explode('/', $entry, 2);
        $normalizedIp = self::normalizeIp($ip);
        $prefix = trim($prefix);

        if ($prefix === '' || !ctype_digit($prefix)) {
            throw new InvalidArgumentException(sprintf('Invalid CIDR prefix: %s', $entry));
        }

        $prefixLength = (int) $prefix;
        $maxPrefix = filter_var($normalizedIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        if ($prefixLength < 0 || $prefixLength > $maxPrefix) {
            throw new InvalidArgumentException(sprintf('CIDR prefix is out of range: %s', $entry));
        }

        return $normalizedIp . '/' . $prefixLength;
    }

    /**
     * @param array<int, string> $entries
     */
    public static function contains(string $ipAddress, array $entries): bool
    {
        $normalizedIp = self::normalizeIp($ipAddress);

        foreach ($entries as $entry) {
            $normalizedEntry = self::normalizeEntry((string) $entry);

            if (!str_contains($normalizedEntry, '/')) {
                if ($normalizedEntry === $normalizedIp) {
                    return true;
                }

                continue;
            }

            if (self::ipMatchesCidr($normalizedIp, $normalizedEntry)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeIp(string $ipAddress): string
    {
        $ipAddress = trim($ipAddress);
        $packed = @inet_pton($ipAddress);

        if ($packed === false) {
            throw new InvalidArgumentException(sprintf('Invalid IP address: %s', $ipAddress));
        }

        $normalized = inet_ntop($packed);
        if ($normalized === false) {
            throw new InvalidArgumentException(sprintf('Invalid IP address: %s', $ipAddress));
        }

        return strtolower($normalized);
    }

    private static function ipMatchesCidr(string $ipAddress, string $cidr): bool
    {
        [$networkAddress, $prefix] = explode('/', $cidr, 2);
        $ipBinary = inet_pton($ipAddress);
        $networkBinary = inet_pton($networkAddress);

        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($networkBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (~(0xff >> $remainingBits)) & 0xff;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($networkBinary[$fullBytes]) & $mask);
    }
}
