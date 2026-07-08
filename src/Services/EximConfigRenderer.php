<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Repositories\Pdo\AliasRepository;
use MailPanel\Repositories\Pdo\DkimKeyRepository;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\ForwardRepository;
use MailPanel\Repositories\Pdo\MailGroupMemberRepository;
use MailPanel\Repositories\Pdo\MailGroupRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\PackageRepository;
use MailPanel\Repositories\Pdo\QuotaUsageRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Support\SafePath;

final class EximConfigRenderer
{
    private readonly TlsCertificateInventory $tlsInventory;

    public function __construct(
        private readonly string $generatedRoot,
        private readonly DomainRepository $domains,
        private readonly MailboxRepository $mailboxes,
        private readonly AliasRepository $aliases,
        private readonly TenantRepository $tenants,
        private readonly string $tlsCertificate = '/etc/exim4/ssl/mailpanel.pem',
        private readonly string $tlsPrivateKey = '/etc/exim4/ssl/mailpanel.key',
        private readonly string $submissionPorts = '25 : 465 : 587',
        private readonly string $tlsOnConnectPorts = '465',
        ?TlsCertificateInventory $tlsInventory = null,
        private readonly ?MailGroupRepository $mailGroups = null,
        private readonly ?MailGroupMemberRepository $mailGroupMembers = null,
        private readonly ?QuotaUsageRepository $quotaUsage = null,
        private readonly ?PackageRepository $packages = null,
        private readonly ?DkimKeyRepository $dkimKeys = null,
        private readonly ?ForwardRepository $forwards = null,
        private readonly string $authPorts = '465 : 587',
    ) {
        $this->tlsInventory = $tlsInventory ?? new TlsCertificateInventory();
    }

