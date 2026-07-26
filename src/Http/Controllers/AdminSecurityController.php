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

final class AdminSecurityController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly AdminSecurityService $adminSecurityService,
        private readonly AppSecuritySettingsService $appSecuritySettingsService,
        private readonly SuperAdminService $superAdminService,
        private readonly UserRepository $users,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view,
        private readonly AuditLogService $auditLog,
        private readonly AcmeTlsService $acmeTlsService,
        private readonly ConfigDeploymentService $configDeploymentService
    ) {
    }

    public function security(Request $request): Response
    {
        if (!$this->isAdminAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if ($redirect = $this->guardPermission(
            $request->method === 'POST' ? 'security.update' : 'security.view'
        )) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            return $this->handleSecurityAction($request);
        }

        $identity = $this->sessions->identity() ?? [];
        $securityUser = $this->users->find((int) ($identity['id'] ?? 0)) ?? [];
        $totpSetup = $this->sessions->pullFlash('totp_setup');
        unset($securityUser['password_hash'], $securityUser['totp_secret']);

        $portalConfig = $this->appSecuritySettingsService->portalDomainConfig();

        return Response::html($this->renderPage('admin/pages/security.php', [
            'securityUser' => $securityUser,
            'securityLogin' => $this->displayAdminLogin($securityUser),
            'isImpersonating' => $this->sessions->isImpersonating(),
            'impersonatorLogin' => $this->displayAdminLogin($this->sessions->impersonatorIdentity() ?? []),
            'totpSetup' => $totpSetup,
            'portalConfig' => $portalConfig,
        ], 'security', 'Bảo mật tài khoản', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('security'),
        ]));
    }

    public function superAdmins(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/super-admins')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view')) {
            return $redirect;
        }

        if (!$this->hasPermission('super_admins.view')) {
            $this->sessions->flash('error', 'Chỉ Admin level mới được quản lý tài khoản Admin level.');
            return Response::redirect('/admin/dashboard');
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create');

            if ($redirect = $this->guardPermission(
                match ($intent) {
                    'sync_access', 'disable_ssh', 'toggle_ssh', 'toggle_sudo', 'update_ip_allowlist', 'reset_password' => 'super_admins.update',
                    'delete' => 'super_admins.delete',
                    default => 'super_admins.create',
                },
                '/admin/super-admins'
            )) {
                return $redirect;
            }

            try {
                $this->assertCurrentAdminSensitiveAction($request);
            } catch (Throwable $exception) {
                $this->sessions->flash('error', UiMessage::exception($exception));
                return Response::redirect('/admin/super-admins');
            }

            return match ($intent) {
                'sync_access' => $this->handleSyncSuperAdminAccess($request),
                'disable_ssh' => $this->handleDisableSuperAdminSsh($request),
                'toggle_ssh' => $this->handleToggleSuperAdminSsh($request),
                'toggle_sudo' => $this->handleToggleSuperAdminSudo($request),
                'update_ip_allowlist' => $this->handleUpdateSuperAdminIpAllowlist($request),
                'reset_password' => $this->handleResetSuperAdminPassword($request),
                'delete' => $this->handleDeleteSuperAdmin($request),
                default => $this->handleCreateSuperAdmin($request),
            };
        }

        $superAdmins = $this->filterRows(
            $this->superAdminService->list(),
            (string) ($request->query['search'] ?? ''),
            ['name', 'email', 'linux_username']
        );
        $superAdminPage = $this->paginateRows($superAdmins, $request, 'super_admins_page', 10);

        return Response::html($this->renderPage('admin/pages/super_admins.php', [
            'superAdmins' => $superAdmins,
            'superAdminRows' => $superAdminPage['items'],
            'superAdminsPagination' => $superAdminPage['meta'],
            'superAdminIpAllowlist' => $this->appSecuritySettingsService->superAdminIpAllowlistConfig(),
            'currentClientIp' => $request->ip(),
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
            ],
        ], 'super_admins', 'Admin Level Accounts', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('super_admins'),
        ]));
    }

    private function handleSecurityAction(Request $request): Response
    {
        $identity = $this->sessions->identity() ?? [];
        $userId = (int) ($identity['id'] ?? 0);
        $intent = (string) ($request->body['_intent'] ?? '');

        try {
            if ($this->sessions->isImpersonating()) {
                throw new \InvalidArgumentException('Hãy thoát impersonation trước khi đổi mật khẩu hoặc cấu hình 2FA cho tài khoản này.');
            }

            if ($intent === 'change_password') {
                $this->adminSecurityService->changePassword(
                    $userId,
                    (string) ($request->body['current_password'] ?? ''),
                    (string) ($request->body['new_password'] ?? '')
                );

                $freshUser = $this->users->find($userId) ?? $identity;
                unset($freshUser['password_hash'], $freshUser['totp_secret'], $freshUser['totp_pending_secret']);
                $this->sessions->putIdentity($freshUser, 'admin');
                $this->sessions->flash('success', 'Đổi mật khẩu thành công.');
            } elseif ($intent === 'totp_start') {
                $this->sessions->flash('totp_setup', $this->adminSecurityService->startTotpEnrollment(
                    $userId,
                    (string) ($request->body['current_password'] ?? ''),
                    isset($request->body['otp']) ? (string) $request->body['otp'] : null
                ));
                $this->sessions->flash('success', 'Đã tạo secret TOTP mới. Hãy scan rồi xác nhận.');
            } elseif ($intent === 'totp_confirm') {
                $this->adminSecurityService->confirmTotpEnrollment(
                    $userId,
                    (string) ($request->body['otp'] ?? ''),
                    (string) ($request->body['current_password'] ?? '')
                );
                $this->sessions->flash('success', 'Đã bật 2FA TOTP.');
            } elseif ($intent === 'totp_disable') {
                $this->adminSecurityService->disableTotp(
                    $userId,
                    (string) ($request->body['otp'] ?? ''),
                    (string) ($request->body['current_password'] ?? '')
                );
                $this->sessions->flash('success', 'Đã tắt 2FA TOTP.');
            } elseif ($intent === 'update_portal_domain') {
                return $this->handleUpdatePortalDomain($request);
            } else {
                throw new \InvalidArgumentException('Unknown security action.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/security');
    }

    private function handleUpdatePortalDomain(Request $request): Response
    {
        try {
            $portalDomain = strtolower(trim((string) ($request->body['portal_domain'] ?? '')));
            if ($portalDomain === '') {
                throw new \InvalidArgumentException('Vui lòng nhập Portal Domain.');
            }
            
            $identity = $this->sessions->identity() ?? [];
            $actor = [
                'actor_id' => $identity['id'] ?? null,
                'actor_role' => $identity['role'] ?? 'super_admin',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ];

            // Update app_security.json first so Nginx generator sees it
            $this->appSecuritySettingsService->updatePortalDomain(
                $portalDomain,
                $actor['actor_id'],
                $actor['actor_role'],
                $actor['ip_address'],
                $actor['user_agent']
            );

            // Run ACME TLS
            // Read admin email from session or config
            $adminEmail = $identity['email'] ?? 'admin@' . $portalDomain;
            if (empty($adminEmail)) {
                $adminEmail = 'admin@' . $portalDomain;
            }

            try {
                $this->acmeTlsService->issuePortalDomain($portalDomain, $adminEmail, $actor);
                
                // Trigger Nginx config regeneration
                $drafts = $this->configDeploymentService->generateDrafts();
                foreach ($drafts as $draft) {
                    $this->configDeploymentService->applyVersion((int) $draft['id'], false);
                }
                
                $this->sessions->flash('success', 'Đã cấu hình Portal Domain và cấp phát chứng chỉ SSL (ACME) thành công.');
            } catch (Throwable $e) {
                // If ACME fails, we still configured the domain, but cert is missing
                // Trigger Nginx config regeneration anyway to use snakeoil fallback
                $drafts = $this->configDeploymentService->generateDrafts();
                foreach ($drafts as $draft) {
                    $this->configDeploymentService->applyVersion((int) $draft['id'], false);
                }
                
                $this->sessions->flash('warning', 'Portal Domain đã lưu, nhưng cấu hình SSL (ACME) gặp lỗi: ' . $e->getMessage() . '. Đã sử dụng chứng chỉ mặc định.');
            }
            
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/security');
    }

    private function handleCreateSuperAdmin(Request $request): Response
    {
        try {
            $user = $this->superAdminService->create([
                'name' => (string) ($request->body['name'] ?? ''),
                'email' => (string) ($request->body['email'] ?? ''),
                'password' => (string) ($request->body['password'] ?? ''),
                'linux_username' => (string) ($request->body['linux_username'] ?? ''),
                'ssh_enabled' => isset($request->body['ssh_enabled']) ? 1 : 0,
                'ssh_sudo_enabled' => isset($request->body['ssh_sudo_enabled']) ? 1 : 0,
                'ssh_public_key' => (string) ($request->body['ssh_public_key'] ?? ''),
                'force_password_change' => isset($request->body['force_password_change']) ? 1 : 0,
            ]);
            $username = $this->displayAdminLogin($user);
            $this->sessions->flash('success', 'Đã tạo tài khoản Admin level [' . $username . '].');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleDeleteSuperAdmin(Request $request): Response
    {
        try {
            $identity = $this->sessions->identity() ?? [];
            $this->superAdminService->delete(
                (int) ($request->body['user_id'] ?? 0),
                isset($identity['id']) ? (int) $identity['id'] : null,
                isset($request->body['purge_linux_account'])
            );
            $this->sessions->flash('success', 'Đã xóa tài khoản Admin level.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleResetSuperAdminPassword(Request $request): Response
    {
        try {
            $result = $this->superAdminService->resetPassword((int) ($request->body['user_id'] ?? 0));
            $generatedPassword = (string) ($result['generated_password'] ?? '');
            $username = $this->displayAdminLogin($result['user'] ?? []);
            $this->sessions->flash('success', 'Đã reset mật khẩu tài khoản Admin level [' . $username . ']. Mật khẩu mới: [' . $generatedPassword . ']');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleSyncSuperAdminAccess(Request $request): Response
    {
        try {
            $this->superAdminService->syncAccess((int) ($request->body['user_id'] ?? 0), [
                'linux_username' => (string) ($request->body['linux_username'] ?? ''),
                'ssh_enabled' => isset($request->body['ssh_enabled']) ? 1 : 0,
                'ssh_sudo_enabled' => isset($request->body['ssh_sudo_enabled']) ? 1 : 0,
                'ssh_public_key' => (string) ($request->body['ssh_public_key'] ?? ''),
                'reset_password' => isset($request->body['reset_password']) ? 1 : 0,
            ]);
            $this->sessions->flash('success', 'Đã đồng bộ quyền truy cập.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleDisableSuperAdminSsh(Request $request): Response
    {
        try {
            $this->superAdminService->disableSsh((int) ($request->body['user_id'] ?? 0));
            $this->sessions->flash('success', 'Đã tắt quyền SSH.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleToggleSuperAdminSsh(Request $request): Response
    {
        try {
            $this->superAdminService->toggleSsh((int) ($request->body['user_id'] ?? 0));
            $this->sessions->flash('success', 'Đã cập nhật quyền SSH.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleToggleSuperAdminSudo(Request $request): Response
    {
        try {
            $this->superAdminService->toggleSudo((int) ($request->body['user_id'] ?? 0));
            $this->sessions->flash('success', 'Đã cập nhật quyền Sudo.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins');
    }

    private function handleUpdateSuperAdminIpAllowlist(Request $request): Response
    {
        try {
            $identity = $this->sessions->identity() ?? [];
            $updated = $this->appSecuritySettingsService->updateSuperAdminIpAllowlist(
                isset($request->body['super_admin_ip_allowlist_enabled']),
                (string) ($request->body['super_admin_ip_allowlist'] ?? ''),
                isset($identity['id']) ? (int) $identity['id'] : null,
                (string) ($identity['role'] ?? 'super_admin'),
                $request->ip(),
                $request->userAgent()
            );
            $this->sessions->flash(
                'success',
                $updated['enabled']
                    ? sprintf('Đã cập nhật IP allowlist cho Admin level (%d mục).', count($updated['entries']))
                    : 'Đã tắt IP allowlist cho Admin level.'
            );
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/super-admins#super-admin-ip-allowlist');
    }

}
