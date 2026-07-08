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
        private readonly ConfigDeploymentService $configDeploymentService,
        private readonly array $appConfig = []
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
        unset($securityUser['password_hash'], $securityUser['totp_secret'], $securityUser['totp_pending_secret']);
        if ($securityUser !== []) {
            $this->sessions->replaceIdentity($securityUser);
        }

        return Response::html($this->renderPage('admin/pages/security.php', [
            'securityUser' => $securityUser,
            'securityLogin' => $this->displayAdminLogin($securityUser),
            'isImpersonating' => $this->sessions->isImpersonating(),
            'impersonatorLogin' => $this->displayAdminLogin($this->sessions->impersonatorIdentity() ?? []),
            'totpSetup' => $totpSetup,
            'isSuperAdmin' => $this->isSuperAdmin(),
        ], 'security', 'Bảo mật tài khoản', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('security'),
        ]));
    }

    public function portalSettings(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/portal-settings')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('system_settings.view', '/admin/dashboard')) {
            return $redirect;
        }

        if (!$this->isSuperAdmin()) {
            $this->sessions->flash('error', 'Chỉ Admin level mới được cấu hình portal.');
            return Response::redirect('/admin/dashboard');
        }

        if ($request->method === 'POST') {
            if ($redirect = $this->guardPermission('system_settings.update', '/admin/portal-settings')) {
                return $redirect;
            }

            return $this->handleUpdatePortalSettings($request);
        }

        return Response::html($this->renderPage('admin/pages/portal_settings.php', [
            'securityUser' => $this->sanitizeAdminIdentity($this->users->find((int) (($this->sessions->identity() ?? [])['id'] ?? 0)) ?? []),
            'portalSettings' => $this->appSecuritySettingsService->portalSettingsConfig(),
        ], 'portal_settings', 'Cấu hình Portal', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('portal_settings'),
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
                    'sync_access', 'disable_ssh', 'toggle_ssh', 'toggle_sudo', 'toggle_security_lock', 'update_ip_allowlist', 'reset_password' => 'super_admins.update',
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
                'toggle_security_lock' => $this->handleToggleSuperAdminSecurityLock($request),
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
            'currentAdminId' => (int) (($this->sessions->identity() ?? [])['id'] ?? 0),
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
                    isset($request->body['otp']) ? (string) $request->body['otp'] : null,
                    $this->totpPortalLabel($request, $identity)
                ));
                $this->sessions->flash('success', 'Đã tạo secret TOTP mới. Hãy scan rồi xác nhận.');
            } elseif ($intent === 'totp_confirm') {
                $this->adminSecurityService->confirmTotpEnrollment(
                    $userId,
                    (string) ($request->body['otp'] ?? ''),
                    (string) ($request->body['current_password'] ?? '')
                );
                $this->refreshAdminSessionIdentity($userId, $identity);
                $this->sessions->flash('success', 'Đã bật 2FA TOTP.');
            } elseif ($intent === 'totp_disable') {
                $this->adminSecurityService->disableTotp(
                    $userId,
                    (string) ($request->body['otp'] ?? ''),
                    (string) ($request->body['current_password'] ?? '')
                );
                $this->refreshAdminSessionIdentity($userId, $identity);
                $this->sessions->flash('success', 'Đã tắt 2FA TOTP.');
            } else {
                throw new \InvalidArgumentException('Unknown security action.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/security');
    }

    private function refreshAdminSessionIdentity(int $userId, array $fallbackIdentity): void
    {
        $freshUser = $this->users->find($userId) ?? $fallbackIdentity;
        unset($freshUser['password_hash'], $freshUser['totp_secret'], $freshUser['totp_pending_secret']);

        $this->sessions->replaceIdentity($freshUser);
    }

    private function handleUpdatePortalDomain(Request $request): Response
    {
        try {
            if (!$this->isSuperAdmin()) {
                throw new \RuntimeException('Only Admin level can update the portal domain.');
            }

            $this->assertCurrentAdminSensitiveAction($request);

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

    private function handleUpdatePortalSettings(Request $request): Response
    {
        try {
            if (!$this->isSuperAdmin()) {
                throw new \RuntimeException('Only Admin level can update portal settings.');
            }

            $this->assertCurrentAdminSensitiveAction($request);

            $previous = $this->appSecuritySettingsService->portalSettingsConfig();
            $portalDomain = strtolower(trim((string) ($request->body['portal_domain'] ?? '')));
            $identity = $this->sessions->identity() ?? [];
            $actor = [
                'actor_id' => $identity['id'] ?? null,
                'actor_role' => $identity['role'] ?? 'super_admin',
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ];

            $updated = $this->appSecuritySettingsService->updatePortalSettings(
                $portalDomain,
                (string) ($request->body['password_reset_from_email'] ?? ''),
                (string) ($request->body['password_reset_from_name'] ?? ''),
                (string) ($request->body['password_reset_subject'] ?? ''),
                (int) ($request->body['password_reset_ttl_seconds'] ?? 3600),
                (string) ($request->body['password_reset_transport'] ?? 'mail'),
                (string) ($request->body['password_reset_smtp_host'] ?? ''),
                (int) ($request->body['password_reset_smtp_port'] ?? 587),
                (string) ($request->body['password_reset_smtp_encryption'] ?? 'starttls'),
                (string) ($request->body['password_reset_smtp_username'] ?? ''),
                (string) ($request->body['password_reset_smtp_password'] ?? ''),
                isset($request->body['password_reset_smtp_clear_password']),
                (int) ($request->body['password_reset_smtp_timeout_seconds'] ?? 15),
                $actor['actor_id'],
                $actor['actor_role'],
                $actor['ip_address'],
                $actor['user_agent']
            );

            if ((string) ($previous['portal_domain'] ?? '') !== (string) $updated['portal_domain']) {
                $adminEmail = trim((string) ($identity['email'] ?? ''));
                if ($adminEmail === '') {
                    $adminEmail = 'admin@' . $updated['portal_domain'];
                }

                try {
                    $this->acmeTlsService->issuePortalDomain((string) $updated['portal_domain'], $adminEmail, $actor);
                    $drafts = $this->configDeploymentService->generateDrafts();
                    foreach ($drafts as $draft) {
                        $this->configDeploymentService->applyVersion((int) $draft['id'], false);
                    }
                    $this->sessions->flash('success', 'Đã lưu cấu hình portal, cập nhật SSL và reload cấu hình.');
                } catch (Throwable $e) {
                    $drafts = $this->configDeploymentService->generateDrafts();
                    foreach ($drafts as $draft) {
                        $this->configDeploymentService->applyVersion((int) $draft['id'], false);
                    }
                    $this->sessions->flash('error', 'Đã lưu cấu hình portal nhưng cấp SSL gặp lỗi: ' . UiMessage::exception($e));
                }
            } else {
                $this->sessions->flash('success', 'Đã lưu cấu hình portal.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/portal-settings');
    }

    private function handleCreateSuperAdmin(Request $request): Response
    {
        try {
            $user = $this->superAdminService->create([
                'name' => (string) ($request->body['name'] ?? ''),
                'email' => (string) ($request->body['email'] ?? ''),
                'password' => (string) ($request->body['password'] ?? ''),
                'linux_username' => (string) ($request->body['linux_username'] ?? ($request->body['username'] ?? '')),
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
            $result = $this->superAdminService->resetPassword(
                (int) ($request->body['user_id'] ?? 0),
                (string) ($request->body['new_password'] ?? '')
            );
            $username = $this->displayAdminLogin($result['user'] ?? []);
            $this->sessions->flash('success', 'Đã reset mật khẩu tài khoản Admin level [' . $username . '] và bắt buộc đổi mật khẩu sau đăng nhập.');
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
                'new_password' => (string) ($request->body['new_password'] ?? ''),
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

    private function handleToggleSuperAdminSecurityLock(Request $request): Response
    {
        try {
            $identity = $this->sessions->identity() ?? [];
            $locked = (string) ($request->body['locked'] ?? '1') === '1';
            $this->superAdminService->setSecurityLock(
                (int) ($request->body['user_id'] ?? 0),
                $locked,
                isset($identity['id']) ? (int) $identity['id'] : null
            );
            $this->sessions->flash('success', $locked ? 'Đã khóa đăng nhập tài khoản Admin level.' : 'Đã mở khóa đăng nhập tài khoản Admin level.');
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

    private function displayAdminLogin(array $user): string
    {
        $username = trim((string) ($user['linux_username'] ?? ''));

        if ($username !== '') {
            return $username;
        }

        return trim((string) ($user['email'] ?? ''));
    }

    private function totpPortalLabel(Request $request, array $identity): string
    {
        $host = $this->configuredPortalHost();
        if ($host === '') {
            $host = $this->safeRequestHost((string) ($request->header('Host') ?? $request->server['SERVER_NAME'] ?? ''));
        }

        $path = preg_replace('#/(security|login)$#', '', $request->path) ?: '/admin';
        $path = '/' . trim(preg_replace('/[^a-z0-9\/._-]/i', '', $path) ?? 'admin', '/');
        $username = $this->displayAdminLogin($identity);

        return trim(($host !== '' ? $host : 'portal') . $path, '/') . ' - ' . ($username !== '' ? $username : 'admin');
    }

    private function configuredPortalHost(): string
    {
        $baseUrl = trim((string) ($this->appConfig['base_url'] ?? ''));
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) ? $this->safeRequestHost($host) : '';
    }

    private function safeRequestHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }

        if (str_contains($host, ',')) {
            $host = trim(explode(',', $host)[0]);
        }

        $host = preg_replace('/[^a-z0-9.\-:\[\]]/i', '', $host) ?? '';
        if (str_starts_with($host, '[')) {
            return preg_match('/\A\[[0-9a-f:.]+\](?::[0-9]{1,5})?\z/i', $host) === 1 ? $host : '';
        }

        $withoutPort = preg_replace('/:\d{1,5}\z/', '', $host) ?? '';

        return preg_match('/\A(?:localhost|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\d{1,3}(?:\.\d{1,3}){3})\z/', $withoutPort) === 1
            ? $host
            : '';
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
            'portal_settings' => [
                ['label' => 'Lưu cấu hình', 'href' => '#portal-settings-form', 'variant' => 'primary', 'roles' => ['super_admin']],
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

}