    public function render(): array
    {
        $generatedRoot = SafePath::absolute($this->generatedRoot, 'generated root');
        $domains = $this->domains->all();
        $mailboxes = $this->mailboxes->all();
        $tenantIndex = [];
        $domainIndex = [];
        $mailboxIndex = [];

        foreach ($this->tenants->all() as $tenant) {
            $tenantIndex[(int) ($tenant['id'] ?? 0)] = $tenant;
        }

        $packageIndex = [];
        foreach ($this->packages?->all() ?? [] as $package) {
            $packageIndex[(int) ($package['id'] ?? 0)] = $package;
        }

        foreach ($domains as $domain) {
            $domainIndex[(int) ($domain['id'] ?? 0)] = $domain;
        }

        foreach ($mailboxes as $mailbox) {
            $mailboxIndex[(int) ($mailbox['id'] ?? 0)] = $mailbox;
        }

        $localDomainMap = $this->buildLocalDomainMap($domains, $tenantIndex);
        $tenantQuotaExceededDomainMap = $this->buildTenantQuotaExceededDomainMap(
            $domains,
            $tenantIndex,
            $this->quotaUsage?->tenantUsageMap() ?? []
        );
        $domainList = $localDomainMap === [] ? 'localhost' : implode(':', array_keys($localDomainMap));

        $allowedSenders = [];
        $aliasMap = [];
        foreach ($this->aliases->all() as $alias) {
            $source = strtolower(trim((string) ($alias['source_address'] ?? '')));
            $destinationMailbox = $mailboxIndex[(int) ($alias['destination_mailbox_id'] ?? 0)] ?? null;
            $destination = strtolower(trim((string) ($destinationMailbox['email'] ?? '')));

            if ($source === '' || $destination === '') {
                continue;
            }

            $allowedSenders[$source] = $destination;

            $destinationDomain = $domainIndex[(int) ($destinationMailbox['domain_id'] ?? 0)] ?? null;
            $destinationTenant = $tenantIndex[(int) ($destinationMailbox['tenant_id'] ?? 0)] ?? null;
            if (
                filter_var($source, FILTER_VALIDATE_EMAIL) !== false
                && filter_var($destination, FILTER_VALIDATE_EMAIL) !== false
                && $destinationDomain !== null
                && $destinationTenant !== null
                && $this->canMailboxReceive($destinationMailbox, $destinationDomain, $destinationTenant)
            ) {
                $aliasMap[$source] = $destination;
            }
        }

        ksort($allowedSenders);
        ksort($aliasMap);

        $smtpSubmitEnabled = [];
        $mailboxesReceiving = [];
        $receivingMailboxIndex = [];
        $mailboxHourlyLimits = [];
        $mailboxDailyLimits = [];
        $mailboxMessageSizeLimits = [];
        $mailboxDomainMap = [];
        $mailboxTenantMap = [];
        $domainHourlyLimits = [];
        $domainDailyLimits = [];
        $tenantHourlyLimits = [];
        $tenantDailyLimits = [];
        foreach ($mailboxes as $mailbox) {
            $email = strtolower(trim((string) ($mailbox['email'] ?? '')));
            $domain = $domainIndex[(int) ($mailbox['domain_id'] ?? 0)] ?? null;
            $tenant = $tenantIndex[(int) ($mailbox['tenant_id'] ?? 0)] ?? null;
            $package = $tenant === null ? null : ($packageIndex[(int) ($tenant['package_id'] ?? 0)] ?? null);

            if ($email === '' || $domain === null || $tenant === null) {
                continue;
            }

            if (!$this->canMailboxSubmit($mailbox, $domain, $tenant)) {
                if (!$this->canMailboxReceive($mailbox, $domain, $tenant)) {
                    continue;
                }
            }

            if ($this->canMailboxReceive($mailbox, $domain, $tenant)) {
                $mailboxesReceiving[$email] = '1';
                $receivingMailboxIndex[$email] = $mailbox;
            }

            if ($this->canMailboxSubmit($mailbox, $domain, $tenant)) {
                $smtpSubmitEnabled[$email] = '1';
                $domainName = strtolower(trim((string) ($domain['domain'] ?? '')));
                $tenantId = (int) ($tenant['id'] ?? 0);
                $hourlyLimit = max(1, (int) ($package['outbound_per_hour'] ?? 500));
                $dailyLimit = max(1, (int) ($package['outbound_per_day'] ?? 5000));
                $messageSizeLimitBytes = max(1, (int) ($package['max_message_size_mb'] ?? 25)) * 1024 * 1024;

                $mailboxHourlyLimits[$email] = (string) $hourlyLimit;
                $mailboxDailyLimits[$email] = (string) $dailyLimit;
                $mailboxMessageSizeLimits[$email] = (string) $messageSizeLimitBytes;
                if ($domainName !== '') {
                    $mailboxDomainMap[$email] = $domainName;
                    $domainHourlyLimits[$domainName] = (string) max((int) ($domainHourlyLimits[$domainName] ?? 0), $hourlyLimit);
                    $domainDailyLimits[$domainName] = (string) max((int) ($domainDailyLimits[$domainName] ?? 0), $dailyLimit);
                }
                if ($tenantId > 0) {
                    $mailboxTenantMap[$email] = (string) $tenantId;
                    $tenantHourlyLimits[(string) $tenantId] = (string) max((int) ($tenantHourlyLimits[(string) $tenantId] ?? 0), $hourlyLimit);
                    $tenantDailyLimits[(string) $tenantId] = (string) max((int) ($tenantDailyLimits[(string) $tenantId] ?? 0), $dailyLimit);
                }
            }
        }

        ksort($smtpSubmitEnabled);
        ksort($mailboxesReceiving);
        ksort($mailboxHourlyLimits);
        ksort($mailboxDailyLimits);
        ksort($mailboxMessageSizeLimits);
        ksort($mailboxDomainMap);
        ksort($mailboxTenantMap);
        ksort($domainHourlyLimits);
        ksort($domainDailyLimits);
        ksort($tenantHourlyLimits);
        ksort($tenantDailyLimits);

        $mailGroups = $this->mailGroups?->all() ?? [];
        $mailGroupMap = $this->buildMailGroupMap($mailGroups, $domainIndex, $tenantIndex);
        [$forwardMap, $forwardCopyMap] = $this->buildForwardMaps(
            $this->forwards?->all() ?? [],
            $domainIndex,
            $tenantIndex,
            $receivingMailboxIndex
        );
        $dkimMaps = $this->buildDkimMaps();

        $content = <<<CONF
# generated by MailPanel
# Debian Exim local ACL hook
deny
  authenticated = *
  condition = \${if and{{!eq{\$authenticated_id}{}}{!eq{\$sender_address}{}}{!eq{\${lc:\$sender_address}}{\${lc:\$authenticated_id}}}{!eq{\${lookup{\${lc:\$sender_address}}lsearch{/etc/exim4/mailpanel/allowed_senders.map}{\$value}{}}}{\${lc:\$authenticated_id}}}}{yes}{no}}
  message = MailPanel policy: sender address is not allowed for this mailbox

deny
  authenticated = *
  condition = \${if and{{!eq{\$authenticated_id}{}}{!eq{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/smtp_submit_enabled.map}{\$value}{}}}{1}}}{yes}{no}}
  message = MailPanel policy: outbound submission is disabled for this mailbox

