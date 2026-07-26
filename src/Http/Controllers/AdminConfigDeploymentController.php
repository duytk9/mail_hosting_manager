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
                default => 'config_versions.update',
            };

            if ($redirect = $this->guardPermission($permission, '/admin/config-versions')) {
                return $redirect;
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
            } else {
                throw new \InvalidArgumentException('Unknown config action.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/config-versions');
    }

}
