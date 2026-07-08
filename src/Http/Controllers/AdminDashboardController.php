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
            $domains = $this->scopeByTenant($this->domainService->list(), $tenantId);
            $mailboxes = $this->scopeByTenant($this->mailboxService->list(), $tenantId);
            $aliases = $this->scopeByTenant($this->aliasService->list(), $tenantId);
            $forwards = $this->scopeByTenant($this->forwardService->list(), $tenantId);
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

    private function currentTenantId(): ?int
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        $identity = $this->sessions->identity() ?? [];

        return isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null;
    }

    private function isTenantAdmin(): bool
    {
        return ($this->sessions->identity()['role'] ?? null) === 'tenant_admin';
    }

    private function scopeByTenant(array $rows, ?int $tenantId): array
    {
        if ($tenantId === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => (int) ($row['tenant_id'] ?? 0) === $tenantId));
    }

    private function currentActor(): Actor
    {
        $identity = $this->sessions->identity() ?? [];

        return new Actor(
            (int) ($identity['id'] ?? 0),
            (string) ($identity['role'] ?? 'guest'),
            isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null
        );
    }

    private function buildQuickActions(string $active): array
    {
        $actions = [
            'dashboard' => [
                ['label' => 'Tạo user level', 'href' => '/admin/tenants#tenant-create', 'variant' => 'primary', 'roles' => ['super_admin']],
                ['label' => 'Thêm domain', 'href' => '/admin/domains#domain-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin']],
                ['label' => 'Tạo tài khoản mail', 'href' => '/admin/mailboxes#mailbox-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin']],
            ],
            'security' => [
                ['label' => 'Đổi mật khẩu', 'href' => '#password-policy', 'variant' => 'primary'],
                ['label' => 'Thiết lập TOTP', 'href' => '#totp-setup', 'variant' => 'secondary'],
            ],
            'packages' => [
                ['label' => 'Tạo gói dịch vụ', 'href' => '#package-create', 'variant' => 'primary', 'roles' => ['super_admin']],
            ],
            'tenants' => [
                ['label' => 'Tạo user level', 'href' => '#tenant-create', 'variant' => 'primary', 'roles' => ['super_admin']],
            ],
            'domains' => [
                ['label' => 'Thêm domain', 'href' => '#domain-create', 'variant' => 'primary', 'roles' => ['super_admin', 'tenant_admin']],
                ['label' => 'Kiểm tra DNS / TLS', 'href' => '/admin/dns-checks', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin']],
            ],
            'mailboxes' => [
                ['label' => 'Tạo tài khoản mail', 'href' => '#mailbox-create', 'variant' => 'primary', 'roles' => ['super_admin', 'tenant_admin']],
            ],
            'routing' => [
                ['label' => 'Tạo bí danh', 'href' => '#alias-create', 'variant' => 'primary', 'roles' => ['super_admin', 'tenant_admin']],
                ['label' => 'Tạo chuyển tiếp', 'href' => '#forward-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin']],
                ['label' => 'Tạo nhóm mail', 'href' => '#mail-group-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin']],
            ],
            'super_admins' => [
                ['label' => 'Tạo quản trị viên', 'href' => '#super-admin-create', 'variant' => 'primary', 'roles' => ['super_admin']],
            ],
            'config_versions' => [
                ['label' => 'Build Cấu hình', 'href' => '#config-build', 'variant' => 'primary', 'roles' => ['super_admin']],
            ],
        ];

        $matched = $actions[$active] ?? [];
        return array_filter($matched, function ($action) {
            if (empty($action['roles'])) return true;
            $identity = $this->sessions->identity() ?? [];
            $role = $identity['role'] ?? 'guest';
            return in_array($role, $action['roles'], true);
        });
    }

    private function mustForcePasswordChange(): bool
    {
        $identity = $this->sessions->identity() ?? [];

        return !empty($identity['force_password_change']);
    }

}
