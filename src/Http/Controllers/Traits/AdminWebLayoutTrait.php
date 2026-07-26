<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers\Traits;

use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\SessionManager;
use MailPanel\Support\View;
use MailPanel\Core\Request;
use MailPanel\Core\Response;

trait AdminWebLayoutTrait
{
    abstract protected function view(): View;
    abstract protected function sessions(): SessionManager;
    abstract protected function authorization(): AuthorizationService;

    protected function renderPage(string $template, array $data, string $active, string $title, array $page = []): string
    {
        $identity = $this->sessions()->identity() ?? [];
        $pageMeta = $this->buildPageMeta($active, $title, $page);
        
        $content = $this->view()->render($template, $data + [
            'identity' => $identity,
            'csrfToken' => $this->sessions()->csrfToken(),
            'view' => $this->view(),
            'page' => $pageMeta,
            'canWrite' => ($identity['role'] ?? null) !== 'support_readonly',
            'isSuperAdmin' => $this->isSuperAdmin(),
            'canAccess' => fn (string|array $permissions, bool $matchAny = false): bool => 
                $this->hasPermission($permissions, $matchAny),
        ]);

        return $this->view()->render('admin/layout.php', [
            'title' => $title,
            'active' => $active,
            'page' => $pageMeta,
            'identity' => $identity,
            'isImpersonating' => $this->sessions()->isImpersonating(),
            'flashSuccess' => $this->sessions()->pullFlash('success'),
            'flashError' => $this->sessions()->pullFlash('error'),
            'csrfToken' => $this->sessions()->csrfToken(),
            'canAccess' => fn (string|array $permissions, bool $matchAny = false): bool => 
                $this->hasPermission($permissions, $matchAny),
            'content' => $content,
        ]);
    }

    protected function buildPageMeta(string $active, string $title, array $overrides = []): array
    {
        return array_merge([
            'title' => $title,
            'description' => '',
            'breadcrumbs' => [
                ['label' => 'Trang chu', 'href' => '/admin/dashboard'],
                ['label' => $title, 'href' => '#'],
            ],
            'quick_actions' => [],
        ], $overrides);
    }

    protected function isSuperAdmin(): bool
    {
        $identity = $this->sessions()->identity() ?? [];
        return ($identity['role'] ?? 'guest') === 'super_admin';
    }

    protected function hasPermission(string|array $permissions, bool $matchAny = false): bool
    {
        $identity = $this->sessions()->identity() ?? [];
        if ((int) ($identity['id'] ?? 0) <= 0) {
            return false;
        }

        $actor = new Actor(
            (int) ($identity['id'] ?? 0),
            (string) ($identity['role'] ?? 'guest'),
            isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null
        );

        return is_array($permissions)
            ? ($matchAny
                ? $this->authorization()->canAny($actor, $permissions)
                : $this->authorization()->canAll($actor, $permissions))
            : $this->authorization()->can($actor, $permissions);
    }

    protected function guardPermission(
        string|array $permissions,
        string $redirect = '/admin/dashboard',
        ?string $message = null,
        bool $matchAny = false
    ): ?Response {
        if ($this->hasPermission($permissions, $matchAny)) {
            return null;
        }

        $this->sessions()->flash('error', $message ?? 'Bạn không có quyền thực hiện thao tác này.');
        return Response::redirect($redirect);
    }

    protected function guardAdminSession(string $path): ?Response
    {
        if ($this->sessions()->guard() !== 'admin' || !is_array($this->sessions()->identity())) {
            return Response::redirect('/admin/login');
        }

        $identity = $this->sessions()->identity() ?? [];
        if (!empty($identity['force_password_change']) && !in_array($path, ['/admin/security', '/admin/logout'], true)) {
            return Response::redirect('/admin/security');
        }

        return null;
    }

    // --- Extracted Helper Methods ---

    protected function isAdminAuthenticated(): bool
    {
        return $this->sessions->guard() === 'admin' && is_array($this->sessions->identity());
    }

    protected function isTenantAdmin(): bool
    {
        return ($this->sessions->identity()['role'] ?? null) === 'tenant_admin';
    }

    protected function currentActor(): Actor
    {
        $identity = $this->sessions->identity() ?? [];

        return new Actor(
            (int) ($identity['id'] ?? 0),
            (string) ($identity['role'] ?? 'guest'),
            isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null
        );
    }

    protected function currentTenantId(): ?int
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        $identity = $this->sessions->identity() ?? [];

