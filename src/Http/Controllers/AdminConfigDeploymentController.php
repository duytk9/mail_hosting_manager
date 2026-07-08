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

final class AdminConfigDeploymentController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly ConfigDeploymentService $configDeploymentService,
        private readonly AdminSecurityService $adminSecurityService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view
    ) {
    }

    public function configVersions(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/config-versions')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('config_versions.view')) {
            return $redirect;
        }

        if (!$this->hasPermission('config_versions.view')) {
            $this->sessions->flash('error', 'Chỉ Admin level mới được quản lý version cấu hình.');
            return Response::redirect('/admin/dashboard');
        }

        if ($request->method === 'POST') {
            $action = (string) ($request->body['action'] ?? '');
            $permission = match ($action) {
                'generate' => 'config_versions.create',
                'apply' => 'config_versions.update',
                'rollback' => 'config_versions.restore',
                'clear_old' => 'config_versions.delete',
                default => 'config_versions.update',
            };

            if ($redirect = $this->guardPermission($permission, '/admin/config-versions')) {
                return $redirect;
            }

            try {
                $this->assertCurrentAdminSensitiveAction($request);
            } catch (Throwable $exception) {
                $this->sessions->flash('error', UiMessage::exception($exception));
                return Response::redirect('/admin/config-versions');
            }

            return $this->handleConfigAction($request);
        }

        $configVersions = $this->filterRows(
            $this->configDeploymentService->listVersions(),
            (string) ($request->query['search'] ?? ''),
            ['service', 'version', 'checksum'],
            (string) ($request->query['status'] ?? '')
        );
        $configVersionPage = $this->paginateRows($configVersions, $request, 'config_versions_page', 12);

        return Response::html($this->renderPage('admin/pages/config_versions.php', [
            'configVersions' => $configVersions,
            'configVersionRows' => $configVersionPage['items'],
            'configVersionsPagination' => $configVersionPage['meta'],
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
                'status' => (string) ($request->query['status'] ?? ''),
            ],
        ], 'config_versions', 'Service Config Revisions', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('config_versions'),
        ]));
    }

    private function handleConfigAction(Request $request): Response
    {
        try {
            $action = (string) ($request->body['action'] ?? '');

            if ($action === 'generate') {
                $identity = $this->sessions->identity() ?? [];
                $this->configDeploymentService->generateValidationPlan((int) ($identity['id'] ?? 0));
                $this->sessions->flash('success', 'Đã generate draft config mới.');
            } elseif ($action === 'apply') {
                $this->configDeploymentService->applyVersion((int) ($request->body['version_id'] ?? 0), false);
                $this->sessions->flash('success', 'Apply config thành công.');
            } elseif ($action === 'rollback') {
                $this->configDeploymentService->rollbackVersion((int) ($request->body['version_id'] ?? 0), false);
                $this->sessions->flash('success', 'Rollback config thành công.');
            } elseif ($action === 'clear_old') {
                $identity = $this->sessions->identity() ?? [];
                $result = $this->configDeploymentService->pruneOldVersions(
                    (int) ($request->body['keep_per_service'] ?? 10),
                    isset($request->body['delete_artifacts']),
                    (int) ($identity['id'] ?? 0)
                );
                $this->sessions->flash(
                    'success',
                    sprintf(
                        'Đã clear %d config revision cũ và %d thư mục artifact%s.',
                        (int) ($result['deleted_rows'] ?? 0),
                        (int) ($result['deleted_artifacts'] ?? 0),
                        ((int) ($result['artifact_errors'] ?? 0) > 0)
                            ? sprintf(' (%d artifact lỗi quyền/path đã được bỏ qua an toàn)', (int) ($result['artifact_errors'] ?? 0))
                            : ''
                    )
                );
                return Response::redirect('/admin/config-versions');
            } else {
                throw new \InvalidArgumentException('Unknown config action.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/config-versions');
    }

    private function assertCurrentAdminSensitiveAction(Request $request): void
    {
        $identity = $this->sessions->identity() ?? [];
        $userId = (int) ($identity['id'] ?? 0);

        if ($userId <= 0 || ($identity['role'] ?? null) !== 'super_admin') {
            throw new \RuntimeException('Only Admin level can deploy system configuration.');
        }

        $this->adminSecurityService->assertSensitiveActionAllowed(
            $userId,
            (string) ($request->body['current_password'] ?? ''),
            isset($request->body['otp']) ? (string) $request->body['otp'] : null
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
                ['label' => 'Build cấu hình', 'href' => '#config-build', 'variant' => 'primary', 'roles' => ['super_admin']],
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

}
