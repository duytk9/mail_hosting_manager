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

final class AdminRoutingController
{
    use Traits\AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly AliasService $aliasService,
        private readonly ForwardService $forwardService,
        private readonly MailGroupService $mailGroupService,
        private readonly DomainService $domainService,
        private readonly MailboxService $mailboxService,
        private readonly TenantService $tenantService,
        private readonly ConfigDeploymentService $configDeploymentService,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly View $view
    ) {
    }

    public function routing(Request $request): Response
    {
        if ($redirect = $this->guardAuthenticatedPage('/admin/routing')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('routing.view')) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $intent = (string) ($request->body['_intent'] ?? 'create_group');

            $permission = match ($intent) {
                'create_alias' => 'aliases.create',
                'create_forward' => 'forwards.create',
                'update_group' => 'mail_groups.update',
                'delete_alias' => 'aliases.delete',
                'delete_forward' => 'forwards.delete',
                'delete_group' => 'mail_groups.delete',
                default => 'mail_groups.create',
            };

            if ($redirect = $this->guardPermission($permission, '/admin/routing')) {
                return $redirect;
            }

            return match ($intent) {
                'create_alias' => $this->handleCreateAlias($request),
                'create_forward' => $this->handleCreateForward($request),
                'update_group' => $this->handleUpdateMailGroup($request),
                'delete_alias', 'delete_forward', 'delete_group' => $this->handleRoutingAction($request),
                default => $this->handleCreateMailGroup($request),
            };
        }

        $tenantId = $this->currentTenantId();
        $editingMailGroup = null;
        $editingGroupId = (int) ($request->query['edit_group'] ?? 0);

        if ($editingGroupId > 0) {
            try {
                $this->assertTenantOwnsMailGroup($editingGroupId);
                $editingMailGroup = $this->mailGroupService->find($editingGroupId);

                if ($editingMailGroup === null) {
                    throw new \InvalidArgumentException('Mail group not found.');
                }
            } catch (Throwable $exception) {
                $this->sessions->flash('error', UiMessage::exception($exception));
                return Response::redirect('/admin/routing');
            }
        }

        $aliases = $this->filterRows(
            $this->scopeByTenant($this->aliasService->list(), $tenantId),
            (string) ($request->query['search'] ?? ''),
            ['source_address']
        );
        $forwards = $this->filterRows(
            $this->scopeByTenant($this->forwardService->list(), $tenantId),
            (string) ($request->query['search'] ?? ''),
            ['source_address', 'destination_address']
        );
        $mailGroups = $this->filterRows(
            $this->scopeByTenant($this->mailGroupService->list(), $tenantId),
            (string) ($request->query['search'] ?? ''),
            ['email', 'display_name', 'status']
        );
        $aliasPage = $this->paginateRows($aliases, $request, 'aliases_page', 8);
        $forwardPage = $this->paginateRows($forwards, $request, 'forwards_page', 8);
        $mailGroupPage = $this->paginateRows($mailGroups, $request, 'mail_groups_page', 8);

        return Response::html($this->renderPage('admin/pages/routing.php', [
            'aliases' => $aliases,
            'aliasRows' => $aliasPage['items'],
            'aliasesPagination' => $aliasPage['meta'],
            'forwards' => $forwards,
            'forwardRows' => $forwardPage['items'],
            'forwardsPagination' => $forwardPage['meta'],
            'mailGroups' => $mailGroups,
            'mailGroupRows' => $mailGroupPage['items'],
            'mailGroupsPagination' => $mailGroupPage['meta'],
            'editingMailGroup' => $editingMailGroup,
            'domains' => $this->scopeByTenant($this->domainService->list(), $tenantId),
            'mailboxes' => $this->scopeByTenant($this->mailboxService->list(), $tenantId),
            'filters' => [
                'search' => (string) ($request->query['search'] ?? ''),
            ],
        ], 'routing', 'Mail Routing', [
            'description' => '',
            'quick_actions' => $this->buildQuickActions('routing'),
        ]));
    }

    private function handleRoutingAction(Request $request): Response
    {
        try {
            $intent = (string) ($request->body['_intent'] ?? '');

            if ($intent === 'delete_alias') {
                $aliasId = (int) ($request->body['alias_id'] ?? 0);
                $this->assertTenantOwnsAlias($aliasId);
                $this->aliasService->delete($aliasId);
                $this->applyEximRoutingConfig();
                $this->sessions->flash('success', 'Đã xóa alias.');
            } elseif ($intent === 'delete_forward') {
                $forwardId = (int) ($request->body['forward_id'] ?? 0);
                $this->assertTenantOwnsForward($forwardId);
                $this->forwardService->delete($forwardId);
                $this->applyEximRoutingConfig();
                $this->sessions->flash('success', 'Đã xóa forward.');
            } elseif ($intent === 'delete_group') {
                $groupId = (int) ($request->body['group_id'] ?? 0);
                $this->assertTenantOwnsMailGroup($groupId);
                $this->mailGroupService->delete($groupId);
                $this->applyEximRoutingConfig();
                $this->sessions->flash('success', 'Đã xóa mail group.');
            } else {
                throw new \InvalidArgumentException('Unknown routing action.');
            }
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/routing');
    }

    private function handleCreateAlias(Request $request): Response
    {
        try {
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $destinationMailboxId = (int) ($request->body['destination_mailbox_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);
            $this->assertTenantOwnsMailbox($destinationMailboxId);

            $domain = $this->domainService->find($domainId);
            if ($domain === null) {
                throw new \InvalidArgumentException('Domain not found.');
            }

            $localPart = strtolower(trim((string) ($request->body['local_part'] ?? '')));
            $this->aliasService->create([
                'tenant_id' => (int) ($domain['tenant_id'] ?? 0),
                'domain_id' => $domainId,
                'source_address' => sprintf('%s@%s', $localPart, (string) $domain['domain']),
                'destination_mailbox_id' => $destinationMailboxId,
                'keep_copy' => isset($request->body['keep_copy']) ? 1 : 0,
            ]);
            $this->applyEximRoutingConfig();
            $this->sessions->flash('success', 'Tạo alias thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/routing');
    }

    private function handleCreateForward(Request $request): Response
    {
        try {
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);

            $domain = $this->domainService->find($domainId);
            if ($domain === null) {
                throw new \InvalidArgumentException('Domain not found.');
            }

            $localPart = strtolower(trim((string) ($request->body['local_part'] ?? '')));
            $this->forwardService->create([
                'tenant_id' => (int) ($domain['tenant_id'] ?? 0),
                'domain_id' => $domainId,
                'source_address' => sprintf('%s@%s', $localPart, (string) $domain['domain']),
                'destination_address' => trim((string) ($request->body['destination_address'] ?? '')),
                'keep_copy' => isset($request->body['keep_copy']) ? 1 : 0,
            ]);
            $this->applyEximRoutingConfig();
            $this->sessions->flash('success', 'Tạo forward thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/routing');
    }

    private function handleCreateMailGroup(Request $request): Response
    {
        try {
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);

            $this->mailGroupService->create([
                'domain_id' => $domainId,
                'local_part' => trim((string) ($request->body['local_part'] ?? '')),
                'display_name' => trim((string) ($request->body['display_name'] ?? '')),
                'members' => (string) ($request->body['members'] ?? ''),
            ]);
            $this->applyEximRoutingConfig();
            $this->sessions->flash('success', 'Tạo mail group thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/routing');
    }

    private function handleUpdateMailGroup(Request $request): Response
    {
        $groupId = (int) ($request->body['group_id'] ?? 0);

        try {
            $this->assertTenantOwnsMailGroup($groupId);
            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $this->assertTenantOwnsDomain($domainId);

            $this->mailGroupService->update($groupId, [
                'domain_id' => $domainId,
                'local_part' => trim((string) ($request->body['local_part'] ?? '')),
                'display_name' => trim((string) ($request->body['display_name'] ?? '')),
                'members' => (string) ($request->body['members'] ?? ''),
            ]);
            $this->applyEximRoutingConfig();
            $this->sessions->flash('success', 'Cập nhật mail group thành công.');
            return Response::redirect('/admin/routing#mail-group-create');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/routing?edit_group=' . $groupId . '#mail-group-create');
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

    private function assertTenantOwnsAlias(int $aliasId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $alias = $this->aliasService->find($aliasId);

        if ($alias === null || (int) ($alias['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Alias nằm ngoài tenant hiện tại.');
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

    private function assertTenantOwnsForward(int $forwardId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $forward = $this->forwardService->find($forwardId);

        if ($forward === null || (int) ($forward['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Forward nằm ngoài tenant hiện tại.');
        }
    }

    private function assertTenantOwnsMailGroup(int $groupId): void
    {
        if ($this->isSuperAdmin()) {
            return;
        }

        $group = $this->mailGroupService->find($groupId);

        if ($group === null || (int) ($group['tenant_id'] ?? 0) !== $this->currentTenantId()) {
            throw new \InvalidArgumentException('Mail group nằm ngoài tenant hiện tại.');
        }
    }

}
