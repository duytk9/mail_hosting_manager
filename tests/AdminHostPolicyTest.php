<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Request;
use MailPanel\Security\Actor;
use MailPanel\Security\AdminHostPolicy;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Separating the super admin console onto its own hostname is only a real control
 * if it is enforced by the application, not just by the generated nginx config.
 */
final class AdminHostPolicyTest extends TestCase
{
    private function request(string $host, string $ip = '203.0.113.10'): Request
    {
        return new Request(
            'GET',
            '/admin/dashboard',
            [],
            [],
            ['REMOTE_ADDR' => $ip, 'HTTP_HOST' => $host],
            ['Host' => $host],
        );
    }

    private function policy(array $allowlist = [], bool $enforceIp = false): AdminHostPolicy
    {
        return new AdminHostPolicy('admin.example.com', 'panel.example.com', $allowlist, $enforceIp);
    }

    // --------------------------------------------------------------- disabled

    public function test_policy_is_disabled_when_no_admin_hostname_is_set(): void
    {
        $policy = new AdminHostPolicy('', 'panel.example.com');

        $this->assertFalse($policy->isEnabled());

        // Must not interfere with the default single-host deployment.
        $policy->assertActorMayUseHost(new Actor(1, 'super_admin'), $this->request('panel.example.com'));
        $this->addToAssertionCount(1);
    }

    public function test_policy_is_disabled_when_both_hostnames_are_identical(): void
    {
        $policy = new AdminHostPolicy('mail.example.com', 'mail.example.com');

        $this->assertFalse($policy->isEnabled());
    }

    // ------------------------------------------------------- role/host matrix

    public function test_super_admin_is_allowed_on_the_admin_hostname(): void
    {
        $this->policy()->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('admin.example.com')
        );

        $this->addToAssertionCount(1);
    }

    public function test_super_admin_is_refused_on_the_tenant_hostname(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden.');

        $this->policy()->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('panel.example.com')
        );
    }

    public function test_tenant_admin_is_refused_on_the_admin_hostname(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden.');

        $this->policy()->assertActorMayUseHost(
            new Actor(7, 'tenant_admin', 4),
            $this->request('admin.example.com')
        );
    }

    public function test_tenant_admin_is_allowed_on_the_tenant_hostname(): void
    {
        $this->policy()->assertActorMayUseHost(
            new Actor(7, 'tenant_admin', 4),
            $this->request('panel.example.com')
        );

        $this->addToAssertionCount(1);
    }

    public function test_support_readonly_is_refused_on_the_admin_hostname(): void
    {
        $this->expectException(RuntimeException::class);

        $this->policy()->assertActorMayUseHost(
            new Actor(9, 'support_readonly'),
            $this->request('admin.example.com')
        );
    }

    public function test_guest_is_not_blocked_so_the_login_page_stays_reachable(): void
    {
        $this->policy()->assertActorMayUseHost(new Actor(0, 'guest'), $this->request('admin.example.com'));

        $this->addToAssertionCount(1);
    }

    /**
     * A request with a Host the policy does not recognise (direct IP, spoofed
     * header, missing header) must not be treated as the admin hostname.
     */
    public function test_super_admin_is_refused_on_an_unknown_host(): void
    {
        $this->expectException(RuntimeException::class);

        $this->policy()->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('203.0.113.10')
        );
    }

    // -------------------------------------------------------- port handling

    /**
     * The port is stripped before comparison. Cookies ignore the port, so treating
     * :8443 as a distinct origin would be misleading.
     */
    public function test_port_is_ignored_when_matching_the_hostname(): void
    {
        $this->policy()->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('admin.example.com:8443')
        );

        $this->addToAssertionCount(1);
    }

    public function test_host_matching_is_case_insensitive(): void
    {
        $this->policy()->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('ADMIN.Example.COM')
        );

        $this->addToAssertionCount(1);
    }

    public function test_trailing_dot_is_normalised(): void
    {
        $this->assertSame('admin.example.com', AdminHostPolicy::normalizeHost('admin.example.com.'));
        $this->assertSame('admin.example.com', AdminHostPolicy::normalizeHost('  ADMIN.example.com:443 '));
        $this->assertSame('[::1]', AdminHostPolicy::normalizeHost('[::1]:8443'));
    }

    // ------------------------------------------------------- IP allowlisting

    public function test_admin_ip_allowlist_blocks_an_outside_address(): void
    {
        $policy = $this->policy(['198.51.100.0/24'], true);

        $this->expectException(RuntimeException::class);

        $policy->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('admin.example.com', '203.0.113.10')
        );
    }

    public function test_admin_ip_allowlist_permits_an_inside_address(): void
    {
        $policy = $this->policy(['198.51.100.0/24'], true);

        $policy->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('admin.example.com', '198.51.100.7')
        );

        $this->addToAssertionCount(1);
    }

    public function test_empty_allowlist_means_no_network_restriction(): void
    {
        $policy = $this->policy([], true);

        $policy->assertActorMayUseHost(
            new Actor(1, 'super_admin'),
            $this->request('admin.example.com', '203.0.113.10')
        );

        $this->addToAssertionCount(1);
    }

    // --------------------------------------------------- cookie scope guard

    /**
     * The whole split rests on host-only cookies. A parent-domain cookie would
     * make one session valid on both hostnames.
     */
    public function test_parent_cookie_domain_is_detected_as_defeating_separation(): void
    {
        $policy = $this->policy();

        $this->assertTrue($policy->cookieDomainDefeatsSeparation('.example.com'));
        $this->assertTrue($policy->cookieDomainDefeatsSeparation('example.com'));
    }

    public function test_host_only_or_narrow_cookie_domain_is_accepted(): void
    {
        $policy = $this->policy();

        $this->assertFalse($policy->cookieDomainDefeatsSeparation(''));
        $this->assertFalse($policy->cookieDomainDefeatsSeparation('admin.example.com'));
        $this->assertFalse($policy->cookieDomainDefeatsSeparation('other.example.net'));
    }

    public function test_cookie_guard_is_inert_while_separation_is_disabled(): void
    {
        $policy = new AdminHostPolicy('', '');

        $this->assertFalse($policy->cookieDomainDefeatsSeparation('.example.com'));
    }

    // -------------------------------------------------------- login routing

    public function test_login_host_is_reported_per_role(): void
    {
        $policy = $this->policy();

        $this->assertSame('admin.example.com', $policy->loginHostFor('super_admin'));
        $this->assertSame('panel.example.com', $policy->loginHostFor('tenant_admin'));
        $this->assertNull((new AdminHostPolicy('', ''))->loginHostFor('super_admin'));
    }
}
