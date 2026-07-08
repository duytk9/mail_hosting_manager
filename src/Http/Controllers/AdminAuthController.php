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
use MailPanel\Services\PasswordResetService;
use MailPanel\Services\SuperAdminService;
use MailPanel\Services\TenantAdminService;
use MailPanel\Services\TenantLifecyclePolicy;
use MailPanel\Services\TenantService;
use MailPanel\Core\Database;
use MailPanel\Support\View;
use MailPanel\Support\UiMessage;
use MailPanel\Http\Controllers\Traits\AdminWebLayoutTrait;
use Throwable;

final class AdminAuthController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly AuthService $authService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view,
        private readonly UserRepository $users,
        private readonly AuditLogService $auditLog,
        private readonly PasswordResetService $passwordResetService,
        private readonly array $appConfig = []
    ) {
    }

    public function login(Request $request): Response
    {
        if ($this->isAdminAuthenticated()) {
            return Response::redirect($this->adminLandingPath());
        }

        $error = null;
        $oldLogin = '';

        if ($request->method === 'POST') {
            $oldLogin = trim((string) ($request->body['login'] ?? ''));

            try {
                $this->authService->loginAdmin(
                    $oldLogin,
                    (string) ($request->body['password'] ?? ''),
                    isset($request->body['otp']) ? (string) $request->body['otp'] : null,
                    $request->ip(),
                    $request->userAgent()
                );

                return Response::redirect($this->adminLandingPath());
            } catch (Throwable $exception) {
                $error = UiMessage::exception($exception, 'Đăng nhập thất bại.');
            }
        }

        return Response::html($this->view->render('admin/login.php', [
            'title' => 'Đăng nhập quản trị',
            'error' => $error,
            'oldLogin' => $oldLogin,
            'csrfToken' => $this->sessions->csrfToken(),
        ]), $error === null ? 200 : 422);
    }

    public function logout(Request $request): Response
    {
        $this->sessions->clear();

        return Response::redirect('/admin/login');
    }

    public function forgotPassword(Request $request): Response
    {
        if ($this->isAdminAuthenticated()) {
            return Response::redirect($this->adminLandingPath());
        }

        $message = null;
        $error = null;
        $oldLogin = '';

        if ($request->method === 'POST') {
            $oldLogin = trim((string) ($request->body['login'] ?? ''));

            try {
                $message = $this->passwordResetService->requestReset(
                    $oldLogin,
                    $this->passwordResetBaseUrl($request),
                    $request->ip(),
                    $request->userAgent()
                );
                $oldLogin = '';
            } catch (Throwable $exception) {
                $error = UiMessage::exception($exception, 'Không thể xử lý yêu cầu lúc này.');
            }
        }

        return Response::html($this->view->render('admin/forgot_password.php', [
            'title' => 'Quên mật khẩu',
            'message' => $message,
            'error' => $error,
            'oldLogin' => $oldLogin,
            'csrfToken' => $this->sessions->csrfToken(),
        ]), $error === null ? 200 : 422);
    }

    public function resetPassword(Request $request): Response
    {
        if ($this->isAdminAuthenticated()) {
            $this->sessions->clear();
        }

        $token = trim((string) ($request->method === 'POST' ? ($request->body['token'] ?? '') : ($request->query['token'] ?? '')));
        $error = null;
        $message = null;
        $tokenUsable = $this->passwordResetService->validToken($token);

        if ($request->method === 'POST') {
            try {
                $this->passwordResetService->completeReset(
                    $token,
                    (string) ($request->body['new_password'] ?? ''),
                    (string) ($request->body['confirm_password'] ?? ''),
                    $request->ip(),
                    $request->userAgent()
                );
                $token = '';
                $tokenUsable = false;
                $message = 'Đã đặt lại mật khẩu thành công. Vui lòng đăng nhập bằng mật khẩu mới.';
            } catch (Throwable $exception) {
                $error = UiMessage::exception($exception, 'Không thể đặt lại mật khẩu.');
                $tokenUsable = $this->passwordResetService->validToken($token);
            }
        }

        return Response::html($this->view->render('admin/reset_password.php', [
            'title' => 'Đặt lại mật khẩu',
            'token' => $token,
            'tokenUsable' => $tokenUsable,
            'message' => $message,
            'error' => $error,
            'csrfToken' => $this->sessions->csrfToken(),
        ]), $error === null ? 200 : 422);
    }

    public function impersonate(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/tenants')) {
            return $redirect;
        }

        if (!$this->isSuperAdmin()) {
            $this->sessions->flash('error', 'Chỉ Admin level mới được impersonate user level.');

            return Response::redirect('/admin/dashboard');
        }

        $targetUserId = (int) ($request->body['user_id'] ?? 0);

        try {
            $impersonator = $this->sessions->identity() ?? [];
            $target = $this->requireImpersonatableUser($targetUserId, (int) ($impersonator['id'] ?? 0));
            $targetIdentity = $this->sanitizeAdminIdentity($target);
            $targetIdentity['impersonated_by'] = [
                'id' => $impersonator['id'] ?? null,
                'role' => $impersonator['role'] ?? 'super_admin',
                'name' => $impersonator['name'] ?? null,
                'login' => $this->displayAdminLogin($impersonator),
            ];

            $this->sessions->beginImpersonation($this->sanitizeAdminIdentity($impersonator), $targetIdentity);

            $this->auditLog->log([
                'actor_id' => $impersonator['id'] ?? null,
                'actor_role' => $impersonator['role'] ?? 'super_admin',
                'tenant_id' => $target['tenant_id'] ?? null,
                'action' => 'auth.impersonation.started',
                'target_type' => 'user',
                'target_id' => $target['id'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'new_values' => [
                    'target_role' => $target['role'] ?? null,
                    'target_tenant_id' => $target['tenant_id'] ?? null,
                    'target_login' => $this->displayAdminLogin($target),
                ],
            ]);

            $this->sessions->flash('success', 'Đã impersonate user level [' . $this->displayAdminLogin($target) . '].');

            return Response::redirect('/admin/dashboard');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));

            return Response::redirect('/admin/tenants');
        }
    }

    public function stopImpersonation(Request $request): Response
    {
        if (!$this->sessions->isImpersonating()) {
            return Response::redirect('/admin/dashboard');
        }

        $impersonated = $this->sessions->identity() ?? [];
        $impersonator = $this->sessions->impersonatorIdentity() ?? [];
        $restored = $this->sessions->stopImpersonation();

        $this->auditLog->log([
            'actor_id' => $impersonator['id'] ?? null,
            'actor_role' => $impersonator['role'] ?? 'super_admin',
            'tenant_id' => $impersonated['tenant_id'] ?? null,
            'action' => 'auth.impersonation.stopped',
            'target_type' => 'user',
            'target_id' => $impersonated['id'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'new_values' => [
                'restored_login' => is_array($restored) ? $this->displayAdminLogin($restored) : null,
            ],
        ]);

        $this->sessions->flash('success', 'Đã thoát impersonation và quay lại Admin level.');

        return Response::redirect('/admin/tenants');
    }

    private function requireImpersonatableUser(int $userId, int $impersonatorId): array
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Tài khoản impersonate không hợp lệ.');
        }

        if ($userId === $impersonatorId) {
            throw new \InvalidArgumentException('Không thể impersonate chính tài khoản hiện tại.');
        }

        $target = $this->users->find($userId);
        if ($target === null) {
            throw new \InvalidArgumentException('Không tìm thấy tài khoản cần impersonate.');
        }

        if (!in_array((string) ($target['role'] ?? ''), ['tenant_admin', 'domain_admin', 'support_readonly'], true)) {
            throw new \InvalidArgumentException('Chỉ được impersonate user level hoặc read-only ops.');
        }

        if (!empty($target['force_password_change'])) {
            throw new \InvalidArgumentException('Không thể impersonate tài khoản đang bắt buộc đổi mật khẩu.');
        }

        return $target;
    }

    private function sanitizeAdminIdentity(array $user): array
    {
        unset($user['password_hash'], $user['totp_secret'], $user['totp_pending_secret']);

        return $user;
    }

    private function displayAdminLogin(array $user): string
    {
        $username = trim((string) ($user['linux_username'] ?? ''));

        if ($username !== '') {
            return $username;
        }

        return trim((string) ($user['email'] ?? ''));
    }

    private function adminLandingPath(): string
    {
        if ($this->mustForcePasswordChange()) {
            return '/admin/security';
        }

        $identity = $this->sessions->identity();
        if (!is_array($identity)) {
            return '/admin/login';
        }

        $actor = new Actor(
            (int) ($identity['id'] ?? 0),
            (string) ($identity['role'] ?? 'guest'),
            isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null
        );

        if ($this->authorization->can($actor, 'dashboard.view')) {
            return '/admin/dashboard';
        }

        if ($this->authorization->can($actor, 'security.view')) {
            return '/admin/security';
        }

        return '/admin/logout';
    }

    private function isAdminAuthenticated(): bool
    {
        return $this->sessions->guard() === 'admin' && is_array($this->sessions->identity());
    }

    private function mustForcePasswordChange(): bool
    {
        $identity = $this->sessions->identity() ?? [];

        return !empty($identity['force_password_change']);
    }

    private function passwordResetBaseUrl(Request $request): string
    {
        $configured = $this->safeConfiguredBaseUrl((string) ($this->appConfig['base_url'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $host = $this->safeRequestHost((string) ($request->header('Host') ?? $request->server['SERVER_NAME'] ?? ''));
        $scheme = $this->requestScheme($request, $host);

        return $scheme . '://' . ($host !== '' ? $host : '127.0.0.1');
    }

    private function safeConfiguredBaseUrl(string $baseUrl): string
    {
        $baseUrl = trim($baseUrl);
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || !is_string($host) || $host === '') {
            return '';
        }

        $safeHost = $this->safeRequestHost($host);
        if ($safeHost === '') {
            return '';
        }

        $port = parse_url($baseUrl, PHP_URL_PORT);

        return $scheme . '://' . $safeHost . (is_int($port) ? ':' . $port : '');
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
        if (
            preg_match('/\A(?:localhost|[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\d{1,3}(?:\.\d{1,3}){3})\z/', $withoutPort) !== 1
        ) {
            return '';
        }

        return $host;
    }

    private function requestScheme(Request $request, string $host): string
    {
        if (!empty($request->server['HTTPS']) && $request->server['HTTPS'] !== 'off') {
            return 'https';
        }

        if ((int) ($request->server['SERVER_PORT'] ?? 0) === 443) {
            return 'https';
        }

        return $host !== '' && !str_starts_with($host, '127.') && $host !== 'localhost' ? 'https' : 'http';
    }

}