        return isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null;
    }

    protected function mustForcePasswordChange(): bool
    {
        $identity = $this->sessions->identity() ?? [];

        return !empty($identity['force_password_change']);
    }

    protected function guardAuthenticatedPage(string $path): ?Response
    {
        if (!$this->isAdminAuthenticated()) {
            return Response::redirect('/admin/login');
        }

        if ($this->mustForcePasswordChange() && $path !== '/admin/security') {
            return Response::redirect('/admin/security');
        }

        return null;
    }


    // scopeByTenant() / scopeTenantRows() were deliberately removed.
    //
    // They fetched every row in a table and filtered the array in PHP, which meant
    // tenant isolation depended on each call site remembering to wrap its query,
    // and every listing loaded the whole table into memory. Tenant scoping now
    // happens in SQL: pass currentTenantId() into the service list() call, e.g.
    //
    //     $this->mailboxService->list($this->currentTenantId());
    //
    // currentTenantId() returns null for super_admin, meaning "no restriction".
    // Do not reintroduce array-side scoping helpers.

    /**
     * Audit context for services that write their own audit entries.
     *
     * Referenced by AdminDomainController for every ACME issue/renew call but never
     * defined after the controller split, so those code paths raised
     * "Call to undefined method" at runtime.
     *
     * @param array<string, mixed>|null $identity Defaults to the session identity.
     * @return array<string, mixed>
     */
    protected function actorContext(Request $request, ?array $identity = null): array
    {
        $identity ??= $this->sessions->identity() ?? [];

        return [
            'actor_id' => isset($identity['id']) ? (int) $identity['id'] : null,
            'actor_role' => (string) ($identity['role'] ?? 'admin'),
            'tenant_id' => isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    /**
     * Re-verify the current admin before a sensitive action (password reset, super
     * admin change, TOTP change). Requires the account password, plus the OTP when
     * 2FA is enabled.
     *
     * Requires the using controller to expose an $adminSecurityService property.
     */
    protected function assertCurrentAdminSensitiveAction(Request $request): void
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

    /**
     * Reject a mailbox id that belongs to a different tenant.
     *
     * Requires the using controller to expose a $mailboxService property.
     */
    protected function assertTenantOwnsMailbox(int $mailboxId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $mailbox = $this->mailboxService->find($mailboxId);

        if ($mailbox === null || (int) ($mailbox['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Mailbox nằm ngoài tenant hiện tại.');
        }
    }

    protected function filterRows(array $rows, string $search = '', array $fields = [], string $status = ''): array
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

    protected function paginateRows(array $rows, Request $request, string $queryKey, int $perPage = 10): array
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

    protected function normalizeFilterValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    protected function buildQuickActions(string $active): array
    {
        $actions = [
            'dashboard' => [
                ['label' => 'Tạo user level', 'href' => '/admin/tenants#tenant-create', 'variant' => 'primary', 'roles' => ['super_admin']],
                ['label' => 'Thêm domain', 'href' => '/admin/domains#domain-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
                ['label' => 'Tạo tài khoản mail', 'href' => '/admin/mailboxes#mailbox-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
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
                ['label' => 'Thêm domain', 'href' => '#domain-create', 'variant' => 'primary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
                ['label' => 'Kiểm tra DNS / TLS', 'href' => '/admin/dns-checks', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
            ],
            'mailboxes' => [
                ['label' => 'Tạo tài khoản mail', 'href' => '#mailbox-create', 'variant' => 'primary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
            ],
            'routing' => [
                ['label' => 'Tạo bí danh', 'href' => '#alias-create', 'variant' => 'primary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
                ['label' => 'Tạo chuyển tiếp', 'href' => '#forward-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
                ['label' => 'Tạo nhóm mail', 'href' => '#mail-group-create', 'variant' => 'secondary', 'roles' => ['super_admin', 'tenant_admin', 'domain_admin']],
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


    protected function sanitizeAdminIdentity(array $user): array
    {
        unset($user['password_hash'], $user['totp_secret'], $user['totp_pending_secret']);

        return $user;
    }

    protected function requireImpersonatableUser(int $userId, int $impersonatorId): array
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

        // Impersonating an account that is mid-forced-rotation would let the
        // impersonator bypass the rotation gate for that user.
        if (!empty($target['force_password_change'])) {
            throw new \InvalidArgumentException('Không thể impersonate tài khoản đang bắt buộc đổi mật khẩu.');
        }

        return $target;
    }

    protected function displayAdminLogin(array $user): string
    {
        $username = trim((string) ($user['linux_username'] ?? ''));

        if ($username !== '') {
            return $username;
        }

        return trim((string) ($user['email'] ?? ''));
    }



}