deny
  condition = \${if eq{\${lookup{\${lc:\$domain}}lsearch{/etc/exim4/mailpanel/tenant_quota_exceeded_domains.map}{\$value}{0}}}{1}{yes}{no}}
  message = 552 MailPanel policy: tenant quota exceeded

deny
  authenticated = *
  ratelimit = \${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/outbound_mailbox_hourly.map}{\$value}{999999999}} / 1h / strict / \${lc:\$authenticated_id}
  message = MailPanel policy: outbound hourly limit exceeded for this mailbox

deny
  authenticated = *
  ratelimit = \${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/outbound_mailbox_daily.map}{\$value}{999999999}} / 1d / strict / \${lc:\$authenticated_id}
  message = MailPanel policy: outbound daily limit exceeded for this mailbox

deny
  authenticated = *
  condition = \${if and{{>{\$message_size}{0}}{>{\$message_size}{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/message_size_limit.map}{\$value}{0}}}}}{yes}{no}}
  message = 552 MailPanel policy: message exceeds package size limit

deny
  authenticated = *
  ratelimit = \${lookup{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_domain.map}{\$value}{}}}lsearch{/etc/exim4/mailpanel/outbound_domain_hourly.map}{\$value}{999999999}} / 1h / strict / \${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_domain.map}{\$value}{unknown}}
  message = MailPanel policy: outbound hourly limit exceeded for this domain

deny
  authenticated = *
  ratelimit = \${lookup{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_domain.map}{\$value}{}}}lsearch{/etc/exim4/mailpanel/outbound_domain_daily.map}{\$value}{999999999}} / 1d / strict / \${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_domain.map}{\$value}{unknown}}
  message = MailPanel policy: outbound daily limit exceeded for this domain

deny
  authenticated = *
  ratelimit = \${lookup{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_tenant.map}{\$value}{}}}lsearch{/etc/exim4/mailpanel/outbound_tenant_hourly.map}{\$value}{999999999}} / 1h / strict / \${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_tenant.map}{\$value}{unknown}}
  message = MailPanel policy: outbound hourly limit exceeded for this tenant

deny
  authenticated = *
  ratelimit = \${lookup{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_tenant.map}{\$value}{}}}lsearch{/etc/exim4/mailpanel/outbound_tenant_daily.map}{\$value}{999999999}} / 1d / strict / \${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/authenticated_tenant.map}{\$value}{unknown}}
  message = MailPanel policy: outbound daily limit exceeded for this tenant

deny
  condition = \${if and{{eq{\$authenticated_id}{}}{eq{\${lookup{\${lc:\$domain}}lsearch{/etc/exim4/mailpanel/local_domains.map}{\$value}{0}}}{0}}}{yes}{no}}
  message = MailPanel policy: unknown local domain
CONF;

        $mailAcl = <<<CONF
