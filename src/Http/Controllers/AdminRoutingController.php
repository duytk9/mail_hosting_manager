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
        private readonly TenantService $tenantService,
        // Used by routing() to populate the mailbox picker and by the trait's
        // assertTenantOwnsMailbox(). It was referenced but never injected.
        private readonly MailboxService $mailboxService,
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
            $this->aliasService->list($tenantId),
            (string) ($request->query['search'] ?? ''),
            ['source_address']
        );
        $forwards = $this->filterRows(
            $this->forwardService->list($tenantId),
            (string) ($request->query['search'] ?? ''),
            ['source_address', 'destination_address']
        );
        $mailGroups = $this->filterRows(
            $this->mailGroupService->list($tenantId),
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
            'domains' => $this->domainService->list($tenantId),
            'mailboxes' => $this->mailboxService->list($tenantId),
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
                $this->sessions->flash('success', 'Đã xóa alias.');
            } elseif ($intent === 'delete_forward') {
                $forwardId = (int) ($request->body['forward_id'] ?? 0);
                $this->assertTenantOwnsForward($forwardId);
                $this->forwardService->delete($forwardId);
                $this->sessions->flash('success', 'Đã xóa forward.');
            } elseif ($intent === 'delete_group') {
                $groupId = (int) ($request->body['group_id'] ?? 0);
                $this->assertTenantOwnsMailGroup($groupId);
                $this->mailGroupService->delete($groupId);
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
            $this->sessions->flash('success', 'Cập nhật mail group thành công.');
            return Response::redirect('/admin/routing#mail-group-create');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/routing?edit_group=' . $groupId . '#mail-group-create');
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
