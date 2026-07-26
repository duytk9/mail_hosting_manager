<?php
declare(strict_types=1);
namespace MailPanel\Http\Controllers;
use MailPanel\Core\Request;
use MailPanel\Core\Response;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\SessionManager;
use MailPanel\Services\AdminSecurityService;
use MailPanel\Services\AppSecuritySettingsService;
use MailPanel\Services\AliasService;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\AuthService;
use MailPanel\Services\ConfigDeploymentService;
use MailPanel\Services\DashboardService;
use MailPanel\Services\DnsCheckService;
use MailPanel\Services\DomainService;
use MailPanel\Services\ForwardService;
use MailPanel\Services\MailGroupService;
use MailPanel\Services\MailboxService;
use MailPanel\Services\AcmeTlsService;
use MailPanel\Services\PackageService;
use MailPanel\Services\SuperAdminService;
use MailPanel\Services\TenantAdminService;
use MailPanel\Services\TenantLifecyclePolicy;
use MailPanel\Services\TenantService;
use MailPanel\Core\Database;
use MailPanel\Support\View;
use MailPanel\Support\UiMessage;
use MailPanel\Http\Controllers\Traits\AdminWebLayoutTrait;
use Throwable;

final class AdminDomainController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly DomainService $domainService,
        private readonly DnsCheckService $dnsCheckService,
        private readonly AcmeTlsService $acmeTlsService,
        private readonly TenantService $tenantService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view,
        private readonly AuditLogService $auditLog
    ) {
    }

    public function domains(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/domains')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('domains.view')) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create');

            if ($redirect = $this->guardPermission(
                $intent === 'create' ? 'domains.create' : ['domains.update', 'domains.delete'],
                '/admin/domains',
                null,
                true
            )) {
                return $redirect;
            }

            return $intent === 'create'
                ? $this->handleCreateDomain($request)
                : $this->handleDomainAction($request);
        }

        $tenantId = $this->currentTenantId();
        $domains = $this->filterRows(
            $this->domainService->list($tenantId),
            (string) ($request->query['search'] ?? ''),
            ['domain', 'status'],
            (string) ($request->query['status'] ?? '')
        );
        $domainSslSummary = [];
        foreach ($domains as $domain) {
            $domainSslSummary[(int) ($domain['id'] ?? 0)] = $this->acmeTlsService->summarizeDomainCertificate($domain);
        }
        $domainPage = $this->paginateRows($domains, $request, 'domains_page', 10);

        return Response::html($this->renderPage('admin/pages/domains.php', [
            'domains' => $domains,
            'domainRows' => $domainPage['items'],
            'domainsPagination' => $domainPage['meta'],
            'domainSslSummary' => $domainSslSummary,
            'tenants' => $this->tenantService->list($tenantId),
            'acmeDefaultEmail' => $this->defaultAcmeEmail(),
            'acmeDefaultProfile' => $this->defaultAcmeProfile(),
            'acmeAutoIssueEnabled' => $this->autoIssueOnCreateEnabled(),
            'acmeProfiles' => $this->acmeTlsService->profileOptions(),
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
                'status' => (string) ($request->query['status'] ?? ''),
            ],
        ], 'domains', 'Managed Domains', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('domains'),
        ]));
    }

    public function dnsChecks(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/dns-checks')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission(
            $request->method === 'POST' ? 'dns_checks.update' : 'dns_checks.view'
        )) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            return $this->handleDnsCheckAction($request);
        }

        $tenantId = $this->currentTenantId();
        $domains = $this->domainService->list($tenantId);
        $selectedDomain = null;
        $report = null;
        $certificateProfiles = null;
        $selectedDomainId = (int) ($request->query['domain_id'] ?? ($domains[0]['id'] ?? 0));

        if ($selectedDomainId > 0) {
            try {
                $this->assertTenantOwnsDomain($selectedDomainId);
                $selectedDomain = $this->domainService->find($selectedDomainId);

                if ($selectedDomain === null) {
                    throw new \InvalidArgumentException('Domain not found.');
                }

                $report = $this->dnsCheckService->inspectDomain($selectedDomain);
                $certificateProfiles = $this->acmeTlsService->inspectDomain($selectedDomain);
            } catch (Throwable $exception) {
                $this->sessions->flash('error', UiMessage::exception($exception));
                return Response::redirect('/admin/dns-checks');
            }
        }

        return Response::html($this->renderPage('admin/pages/dns_checks.php', [
            'domains' => $domains,
            'selectedDomain' => $selectedDomain,
            'report' => $report,
            'certificateProfiles' => $certificateProfiles,
            'acmeDefaultEmail' => $this->defaultAcmeEmail(),
            'acmeDefaultProfile' => $this->defaultAcmeProfile(),
        ], 'dns_checks', 'DNS / TLS Checks', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('dns_checks'),
        ]));
    }

    private function handleDomainAction(Request $request): Response
    {
        try {
            $action = (string) ($request->body['action'] ?? '');
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);

            if ($action === 'delete') {
                $this->domainService->delete($domainId);
                $this->sessions->flash('success', 'Xóa domain thành công.');
            } elseif ($action === 'rename_domain') {
                $newDomain = (string) ($request->body['new_domain'] ?? '');
                $this->domainService->renameDomain($domainId, $newDomain);
                $this->sessions->flash('success', 'Đổi tên miền thành công.');
            } elseif ($action === 'set_primary') {
                $this->domainService->setPrimary($domainId);
                $this->sessions->flash('success', 'Đã đặt primary domain thành công.');
            } elseif ($action === 'issue_acme_tls') {
                $domain = $this->domainService->find($domainId);
                if ($domain === null) {
                    throw new \InvalidArgumentException('Domain not found.');
                }

                $result = $this->acmeTlsService->issue($domain, [
                    'email' => $this->defaultAcmeEmail(),
                    'profile' => $this->automaticAcmeProfile($domain, (string) ($request->body['acme_profile'] ?? '')),
                ], $this->actorContext($request));

                $this->sessions->flash(
                    'success',
                    sprintf(
                        'Đã cấp/đồng bộ SSL SNI cho %s (%s).',
                        (string) ($domain['domain'] ?? ''),
                        (string) ($result['profile_label'] ?? ($result['profile'] ?? ''))
                    )
                );
            } else {
                $this->domainService->setStatus($domainId, $action);
                $this->sessions->flash('success', 'Cập nhật trạng thái domain thành công.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/domains');
    }

    private function handleCreateDomain(Request $request): Response
    {
        try {
            $tenantId = $this->isSuperAdmin()
                ? (int) ($request->body['tenant_id'] ?? 0)
                : (int) $this->currentTenantId();

            $domain = $this->domainService->create([
                'tenant_id' => $tenantId,
                'domain' => trim((string) ($request->body['domain'] ?? '')),
                'status' => (string) ($request->body['status'] ?? 'pending_dns'),
                'is_primary' => isset($request->body['is_primary']) ? 1 : 0,
                'inbound_enabled' => isset($request->body['inbound_enabled']) ? 1 : 0,
                'outbound_enabled' => isset($request->body['outbound_enabled']) ? 1 : 0,
                'dkim_enabled' => isset($request->body['dkim_enabled']) ? 1 : 0,
            ]);

            $message = 'Tạo domain thành công.';
            $tlsMessage = $this->autoIssueCertificateAfterCreate($domain, $request);
            if ($tlsMessage !== null) {
                $message .= ' ' . $tlsMessage;
            }
            $this->sessions->flash('success', $message);
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/domains');
    }

    private function handleDnsCheckAction(Request $request): Response
    {
        $domainId = (int) ($request->body['domain_id'] ?? 0);

        try {
            $this->assertTenantOwnsDomain($domainId);
            $domain = $this->domainService->find($domainId);
            if ($domain === null) {
                throw new \InvalidArgumentException('Domain not found.');
            }

            $intent = (string) ($request->body['_intent'] ?? '');
            if ($intent !== 'issue_acme_tls') {
                throw new \InvalidArgumentException('Unknown DNS checker action.');
            }

            $identity = $this->sessions->identity() ?? [];
            $payload = [
                'email' => (string) ($request->body['acme_email'] ?? ''),
                'profile' => (string) ($request->body['acme_profile'] ?? 'mail_only'),
            ];
            if (trim($payload['email']) === '') {
                $payload['email'] = $this->defaultAcmeEmail();
            }
            $actor = $this->actorContext($request, $identity);
            $acmeAction = (string) ($request->body['acme_action'] ?? 'issue');
            if ($acmeAction === 'renew') {
                $result = $this->acmeTlsService->renew($domain, $payload, $actor);
                $this->sessions->flash(
                    'success',
                    sprintf(
                        'Renew SSL ACME thành công cho %s (%s).',
                        (string) ($domain['domain'] ?? ''),
                        (string) ($result['profile_label'] ?? ($result['profile'] ?? ''))
                    )
                );

                return Response::redirect('/admin/dns-checks?domain_id=' . $domainId);
            }

            $result = $this->acmeTlsService->issue($domain, $payload, $actor);

            $this->sessions->flash(
                'success',
                sprintf(
                    'Đã cấp/đồng bộ SSL ACME cho %s (%s).',
                    (string) ($domain['domain'] ?? ''),
                    (string) ($result['profile_label'] ?? ($result['profile'] ?? ''))
                )
            );
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/dns-checks?domain_id=' . $domainId);
    }

    private function autoIssueCertificateAfterCreate(array $domain, Request $request): ?string
    {
        if (!$this->shouldAutoIssueCertificate($request)) {
            return null;
        }

        $email = trim((string) ($request->body['acme_email'] ?? ''));
        if ($email === '') {
            $email = $this->defaultAcmeEmail();
        }

        if ($email === '') {
            return 'SSL SNI chưa chạy vì chưa cấu hình ACME_EMAIL.';
        }

        try {
            $result = $this->acmeTlsService->issue($domain, [
                'email' => $email,
                'profile' => $this->automaticAcmeProfile($domain, (string) ($request->body['acme_profile'] ?? '')),
            ], $this->actorContext($request));

            return sprintf(
                'SSL SNI đã tự cấp/đồng bộ (%s).',
                (string) ($result['profile_label'] ?? ($result['profile'] ?? $this->defaultAcmeProfile()))
            );
        } catch (Throwable $exception) {
            return UiMessage::exception($exception, 'SSL SNI chưa cấp tự động.');
        }
    }

    private function shouldAutoIssueCertificate(Request $request): bool
    {
        if (array_key_exists('acme_auto_issue_present', $request->body)) {
            return isset($request->body['acme_auto_issue']);
        }

        return $this->autoIssueOnCreateEnabled();
    }

    private function autoIssueOnCreateEnabled(): bool
    {
        return (bool) ($this->mailpanelConfig['acme_auto_issue_on_domain_create'] ?? true);
    }

    private function defaultAcmeEmail(): string
    {
        return trim((string) ($this->mailpanelConfig['acme_email'] ?? ''));
    }

    private function defaultAcmeProfile(): string
    {
        $profile = (string) ($this->mailpanelConfig['acme_default_profile'] ?? 'mail_only');

        return $this->validAcmeProfile($profile) ? $profile : 'mail_only';
    }

    private function automaticAcmeProfile(array $domain, string $requestedProfile = ''): string
    {
        if ($this->validAcmeProfile($requestedProfile)) {
            return $requestedProfile;
        }

        try {
            $inspection = $this->acmeTlsService->inspectDomain($domain);
            foreach (($inspection['profiles'] ?? []) as $profile) {
                if (
                    ($profile['key'] ?? '') === 'mail_and_web'
                    && (!empty($profile['dns_ready']) || !empty($profile['certificate_ready']))
                ) {
                    return 'mail_and_web';
                }
            }
        } catch (Throwable) {
        }

        return $this->defaultAcmeProfile();
    }

    private function validAcmeProfile(string $profile): bool
    {
        return $profile !== '' && array_key_exists($profile, $this->acmeTlsService->profileOptions());
    }

    private function assertTenantOwnsDomain(int $domainId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $domain = $this->domainService->find($domainId);

        if ($domain === null || (int) ($domain['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Domain nằm ngoài tenant hiện tại.');
        }
    }

}
