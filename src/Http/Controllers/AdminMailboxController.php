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
use MailPanel\Services\QuotaService;
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

final class AdminMailboxController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly MailboxService $mailboxService,
        private readonly DomainService $domainService,
        private readonly TenantService $tenantService,
        private readonly QuotaService $quotaService,
        private readonly AdminSecurityService $adminSecurityService,
        private readonly ConfigDeploymentService $configDeploymentService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view
    ) {
    }

    public function mailboxes(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/mailboxes')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('mailboxes.view')) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create');

            if ($redirect = $this->guardPermission(
                $intent === 'create' ? 'mailboxes.create' : ['mailboxes.update', 'mailboxes.delete'],
                '/admin/mailboxes',
                null,
                true
            )) {
                return $redirect;
            }

            return $intent === 'create'
                ? $this->handleCreateMailbox($request)
                : $this->handleMailboxAction($request);
        }

        $tenantId = $this->currentTenantId();
        $mailboxes = $this->scopeByTenant($this->mailboxService->list(), $tenantId);
        $this->refreshQuotaUsage($mailboxes);
        $mailboxes = $this->scopeByTenant($this->mailboxService->list(), $tenantId);
        $domains = $this->scopeByTenant($this->domainService->list(), $tenantId);
        $tenants = $this->scopeTenantRows($this->tenantService->list(), $tenantId);
        $tenantQuotaProfiles = [];

        foreach ($tenants as $tenant) {
            $tenantQuotaProfiles[(int) ($tenant['id'] ?? 0)] = $this->mailboxService->tenantQuotaProfile((int) ($tenant['id'] ?? 0), $tenant);
        }
        
        $domainIdFilter = (int) ($request->query['domain_id'] ?? 0);
        if ($domainIdFilter > 0) {
            $mailboxes = array_filter($mailboxes, fn($mb) => (int) ($mb['domain_id'] ?? 0) === $domainIdFilter);
        }

        $mailboxes = $this->filterRows(
            $mailboxes,
            (string) ($request->query['search'] ?? ''),
            ['email', 'display_name', 'local_part', 'status'],
            (string) ($request->query['status'] ?? '')
        );
        $mailboxes = array_values($mailboxes);
        $mailboxPage = $this->paginateRows($mailboxes, $request, 'mailboxes_page', 10);

        return Response::html($this->renderPage('admin/pages/mailboxes.php', [
            'mailboxes' => $mailboxes,
            'mailboxRows' => $mailboxPage['items'],
            'mailboxesPagination' => $mailboxPage['meta'],
            'domains' => $domains,
            'tenants' => $tenants,
            'tenantQuotaProfiles' => $tenantQuotaProfiles,
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
                'status' => (string) ($request->query['status'] ?? ''),
                'domain_id' => $domainIdFilter > 0 ? (string) $domainIdFilter : '',
            ],
        ], 'mailboxes', 'Mail Accounts', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('mailboxes'),
        ]));
    }

    private function handleMailboxAction(Request $request): Response
    {
        try {
            $action = (string) ($request->body['action'] ?? '');
            $mailboxId = (int) ($request->body['mailbox_id'] ?? 0);
            $this->assertTenantOwnsMailbox($mailboxId);

            if ($action === 'delete') {
                $this->mailboxService->delete($mailboxId);
                $this->applyEximRoutingConfig();
                $this->sessions->flash('success', 'Xóa mail account thành công.');
            } elseif ($action === 'password') {
                $this->assertCurrentAdminSensitiveAction($request);
                $password = (string) ($request->body['new_password'] ?? '');
                $this->mailboxService->changePassword($mailboxId, $password);
                $this->sessions->flash('success', 'Reset mật khẩu mail account thành công.');
            } elseif ($action === 'quota') {
                $quotaMb = (int) ($request->body['quota_mb'] ?? 0);
                $this->mailboxService->updateQuota($mailboxId, $quotaMb);
                $this->sessions->flash('success', 'Cập nhật quota mail account thành công.');
            } else {
                $this->mailboxService->setStatus($mailboxId, $action);
                $this->applyEximRoutingConfig();
                $this->sessions->flash('success', 'Cập nhật trạng thái mail account thành công.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/mailboxes');
    }

    private function handleCreateMailbox(Request $request): Response
    {
        try {
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);

            $this->mailboxService->create([
                'domain_id' => $domainId,
                'local_part' => trim((string) ($request->body['local_part'] ?? '')),
                'password' => (string) ($request->body['password'] ?? ''),
                'display_name' => trim((string) ($request->body['display_name'] ?? '')),
                'quota_mb' => (int) ($request->body['quota_mb'] ?? 1024),
                'force_password_change' => isset($request->body['force_password_change']) ? 1 : 0,
            ]);
            $this->applyEximRoutingConfig();
            $this->sessions->flash('success', 'Tạo mail account thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/mailboxes');
    }

    private function refreshQuotaUsage(array $mailboxes): void
    {
        try {
            $this->quotaService->refreshMailboxUsage($mailboxes);
        } catch (Throwable) {
            // Keep the mailbox page available even if the privileged quota scan is temporarily unavailable.
        }
    }

    private function applyEximRoutingConfig(): void
    {
        $drafts = $this->configDeploymentService->generateDrafts($this->currentActor()->id);

        foreach ($drafts as $draft) {
            if (($draft['service'] ?? '') === 'exim') {
                $this->configDeploymentService->applyVersion((int) $draft['id'], false);
                return;
            }
        }

        throw new \RuntimeException('Unable to generate Exim routing configuration.');
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

    private function assertTenantOwnsDomain(int $domainId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $domain = $this->domainService->find($domainId);

        if ($domain === null || (int) ($domain['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Domain nằm ngoài tenant hiện tại.');
        }
    }

    private function assertTenantOwnsMailbox(int $mailboxId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $mailbox = $this->mailboxService->find($mailboxId);

        if ($mailbox === null || (int) ($mailbox['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Mailbox nằm ngoài tenant hiện tại.');
        }
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

}
