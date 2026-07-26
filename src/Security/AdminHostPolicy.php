<?php

declare(strict_types=1);

namespace MailPanel\Security;

use MailPanel\Core\Request;
use RuntimeException;

/**
 * Restricts which hostname each admin role may use.
 *
 * The super admin console is served from its own hostname (e.g. admin.example.com)
 * while tenant admins use the tenant panel hostname (e.g. panel.example.com).
 *
 * Two things are worth being explicit about:
 *
 * 1. Separating by PORT alone would achieve nothing. Cookies are not scoped by
 *    port (RFC 6265 section 8.5), so :443 and :8443 on the same hostname share a
 *    session. Only a different hostname gives real session separation.
 *
 * 2. Nginx `allow`/`deny` on the admin vhost is the outer layer, but the
 *    application must not assume nginx is configured correctly — a
 *    misconfiguration, a direct request to the PHP-FPM socket, or a future
 *    change to the generated config would otherwise silently remove the control.
 *    This class re-checks inside the request lifecycle.
 *
 * Disabled by default: with no admin hostname configured, every role keeps using
 * a single host and behaviour is unchanged.
 */
final class AdminHostPolicy
{
    /** Roles that may only ever be used on the super admin hostname. */
    private const SUPER_ADMIN_ROLES = ['super_admin'];

    /** Roles that belong on the tenant panel hostname. */
    private const TENANT_ROLES = ['tenant_admin', 'domain_admin', 'support_readonly'];

    private readonly string $adminHostname;
    private readonly string $panelHostname;

    /** @var array<int, string> */
    private readonly array $adminIpAllowlist;

    /**
     * @param array<int, string> $adminIpAllowlist
     */
    public function __construct(
        string $adminHostname = '',
        string $panelHostname = '',
        array $adminIpAllowlist = [],
        private readonly bool $enforceIpAllowlist = false,
    ) {
        $this->adminHostname = self::normalizeHost($adminHostname);
        $this->panelHostname = self::normalizeHost($panelHostname);
        $this->adminIpAllowlist = array_values(array_filter(array_map(
            static fn ($entry): string => trim((string) $entry),
            $adminIpAllowlist
        ), static fn (string $entry): bool => $entry !== ''));
    }

    /**
     * Host separation is only active once a dedicated admin hostname is set and it
     * differs from the tenant panel hostname.
     */
    public function isEnabled(): bool
    {
        return $this->adminHostname !== ''
            && $this->panelHostname !== ''
            && $this->adminHostname !== $this->panelHostname;
    }

    public function adminHostname(): string
    {
        return $this->adminHostname;
    }

    public function panelHostname(): string
    {
        return $this->panelHostname;
    }

    /**
     * @return array<int, string>
     */
    public function adminIpAllowlist(): array
    {
        return $this->adminIpAllowlist;
    }

    /**
     * Throws when the actor's role is not permitted on the request's hostname.
     */
    public function assertActorMayUseHost(Actor $actor, Request $request): void
    {
        if (!$this->isEnabled() || $actor->id <= 0) {
            return;
        }

        $host = self::normalizeHost($request->header('Host', '') ?? '');

        // An unknown Host (direct IP, missing header) is not the admin hostname,
        // so a super admin session is refused there.
        if (in_array($actor->role, self::SUPER_ADMIN_ROLES, true)) {
            if ($host !== $this->adminHostname) {
                throw new RuntimeException('Forbidden.');
            }

            $this->assertIpAllowed($request);

            return;
        }

        if (in_array($actor->role, self::TENANT_ROLES, true) && $host === $this->adminHostname) {
            throw new RuntimeException('Forbidden.');
        }
    }

    /**
     * Second layer behind the nginx allow/deny list on the admin vhost.
     */
    public function assertIpAllowed(Request $request): void
    {
        if (!$this->enforceIpAllowlist || $this->adminIpAllowlist === []) {
            return;
        }

        if (!IpAllowlist::contains($request->ip(), $this->adminIpAllowlist)) {
            throw new RuntimeException('Forbidden.');
        }
    }

    /**
     * Where a freshly authenticated actor belongs. Returns null when the actor is
     * already on the right host or separation is off.
     */
    public function loginHostFor(string $role): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        return in_array($role, self::SUPER_ADMIN_ROLES, true)
            ? $this->adminHostname
            : $this->panelHostname;
    }

    /**
     * A cookie domain covering both hostnames would let one session work on both,
     * defeating the split. Callers should surface this during boot rather than
     * discovering it after an incident.
     */
    public function cookieDomainDefeatsSeparation(string $cookieDomain): bool
    {
        $cookieDomain = self::normalizeHost(ltrim(trim($cookieDomain), '.'));

        if (!$this->isEnabled() || $cookieDomain === '') {
            return false;
        }

        $covers = static fn (string $host): bool => $host === $cookieDomain
            || str_ends_with($host, '.' . $cookieDomain);

        return $covers($this->adminHostname) && $covers($this->panelHostname);
    }

    /**
     * Strips the port, lowercases, and drops a trailing dot. The port is removed
     * deliberately: it carries no security meaning for cookie scope.
     */
    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return '';
        }

        // IPv6 literal, e.g. [::1]:8443
        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            if ($end !== false) {
                return substr($host, 0, $end + 1);
            }
        }

        $colon = strrpos($host, ':');
        if ($colon !== false && ctype_digit(substr($host, $colon + 1))) {
            $host = substr($host, 0, $colon);
        }

        return rtrim($host, '.');
    }
}
