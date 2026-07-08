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

final class AdminTenantController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly TenantService $tenantService,
        private readonly TenantAdminService $tenantAdminService,
        private readonly PackageService $packageService,
        private readonly UserRepository $users,
        private readonly DomainService $domainService,
        private readonly AcmeTlsService $acmeTlsService,
        private readonly AdminSecurityService $adminSecurityService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view,
        private readonly AuditLogService $auditLog,
        private readonly array $mailpanelConfig = []
    ) {
    }

    public function tenants(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/tenants')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('tenants.view')) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create');

            if (!$this->isSuperAdmin()) {
                $this->sessions->flash('error', 'Chỉ Admin level mới được tạo, sửa hoặc xóa tài khoản khách.');
                return Response::redirect('/admin/tenants');
            }

            $permission = match ($intent) {
                'update' => 'tenants.update',
                'delete' => 'tenants.delete',
                'edit_tenant_admin', 'delete_tenant_admin' => 'tenants.update',
                default => 'tenants.create',
            };

            if (!$this->hasPermission($permission)) {
                $this->sessions->flash('error', 'Chỉ Admin level mới được quản lý user level.');
                return Response::redirect('/admin/tenants');
            }

            return match ($intent) {
                'update' => $this->handleUpdateTenant($request),
                'delete' => $this->handleDeleteTenant($request),
                'edit_tenant_admin' => $this->handleEditTenantAdmin($request),
                'delete_tenant_admin' => $this->handleDeleteTenantAdmin($request),
                default => $this->handleCreateTenant($request),
            };
        }

        $tenants = $this->isTenantAdmin()
            ? array_values(array_filter(
                $this->tenantService->list(),
                fn (array $tenant): bool => (int) ($tenant['id'] ?? 0) === $this->currentTenantId()
            ))
            : $this->tenantService->list();
        $tenants = $this->filterRows(
            $tenants,
            (string) ($request->query['search'] ?? ''),
            ['name', 'slug', 'status'],
            (string) ($request->query['status'] ?? '')
        );

        $tenantAdminLookup = $this->scopeByTenant($this->users->allTenantAdmins(), $this->currentTenantId());
        $tenantAdmins = $this->filterRows(
            $tenantAdminLookup,
            (string) ($request->query['admin_search'] ?? ''),
            ['name', 'email', 'linux_username']
        );
        $tenantPage = $this->paginateRows($tenants, $request, 'tenants_page', 10);
        $tenantAdminPage = $this->paginateRows($tenantAdmins, $request, 'tenant_admins_page', 10);
        
        $tenantUsage = [];
        foreach ($tenantPage['items'] as $t) {
            $tenantUsage[$t['id']] = $this->tenantService->getUsage((int) $t['id']);
        }

        $editingTenant = null;
        $editingTenantId = (int) ($request->query['edit_tenant'] ?? 0);
        if ($editingTenantId > 0) {
            if (!$this->isSuperAdmin()) {
                $this->sessions->flash('error', 'Chỉ Admin level mới được sửa tài khoản khách.');
                return Response::redirect('/admin/tenants');
            }

            $editingTenant = $this->tenantService->find($editingTenantId);
        }

        $editingTenantAdmin = null;
        $editingTenantAdminId = (int) ($request->query['edit_tenant_admin'] ?? 0);
        if ($editingTenantAdminId > 0) {
            if (!$this->isSuperAdmin()) {
                $this->sessions->flash('error', 'Chỉ Admin level mới được sửa owner account.');
                return Response::redirect('/admin/tenants');
            }

            $editingTenantAdmin = $this->users->find($editingTenantAdminId);
        }

        return Response::html($this->renderPage('admin/pages/tenants.php', [
            'tenants' => $tenants,
            'tenantRows' => $tenantPage['items'],
            'tenantsPagination' => $tenantPage['meta'],
            'tenantUsage' => $tenantUsage,
            'editingTenant' => $editingTenant,
            'editingTenantAdmin' => $editingTenantAdmin,
            'packages' => $this->isSuperAdmin() ? $this->packageService->list() : [],
            'tenantAdmins' => $tenantAdmins,
            'tenantAdminLookup' => $tenantAdminLookup,
            'tenantAdminRows' => $tenantAdminPage['items'],
            'tenantsAdminsPagination' => $tenantAdminPage['meta'],
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
                'status' => (string) ($request->query['status'] ?? ''),
                'admin_search' => (string) ($request->query['admin_search'] ?? ''),
            ],
        ], 'tenants', 'Users / User Level', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('tenants'),
        ]));
    }

    private function handleCreateTenant(Request $request): Response
    {
        try {
            $tenant = $this->tenantService->create($this->tenantPayloadFromRequest($request));

            $primaryDomain = trim((string) ($request->body['primary_domain'] ?? ''));
            if ($primaryDomain !== '') {
                $domain = $this->domainService->create([
                    'tenant_id' => (int) ($tenant['id'] ?? 0),
                    'domain' => $primaryDomain,
                    'status' => 'active',
                    'is_primary' => 1,
                    'inbound_enabled' => 1,
                    'outbound_enabled' => 1,
                    'dkim_enabled' => 1,
                ]);

                $adminLocalPart = trim((string) ($request->body['admin_local_part'] ?? ''));
                $adminName = trim((string) ($request->body['admin_name'] ?? ''));
                $adminUsername = trim((string) ($request->body['admin_username'] ?? ''));
                $adminPassword = (string) ($request->body['admin_password'] ?? '');
                if ($adminLocalPart !== '' && $adminName !== '' && $adminUsername !== '' && $adminPassword !== '') {
                    $this->tenantAdminService->createForPrimaryDomain([
                        'tenant_id' => (int) ($tenant['id'] ?? 0),
                        'name' => $adminName,
                        'local_part' => $adminLocalPart,
                        'linux_username' => $adminUsername,
                        'password' => $adminPassword,
                        'force_password_change' => isset($request->body['admin_force_change']) ? 1 : 0,
                    ]);
                }

                $this->autoIssueCertificateAfterCreate($domain, $request);
            }

            $this->sessions->flash('success', 'Tạo user level thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/tenants');
    }

    private function handleUpdateTenant(Request $request): Response
    {
        $tenantId = (int) ($request->body['tenant_id'] ?? 0);

        try {
            $this->tenantService->update($tenantId, $this->tenantPayloadFromRequest($request));
            $this->sessions->flash('success', 'Cập nhật user level thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
            return Response::redirect('/admin/tenants?edit_tenant=' . $tenantId . '#tenant-create');
        }

        return Response::redirect('/admin/tenants');
    }

    private function handleDeleteTenant(Request $request): Response
    {
        try {
            $this->tenantService->delete((int) ($request->body['tenant_id'] ?? 0));
            $this->sessions->flash('success', 'Xóa user level thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/tenants');
    }

    private function handleEditTenantAdmin(Request $request): Response
    {
        $id = (int) ($request->body['admin_id'] ?? 0);
        $name = trim((string) ($request->body['name'] ?? ''));
        $localPart = trim((string) ($request->body['local_part'] ?? ''));
        $linuxUsername = $this->resolveRequestedUsername($request);
        $resetPassword = (int) ($request->body['reset_password'] ?? 0);

        if ($id <= 0 || $name === '' || $localPart === '') {
            $this->sessions->flash('error', 'Dữ liệu không hợp lệ.');
            return Response::redirect('/admin/tenants');
        }

        try {
            if ($resetPassword === 1) {
                $this->assertCurrentAdminSensitiveAction($request);
            }

            $result = $this->tenantAdminService->update($id, [
                'name' => $name,
                'local_part' => $localPart,
                'linux_username' => $linuxUsername,
                'reset_password' => $resetPassword === 1,
                'new_password' => (string) ($request->body['new_password'] ?? ''),
            ]);

            if (!empty($result['password_reset'])) {
                $this->sessions->flash('success', 'Cập nhật owner account và reset mật khẩu thành công.');
            } else {
                $this->sessions->flash('success', 'Cập nhật owner account thành công.');
            }
        } catch (\Throwable $e) {
            $this->sessions->flash('error', UiMessage::exception($e));
        }

        return Response::redirect('/admin/tenants');
    }

    private function handleDeleteTenantAdmin(Request $request): Response
    {
        $id = (int) ($request->body['admin_id'] ?? 0);
        if ($id <= 0) {
            $this->sessions->flash('error', 'ID không hợp lệ.');
            return Response::redirect('/admin/tenants');
        }

        try {
            $this->tenantAdminService->delete($id);
            $this->sessions->flash('success', 'Đã xóa owner account.');
        } catch (\Throwable $e) {
            $this->sessions->flash('error', UiMessage::exception($e));
        }

        return Response::redirect('/admin/tenants');
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
            $this->acmeTlsService->issue($domain, [
                'email' => $email,
                'profile' => $this->automaticAcmeProfile($domain, (string) ($request->body['acme_profile'] ?? '')),
            ], $this->actorContext($request));

            return 'SSL SNI đã tự cấp/đồng bộ.';
        } catch (Throwable $exception) {
            return UiMessage::exception($exception, 'SSL SNI chưa cấp tự động.');
        }
    }

    private function shouldAutoIssueCertificate(Request $request): bool
    {
        if (array_key_exists('acme_auto_issue_present', $request->body)) {
            return isset($request->body['acme_auto_issue']);
        }

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

    private function actorContext(Request $request): array
    {
        $identity = $this->sessions->identity() ?? [];

        return [
            'actor_id' => isset($identity['id']) ? (int) $identity['id'] : null,
            'actor_role' => (string) ($identity['role'] ?? 'admin'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    private function assertCurrentAdminSensitiveAction(Request $request): void
    {
        $identity = $this->sessions->identity() ?? [];
        $userId = (int) ($identity['id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Phiên quản trị không hợp lệ.');
        }

        $this->adminSecurityService->assertSensitiveActionAllowed(
            $userId,
            (string) ($request->body['current_password'] ?? ''),
            isset($request->body['otp']) ? (string) $request->body['otp'] : null
        );
    }

    private function tenantPayloadFromRequest(Request $request): array
    {
        $expiresAt = (string) ($request->body['expires_at'] ?? '');
        $graceUntil = (string) ($request->body['grace_until'] ?? '');
        if (trim($expiresAt) !== '' && trim($graceUntil) === '') {
            $defaultGraceDays = max(0, (int) ($this->mailpanelConfig['tenant_default_grace_days'] ?? 7));
            if ($defaultGraceDays > 0) {
                $graceUntil = (new \DateTimeImmutable($expiresAt))->modify('+' . $defaultGraceDays . ' days')->format('Y-m-d');
            }
        }

        return [
            'name' => (string) ($request->body['name'] ?? ''),
            'slug' => (string) ($request->body['slug'] ?? ''),
            'status' => (string) ($request->body['status'] ?? 'active'),
            'billing_status' => (string) ($request->body['billing_status'] ?? 'active'),
            'starts_at' => (string) ($request->body['starts_at'] ?? ''),
            'expires_at' => $expiresAt,
            'grace_until' => $graceUntil,
            'suspended_at' => (string) ($request->body['suspended_at'] ?? ''),
            'terminated_at' => (string) ($request->body['terminated_at'] ?? ''),
            'package_id' => (int) ($request->body['package_id'] ?? 0),
            'is_custom_limits' => (string) ($request->body['is_custom_limits'] ?? '0'),
            'extra_domains' => (int) ($request->body['extra_domains'] ?? 0),
            'extra_mailboxes' => (int) ($request->body['extra_mailboxes'] ?? 0),
            'extra_aliases' => (int) ($request->body['extra_aliases'] ?? 0),
            'extra_forwarders' => (int) ($request->body['extra_forwarders'] ?? 0),
            'extra_total_quota_mb' => (int) ($request->body['extra_total_quota_mb'] ?? 0),
            'max_domains' => (int) ($request->body['max_domains'] ?? 0),
            'max_mailboxes' => (int) ($request->body['max_mailboxes'] ?? 0),
            'max_aliases' => (int) ($request->body['max_aliases'] ?? 0),
            'max_forwarders' => (int) ($request->body['max_forwarders'] ?? 0),
            'max_total_quota_mb' => (int) ($request->body['max_total_quota_mb'] ?? 0),
            'note' => (string) ($request->body['note'] ?? ''),
        ];
    }

}
