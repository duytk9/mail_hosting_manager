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

final class AdminMailboxController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly MailboxService $mailboxService,
        private readonly DomainService $domainService,
        private readonly TenantService $tenantService,
        // Required by the trait's assertCurrentAdminSensitiveAction(), used when
        // resetting a mailbox password.
        private readonly AdminSecurityService $adminSecurityService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view
    ) {
    }

    public function mailboxes(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/mailboxes')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('mailboxes.view')) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create');

            if ($redirect = $this->guardPermission(
                $intent === 'create' ? 'mailboxes.create' : ['mailboxes.update', 'mailboxes.delete'],
                '/admin/mailboxes',
                null,
                true
            )) {
                return $redirect;
            }

            return $intent === 'create'
                ? $this->handleCreateMailbox($request)
                : $this->handleMailboxAction($request);
        }

        $tenantId = $this->currentTenantId();
        $mailboxes = $this->mailboxService->list($tenantId);
        $domains = $this->domainService->list($tenantId);
        $tenants = $this->tenantService->list($tenantId);
        $tenantQuotaProfiles = [];

        foreach ($tenants as $tenant) {
            $tenantQuotaProfiles[(int) ($tenant['id'] ?? 0)] = $this->mailboxService->tenantQuotaProfile((int) ($tenant['id'] ?? 0), $tenant);
        }
        
        $domainIdFilter = (int) ($request->query['domain_id'] ?? 0);
        if ($domainIdFilter > 0) {
            $mailboxes = array_filter($mailboxes, fn($mb) => (int) ($mb['domain_id'] ?? 0) === $domainIdFilter);
        }

        $mailboxes = $this->filterRows(
            $mailboxes,
            (string) ($request->query['search'] ?? ''),
            ['email', 'display_name', 'local_part', 'status'],
            (string) ($request->query['status'] ?? '')
        );
        $mailboxes = array_values($mailboxes);
        $mailboxPage = $this->paginateRows($mailboxes, $request, 'mailboxes_page', 10);

        return Response::html($this->renderPage('admin/pages/mailboxes.php', [
            'mailboxes' => $mailboxes,
            'mailboxRows' => $mailboxPage['items'],
            'mailboxesPagination' => $mailboxPage['meta'],
            'domains' => $domains,
            'tenants' => $tenants,
            'tenantQuotaProfiles' => $tenantQuotaProfiles,
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
                'status' => (string) ($request->query['status'] ?? ''),
                'domain_id' => $domainIdFilter > 0 ? (string) $domainIdFilter : '',
            ],
        ], 'mailboxes', 'Mail Accounts', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('mailboxes'),
        ]));
    }

    private function handleMailboxAction(Request $request): Response
    {
        try {
            $action = (string) ($request->body['action'] ?? '');
            $mailboxId = (int) ($request->body['mailbox_id'] ?? 0);
            $this->assertTenantOwnsMailbox($mailboxId);

            if ($action === 'delete') {
                $this->mailboxService->delete($mailboxId);
                $this->sessions->flash('success', 'Xóa mail account thành công.');
            } elseif ($action === 'password') {
                $this->assertCurrentAdminSensitiveAction($request);
                $password = bin2hex(random_bytes(16)) . 'A1@';
                $this->mailboxService->changePassword($mailboxId, $password);
                $this->sessions->flash('success', 'Reset mật khẩu mail account thành công. Mật khẩu mới là: [' . $password . ']');
            } elseif ($action === 'quota') {
                $quotaMb = (int) ($request->body['quota_mb'] ?? 0);
                $this->mailboxService->updateQuota($mailboxId, $quotaMb);
                $this->sessions->flash('success', 'Cập nhật quota mail account thành công.');
            } else {
                $this->mailboxService->setStatus($mailboxId, $action);
                $this->sessions->flash('success', 'Cập nhật trạng thái mail account thành công.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/mailboxes');
    }

    private function handleCreateMailbox(Request $request): Response
    {
        try {
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);

            $this->mailboxService->create([
                'domain_id' => $domainId,
                'local_part' => trim((string) ($request->body['local_part'] ?? '')),
                'password' => (string) ($request->body['password'] ?? ''),
                'display_name' => trim((string) ($request->body['display_name'] ?? '')),
                'quota_mb' => (int) ($request->body['quota_mb'] ?? 1024),
                'force_password_change' => isset($request->body['force_password_change']) ? 1 : 0,
            ]);
            $this->sessions->flash('success', 'Tạo mail account thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/mailboxes');
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
