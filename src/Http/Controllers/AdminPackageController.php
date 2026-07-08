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

final class AdminPackageController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly PackageService $packageService,
        private readonly TenantService $tenantService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view
    ) {
    }

    public function packages(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/packages')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('packages.view')) {
            return $redirect;
        }

        if (!$this->hasPermission('packages.view')) {
            $this->sessions->flash('error', 'Chỉ Admin level mới được quản lý package.');
            return Response::redirect('/admin/dashboard');
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create');
            $permission = match ($intent) {
                'update' => 'packages.update',
                'delete' => 'packages.delete',
                default => 'packages.create',
            };

            if ($redirect = $this->guardPermission($permission, '/admin/packages')) {
                return $redirect;
            }

            return match ($intent) {
                'update' => $this->handleUpdatePackage($request),
                'delete' => $this->handleDeletePackage($request),
                default => $this->handleCreatePackage($request),
            };
        }

        $packages = $this->filterRows(
            $this->packageService->list(),
            (string) ($request->query['search'] ?? ''),
            ['name', 'description']
        );
        $packageTenantCounts = [];
        foreach ($this->tenantService->list() as $tenant) {
            $packageId = (int) ($tenant['package_id'] ?? 0);
            $packageTenantCounts[$packageId] = ($packageTenantCounts[$packageId] ?? 0) + 1;
        }
        $packagePage = $this->paginateRows($packages, $request, 'packages_page', 10);

        $editingPackage = null;
        $editingPackageId = (int) ($request->query['edit_package'] ?? 0);
        if ($editingPackageId > 0) {
            $editingPackage = $this->packageService->find($editingPackageId);
        }

        return Response::html($this->renderPage('admin/pages/packages.php', [
            'packages' => $packages,
            'packageRows' => $packagePage['items'],
            'packagesPagination' => $packagePage['meta'],
            'editingPackage' => $editingPackage,
            'packageTenantCounts' => $packageTenantCounts,
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
            ],
        ], 'packages', 'Packages', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('packages'),
        ]));
    }

    private function filterRows(array $rows, string $search = '', array $fields = [], string $status = ''): array
    {
        $search = $this->normalizeFilterValue($search);
        $status = $this->normalizeFilterValue($status);

        return array_values(array_filter($rows, function (array $row) use ($search, $fields, $status): bool {
            if ($status !== '' && $this->normalizeFilterValue((string) ($row['status'] ?? '')) !== $status) {
                return false;
            }

            if ($search === '') {
                return true;
            }

            foreach ($fields as $field) {
                $value = $row[$field] ?? null;

                if (is_array($value)) {
                    $value = implode(', ', array_map(static fn (mixed $item): string => (string) $item, $value));
                }

                if (str_contains($this->normalizeFilterValue((string) $value), $search)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function paginateRows(array $rows, Request $request, string $queryKey, int $perPage = 10): array
    {
        $totalItems = count($rows);
        $perPage = max(1, $perPage);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $currentPage = max(1, (int) ($request->query[$queryKey] ?? 1));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $perPage;
        $items = array_slice($rows, $offset, $perPage);
        $params = $request->query;
        unset($params[$queryKey]);

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
                'total_items' => $totalItems,
                'from' => $totalItems === 0 ? 0 : $offset + 1,
                'to' => $totalItems === 0 ? 0 : min($totalItems, $offset + $perPage),
                'query_key' => $queryKey,
                'base_path' => $request->path,
                'params' => $params,
            ],
        ];
    }

    private function normalizeFilterValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
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

}
