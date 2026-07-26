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

final class AdminDashboardController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view,
        private readonly DashboardService $dashboardService,
        private readonly ConfigDeploymentService $configDeploymentService,
        private readonly TenantService $tenantService,
        private readonly DomainService $domainService,
        private readonly MailboxService $mailboxService,
        private readonly AliasService $aliasService,
        private readonly ForwardService $forwardService
    ) {
    }

    public function home(Request $request): Response
    {
        return $this->isAdminAuthenticated()
            ? Response::redirect($this->mustForcePasswordChange() ? '/admin/security' : '/admin/dashboard')
            : Response::redirect('/admin/login');
    }

    public function dashboard(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/dashboard')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('dashboard.view')) {
            return $redirect;
        }

        $identity = $this->sessions->identity() ?? [];
        $configVersions = $this->isSuperAdmin()
            ? $this->configDeploymentService->listVersions()
            : [];

        if ($this->isTenantAdmin()) {
            $tenantId = $this->currentTenantId();
            $domains = $this->domainService->list($tenantId);
            $mailboxes = $this->mailboxService->list($tenantId);
            $aliases = $this->aliasService->list($tenantId);
            $forwards = $this->forwardService->list($tenantId);
            $tenantStats = $this->dashboardService->tenantOverview((int) $tenantId);
            $stats = [
                'tenants' => 1,
                'domains' => count($domains),
                'mailboxes' => count($mailboxes),
                'aliases' => count($aliases),
                'forwards' => count($forwards),
                'quota_used_mb' => (int) ($tenantStats['quota_used_mb'] ?? 0),
            ];
        } else {
            $stats = $this->dashboardService->systemOverview();
        }

        return Response::html($this->renderPage('admin/pages/dashboard.php', [
            'identity' => $identity,
            'stats' => $stats,
            'configVersions' => array_slice($configVersions, 0, 8),
        ], 'dashboard', 'MailPanel Admin', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('dashboard'),
        ]));
    }

}