acl_mailpanel_check_mail:
  deny
    authenticated = *
    condition = \${if and{{!eq{\$authenticated_id}{}}{!eq{\$sender_address}{}}{!eq{\${lc:\$sender_address}}{\${lc:\$authenticated_id}}}{!eq{\${lookup{\${lc:\$sender_address}}lsearch{/etc/exim4/mailpanel/allowed_senders.map}{\$value}{}}}{\${lc:\$authenticated_id}}}}{yes}{no}}
    message = MailPanel policy: sender address is not allowed for this mailbox

  deny
    authenticated = *
    condition = \${if and{{!eq{\$authenticated_id}{}}{!eq{\${lookup{\${lc:\$authenticated_id}}lsearch{/etc/exim4/mailpanel/smtp_submit_enabled.map}{\$value}{}}}{1}}}{yes}{no}}
    message = MailPanel policy: outbound submission is disabled for this mailbox

  accept
CONF;

        $defaultTlsCertificate = $this->safeAbsoluteConfigPath($this->tlsCertificate, 'TLS certificate path');
        $defaultTlsPrivateKey = $this->safeAbsoluteConfigPath($this->tlsPrivateKey, 'TLS private key path');
        $submissionPorts = $this->safePortList($this->submissionPorts, 'submission ports');
        $tlsOnConnectPorts = $this->safePortList($this->tlsOnConnectPorts, 'TLS-on-connect ports');
        $authPorts = $this->safePortList($this->authPorts, 'SMTP AUTH ports');
        $authPortsRegex = $this->submissionAuthPortRegex($authPorts);

        $managedMacros = strtr(<<<'CONF'
MAIN_TLS_ENABLE = yes
MAIN_TLS_CERTIFICATE = ${lookup{${sg{$tls_in_sni}{[^A-Za-z0-9.-]}{}}}lsearch{/etc/exim4/mailpanel/tls_certificates.map}{$value}{__DEFAULT_CERTIFICATE__}}
MAIN_TLS_PRIVATEKEY = ${lookup{${sg{$tls_in_sni}{[^A-Za-z0-9.-]}{}}}lsearch{/etc/exim4/mailpanel/tls_privatekeys.map}{$value}{__DEFAULT_PRIVATEKEY__}}
MAIN_LOCAL_DOMAINS = @ : $primary_hostname : localhost : localhost.localdomain : lsearch;/etc/exim4/mailpanel/local_domains.map
MAIN_ACL_CHECK_MAIL = acl_mailpanel_check_mail
daemon_smtp_ports = __SUBMISSION_PORTS__
tls_on_connect_ports = __TLS_ON_CONNECT_PORTS__
CONF, [
            '__DEFAULT_CERTIFICATE__' => $defaultTlsCertificate,
            '__DEFAULT_PRIVATEKEY__' => $defaultTlsPrivateKey,
            '__SUBMISSION_PORTS__' => $submissionPorts,
            '__TLS_ON_CONNECT_PORTS__' => $tlsOnConnectPorts,
        ]);

        $authConfig = strtr(<<<'CONF'
dovecot_plain:
  driver = dovecot
  public_name = PLAIN
  server_advertise_condition = ${if and{{def:tls_in_cipher}{match{$received_port}{\N^(?:__AUTH_PORTS_REGEX__)$\N}}}{yes}{no}}
  server_socket = /var/spool/exim4/private/auth
  server_set_id = $auth1

dovecot_login:
  driver = dovecot
  public_name = LOGIN
  server_advertise_condition = ${if and{{def:tls_in_cipher}{match{$received_port}{\N^(?:__AUTH_PORTS_REGEX__)$\N}}}{yes}{no}}
  server_socket = /var/spool/exim4/private/auth
  server_set_id = $auth1
CONF, [
            '__AUTH_PORTS_REGEX__' => $authPortsRegex,
        ]);

        return [
            'service' => 'exim',
            'path' => $generatedRoot . '/exim/check_rcpt_local.acl',
            'content' => $content,
            'extras' => [
                [
                    'path' => $generatedRoot . '/exim/allowed_senders.map',
                    'content' => $this->renderLookupMap($allowedSenders),
                ],
                [
                    'path' => $generatedRoot . '/exim/aliases.map',
                    'content' => $this->renderLookupMap($aliasMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/smtp_submit_enabled.map',
                    'content' => $this->renderLookupMap($smtpSubmitEnabled),
                ],
                [
                    'path' => $generatedRoot . '/exim/local_domains.map',
                    'content' => $this->renderLookupMap($localDomainMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/tenant_quota_exceeded_domains.map',
                    'content' => $this->renderLookupMap($tenantQuotaExceededDomainMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/outbound_mailbox_hourly.map',
                    'content' => $this->renderLookupMap($mailboxHourlyLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/outbound_mailbox_daily.map',
                    'content' => $this->renderLookupMap($mailboxDailyLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/message_size_limit.map',
                    'content' => $this->renderLookupMap($mailboxMessageSizeLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/outbound_domain_hourly.map',
                    'content' => $this->renderLookupMap($domainHourlyLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/outbound_domain_daily.map',
                    'content' => $this->renderLookupMap($domainDailyLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/outbound_tenant_hourly.map',
                    'content' => $this->renderLookupMap($tenantHourlyLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/outbound_tenant_daily.map',
                    'content' => $this->renderLookupMap($tenantDailyLimits),
                ],
                [
                    'path' => $generatedRoot . '/exim/authenticated_domain.map',
                    'content' => $this->renderLookupMap($mailboxDomainMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/authenticated_tenant.map',
                    'content' => $this->renderLookupMap($mailboxTenantMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/mailboxes.map',
                    'content' => $this->renderLookupMap($mailboxesReceiving),
                ],
                [
                    'path' => $generatedRoot . '/exim/mail_groups.map',
                    'content' => $this->renderLookupMap($mailGroupMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/forwards.map',
                    'content' => $this->renderLookupMap($forwardMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/forward_copies.map',
                    'content' => $this->renderLookupMap($forwardCopyMap),
                ],
                [
                    'path' => $generatedRoot . '/exim/tls_certificates.map',
                    'content' => $this->renderTlsLookupMap('certificate'),
                ],
                [
                    'path' => $generatedRoot . '/exim/tls_privatekeys.map',
                    'content' => $this->renderTlsLookupMap('privatekey'),
                ],
                [
                    'path' => $generatedRoot . '/exim/dkim_domains.map',
                    'content' => $this->renderLookupMap($dkimMaps['domains']),
                ],
                [
                    'path' => $generatedRoot . '/exim/dkim_selectors.map',
                    'content' => $this->renderLookupMap($dkimMaps['selectors']),
                ],
                [
                    'path' => $generatedRoot . '/exim/dkim_privatekeys.map',
                    'content' => $this->renderLookupMap($dkimMaps['privatekeys']),
                ],
                [
                    'path' => $generatedRoot . '/exim/exim4.conf.localmacros.managed',
                    'content' => $managedMacros . "\n",
                ],
                [
                    'path' => $generatedRoot . '/exim/mailpanel-acl-mail.conf',
                    'content' => $mailAcl . "\n",
                ],
                [
                    'path' => $generatedRoot . '/exim/mailpanel-router.conf',
                    'content' => $this->renderRouterSnippet(),
                ],
                [
                    'path' => $generatedRoot . '/exim/mailpanel-transport.conf',
                    'content' => $this->renderTransportSnippet(),
                ],
                [
                    'path' => $generatedRoot . '/exim/mailpanel-auth.conf',
                    'content' => $authConfig . "\n",
                ],
            ],
        ];
    }

    private function canMailboxSubmit(array $mailbox, array $domain, array $tenant): bool
    {
        return (string) ($mailbox['status'] ?? '') === 'active'
            && (int) ($mailbox['smtp_enabled'] ?? 0) === 1
            && (string) ($domain['status'] ?? '') === 'active'
            && (int) ($domain['outbound_enabled'] ?? 0) === 1
            && TenantLifecyclePolicy::canUseMail($tenant);
    }

    private function canMailboxReceive(array $mailbox, array $domain, array $tenant): bool
    {
        return (string) ($mailbox['status'] ?? '') === 'active'
            && $this->canDomainReceive($domain, $tenant);
    }

    private function canDomainReceive(array $domain, array $tenant): bool
    {
        return (string) ($domain['status'] ?? '') === 'active'
            && (int) ($domain['inbound_enabled'] ?? 0) === 1
            && TenantLifecyclePolicy::canUseMail($tenant);
    }

    /**
     * @param array<int, array<string, mixed>> $forwards
     * @param array<int, array<string, mixed>> $domainIndex
     * @param array<int, array<string, mixed>> $tenantIndex
     * @param array<string, array<string, mixed>> $receivingMailboxIndex
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function buildForwardMaps(
        array $forwards,
        array $domainIndex,
        array $tenantIndex,
        array $receivingMailboxIndex
    ): array {
        $forwardMap = [];
        $forwardCopyMap = [];

        foreach ($forwards as $forward) {
            $source = strtolower(trim((string) ($forward['source_address'] ?? '')));
            $destination = strtolower(trim((string) ($forward['destination_address'] ?? '')));
            $domain = $domainIndex[(int) ($forward['domain_id'] ?? 0)] ?? null;
            $tenant = $tenantIndex[(int) ($forward['tenant_id'] ?? 0)] ?? null;

            if (
                $source === ''
                || $destination === ''
                || filter_var($source, FILTER_VALIDATE_EMAIL) === false
                || filter_var($destination, FILTER_VALIDATE_EMAIL) === false
                || $source === $destination
                || $domain === null
                || $tenant === null
                || !$this->canDomainReceive($domain, $tenant)
            ) {
                continue;
            }

            $forwardMap[$source] = $destination;

            if ((int) ($forward['keep_copy'] ?? 0) === 1 && isset($receivingMailboxIndex[$source])) {
                $forwardCopyMap[$source] = '1';
            }
        }

        ksort($forwardMap);
        ksort($forwardCopyMap);

        return [$forwardMap, $forwardCopyMap];
    }

    /**
     * @param array<int, array<string, mixed>> $mailGroups
     * @param array<int, array<string, mixed>> $domainIndex
     * @param array<int, array<string, mixed>> $tenantIndex
     * @return array<string, string>
     */
    private function buildMailGroupMap(array $mailGroups, array $domainIndex, array $tenantIndex): array
    {
        if ($mailGroups === [] || $this->mailGroupMembers === null) {
            return [];
        }

        $groupIds = [];
        foreach ($mailGroups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            if ($groupId > 0) {
                $groupIds[] = $groupId;
            }
        }

        $memberRows = $this->mailGroupMembers->forGroupIds($groupIds);
        $membersByGroup = [];

        foreach ($memberRows as $member) {
            $groupId = (int) ($member['group_id'] ?? 0);
            $recipient = strtolower(trim((string) ($member['recipient_address'] ?? '')));

            if ($groupId <= 0 || $recipient === '' || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $membersByGroup[$groupId][$recipient] = $recipient;
        }

        $map = [];
        foreach ($mailGroups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            $email = strtolower(trim((string) ($group['email'] ?? '')));
            $domain = $domainIndex[(int) ($group['domain_id'] ?? 0)] ?? null;
            $tenant = $tenantIndex[(int) ($group['tenant_id'] ?? 0)] ?? null;

            if ($groupId <= 0 || $email === '' || $domain === null || $tenant === null) {
                continue;
            }

            if (!$this->canGroupReceive($group, $domain, $tenant)) {
                continue;
            }

            $recipients = array_values(array_filter(
                $membersByGroup[$groupId] ?? [],
                static fn (string $recipient): bool => $recipient !== $email
            ));

            if ($recipients === []) {
                continue;
            }

            sort($recipients);
            $map[$email] = implode(',', $recipients);
        }

        ksort($map);

        return $map;
    }

    private function canGroupReceive(array $group, array $domain, array $tenant): bool
    {
        return (string) ($group['status'] ?? '') === 'active'
            && $this->canDomainReceive($domain, $tenant);
    }

    /**
     * @param array<int, array<string, mixed>> $domains
     * @param array<int, array<string, mixed>> $tenantIndex
     * @return array<string, string>
     */
    private function buildLocalDomainMap(array $domains, array $tenantIndex): array
    {
        $map = [];

        foreach ($domains as $domain) {
            $name = strtolower(trim((string) ($domain['domain'] ?? '')));
            $tenant = $tenantIndex[(int) ($domain['tenant_id'] ?? 0)] ?? null;

            if ($name === '' || $tenant === null || !$this->canDomainReceive($domain, $tenant)) {
                continue;
            }

            $map[$name] = '1';
        }

        ksort($map);

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $domains
     * @param array<int, array<string, mixed>> $tenantIndex
     * @param array<int, int> $tenantUsageMap
     * @return array<string, string>
     */
    private function buildTenantQuotaExceededDomainMap(array $domains, array $tenantIndex, array $tenantUsageMap): array
    {
        $map = [];

        foreach ($domains as $domain) {
            $name = strtolower(trim((string) ($domain['domain'] ?? '')));
            $tenantId = (int) ($domain['tenant_id'] ?? 0);
            $tenant = $tenantIndex[$tenantId] ?? null;

            if ($name === '' || $tenant === null || !$this->canDomainReceive($domain, $tenant)) {
                continue;
            }

            $limitMb = max(0, (int) ($tenant['max_total_quota_mb'] ?? 0));
            $usedMb = max(0, (int) ($tenantUsageMap[$tenantId] ?? 0));
            if ($limitMb > 0 && $usedMb >= $limitMb) {
                $map[$name] = '1';
            }
        }

        ksort($map);

        return $map;
    }

    private function renderRouterSnippet(): string
    {
        return <<<'CONF'
mailpanel_alias_redirect:
  driver = redirect
  domains = +local_domains
  allow_defer
  allow_fail
  retry_use_local_part
  condition = ${if !eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/aliases.map}{$value}{}}}{}{yes}{no}}
  data = ${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/aliases.map}{$value}{}}

mailpanel_group_redirect:
  driver = redirect
  domains = +local_domains
  allow_defer
  allow_fail
  retry_use_local_part
  condition = ${if !eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/mail_groups.map}{$value}{}}}{}{yes}{no}}
  data = ${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/mail_groups.map}{$value}{}}

mailpanel_forward_copy:
  driver = redirect
  domains = +local_domains
  allow_defer
  allow_fail
  retry_use_local_part
  unseen
  condition = ${if and{{!eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/forwards.map}{$value}{}}}{}}{eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/forward_copies.map}{$value}{0}}}{1}}}{yes}{no}}
  data = ${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/forwards.map}{$value}{}}

