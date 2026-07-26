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
        // handleCreateTenant() provisions the primary domain and best-effort TLS.
        // Both were referenced but never injected, so tenant creation with a
        // primary domain raised "Call to undefined method".
        private readonly DomainService $domainService,
        private readonly AcmeTlsService $acmeTlsService,
        // Required by the trait's assertCurrentAdminSensitiveAction(), used when
        // resetting an owner account password.
        private readonly AdminSecurityService $adminSecurityService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view,
        private readonly AuditLogService $auditLog
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

        // currentTenantId() returns null for super_admin, which means "no restriction".
        // The scope is applied in SQL by TenantRepository::all().
        $tenants = $this->tenantService->list($this->currentTenantId());
        $tenants = $this->filterRows(
            $tenants,
            (string) ($request->query['search'] ?? ''),
            ['name', 'slug', 'status'],
            (string) ($request->query['status'] ?? '')
        );

        $tenantAdminLookup = $this->users->allTenantAdmins($this->currentTenantId());
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
            $editingTenant = $this->tenantService->find($editingTenantId);
        }

        $editingTenantAdmin = null;
        $editingTenantAdminId = (int) ($request->query['edit_tenant_admin'] ?? 0);
        if ($editingTenantAdminId > 0) {
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

    /**
     * Read the requested Linux username from the owner-account form.
     *
     * The form posts `username` when editing an existing owner and `admin_username`
     * when creating one alongside a new tenant. Referenced by handleEditTenantAdmin()
     * but never defined after the controller split.
     *
     * Returns '' when the field is absent, which TenantAdminService treats as
     * "leave the username unchanged".
     */
    private function resolveRequestedUsername(Request $request): string
    {
        foreach (['username', 'linux_username', 'admin_username'] as $field) {
            if (!array_key_exists($field, $request->body)) {
                continue;
            }

            $value = strtolower(trim((string) $request->body[$field]));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Best-effort TLS issuance for a newly created tenant's primary domain.
     *
     * Mirrors AdminDomainController::autoIssueCertificateAfterCreate(). Certificate
     * issuance must never fail tenant creation, so every error is swallowed into a
     * flash message; the tenant and its domain already exist at this point.
     */
    private function autoIssueCertificateAfterCreate(array $domain, Request $request): void
    {
        $email = trim((string) ($request->body['acme_email'] ?? ''));

        if ($email === '') {
            return;
        }

        try {
            $this->acmeTlsService->issue(
                $domain,
                ['email' => $email, 'profile' => 'mail_only'],
                $this->actorContext($request)
            );
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception, 'SSL SNI chưa cấp tự động.'));
        }
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
            ]);

            $generatedPassword = (string) ($result['generated_password'] ?? '');
            if ($generatedPassword !== '') {
                $this->sessions->flash('success', 'Cập nhật owner account thành công. Mật khẩu mới là: [' . $generatedPassword . ']');
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