mailpanel_forward_redirect:
  driver = redirect
  domains = +local_domains
  allow_defer
  allow_fail
  retry_use_local_part
  condition = ${if and{{!eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/forwards.map}{$value}{}}}{}}{!eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/forward_copies.map}{$value}{0}}}{1}}}{yes}{no}}
  data = ${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/forwards.map}{$value}{}}

mailpanel_virtual_mailbox:
  driver = accept
  domains = +local_domains
  retry_use_local_part
  condition = ${if eq{${lookup{$local_part@$domain}lsearch{/etc/exim4/mailpanel/mailboxes.map}{$value}{0}}}{1}{yes}{no}}
  transport = mailpanel_dovecot_lmtp

mailpanel_remote_smtp:
  driver = dnslookup
  domains = ! +local_domains
  transport = mailpanel_remote_smtp
  ignore_target_hosts = 0.0.0.0 : 127.0.0.0/8 : ::1
  no_more
CONF . "\n";
    }

    private function renderTransportSnippet(): string
    {
        return <<<'CONF'
mailpanel_dovecot_lmtp:
  driver = lmtp
  socket = /run/dovecot/lmtp
  batch_max = 1

mailpanel_remote_smtp:
  driver = smtp
  dkim_domain = ${lookup{${lc:$sender_address_domain}}lsearch{/etc/exim4/mailpanel/dkim_domains.map}{$value}{}}
  dkim_selector = ${lookup{${lc:$sender_address_domain}}lsearch{/etc/exim4/mailpanel/dkim_selectors.map}{$value}{}}
  dkim_private_key = ${lookup{${lc:$sender_address_domain}}lsearch{/etc/exim4/mailpanel/dkim_privatekeys.map}{$value}{}}
  dkim_canon = relaxed
  dkim_strict = false
CONF . "\n";
    }

    /**
     * @return array{domains: array<string, string>, selectors: array<string, string>, privatekeys: array<string, string>}
     */
    private function buildDkimMaps(): array
    {
        $maps = [
            'domains' => [],
            'selectors' => [],
            'privatekeys' => [],
        ];

        foreach ($this->dkimKeys?->activeSigningKeys() ?? [] as $row) {
            $domain = strtolower(trim((string) ($row['domain'] ?? '')));
            $selector = trim((string) ($row['selector_name'] ?? 'mail'));
            $privateKeyPath = trim((string) ($row['private_key_path'] ?? ''));

            if (!$this->isSafeDomain($domain)
                || !preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $selector)
                || !$this->isSafeAbsolutePath($privateKeyPath)
            ) {
                continue;
            }

            $maps['domains'][$domain] = $domain;
            $maps['selectors'][$domain] = $selector;
            $maps['privatekeys'][$domain] = $privateKeyPath;
        }

        ksort($maps['domains']);
        ksort($maps['selectors']);
        ksort($maps['privatekeys']);

        return $maps;
    }

    private function isSafeDomain(string $domain): bool
    {
        return $domain !== ''
            && strlen($domain) <= 253
            && preg_match('/\A[A-Za-z0-9.-]+\z/', $domain) === 1
            && str_contains($domain, '.');
    }

    private function isSafeAbsolutePath(string $path): bool
    {
        if ($path === '' || $path === '/' || !str_starts_with($path, '/') || preg_match('/\A[\/A-Za-z0-9._-]+\z/', $path) !== 1) {
            return false;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== ''));

        return !in_array('..', $segments, true);
    }

    private function safeAbsoluteConfigPath(string $path, string $label): string
    {
        $path = trim($path);
        if (!$this->isSafeAbsolutePath($path)) {
            throw new \InvalidArgumentException(sprintf('Invalid %s for Exim config.', $label));
        }

        return $path;
    }

    private function safePortList(string $ports, string $label): string
    {
        $ports = trim($ports);
        if ($ports === '' || preg_match('/\A[0-9 :]+\z/', $ports) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid %s for Exim config.', $label));
        }

        $items = array_filter(array_map('trim', explode(':', $ports)), static fn (string $port): bool => $port !== '');
        foreach ($items as $port) {
            $number = (int) $port;
            if ((string) $number !== $port || $number < 1 || $number > 65535) {
                throw new \InvalidArgumentException(sprintf('Invalid %s for Exim config.', $label));
            }
        }

        return implode(' : ', $items);
    }

    private function submissionAuthPortRegex(string $ports): string
    {
        $items = array_filter(
            array_map('trim', explode(':', $ports)),
            static fn (string $port): bool => $port !== '' && $port !== '25'
        );

        if ($items === []) {
            return '587';
        }

        return implode('|', array_map(
            static fn (string $port): string => preg_quote($port, '/'),
            array_values(array_unique($items))
        ));
    }

    private function renderTlsLookupMap(string $field): string
    {
        $rows = [];

        foreach ($this->tlsInventory->entries() as $hostname => $item) {
            $value = (string) ($item[$field] ?? '');
            if ($value === '') {
                continue;
            }

            $rows[(string) $hostname] = $value;
        }

        return $this->renderLookupMap($rows);
    }

    /**
     * @param array<string, string> $rows
     */
    private function renderLookupMap(array $rows): string
    {
        if ($rows === []) {
            return "# generated by MailPanel\n";
        }

        $lines = ["# generated by MailPanel"];
        foreach ($rows as $key => $value) {
            $key = trim((string) $key);
            $value = trim((string) $value);
            if (!$this->isSafeLookupKey($key) || !$this->isSafeLookupValue($value)) {
                continue;
            }
            $lines[] = sprintf('%s:%s', $key, $value);
        }

        return implode("\n", $lines) . "\n";
    }

    private function isSafeLookupKey(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 320
            && !str_contains($value, ':')
            && preg_match('/\A[^\x00-\x20\x7F]+\z/', $value) === 1;
    }

    private function isSafeLookupValue(string $value): bool
    {
        return $value !== ''
            && strlen($value) <= 4096
            && preg_match('/\A[^\x00-\x1F\x7F]+\z/', $value) === 1
            && !str_contains($value, "\r")
            && !str_contains($value, "\n");
    }
}
