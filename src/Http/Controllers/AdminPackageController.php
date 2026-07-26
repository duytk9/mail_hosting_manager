<?php
declare(strict_types=1);
namespace MailPanel\Http\Controllers;
use InvalidArgumentException;
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

    // The three handlers below were referenced by packages() but never carried over
    // when the monolithic AdminWebController was split, so every POST to
    // /admin/packages raised "Call to undefined method".

    private function handleCreatePackage(Request $request): Response
    {
        try {
            $this->packageService->create($this->packagePayloadFromRequest($request));
            $this->sessions->flash('success', 'Tạo package thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/packages');
    }

    private function handleUpdatePackage(Request $request): Response
    {
        try {
            $packageId = (int) ($request->body['package_id'] ?? 0);
            if ($packageId <= 0) {
                throw new InvalidArgumentException('Package không hợp lệ.');
            }

            $this->packageService->update($packageId, $this->packagePayloadFromRequest($request));
            $this->sessions->flash('success', 'Cập nhật package thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/packages');
    }

    private function handleDeletePackage(Request $request): Response
    {
        try {
            $packageId = (int) ($request->body['package_id'] ?? 0);
            if ($packageId <= 0) {
                throw new InvalidArgumentException('Package không hợp lệ.');
            }

            // PackageService::delete() refuses to remove a package that still has
            // tenants assigned; the message is surfaced through UiMessage below.
            $this->packageService->delete($packageId);
            $this->sessions->flash('success', 'Xóa package thành công.');
        } catch (Throwable $exception) {
            $this->sessions->flash('error', UiMessage::exception($exception));
        }

        return Response::redirect('/admin/packages');
    }

    /**
     * Map the package form to the service payload.
     *
     * Checkboxes are absent from the body when unticked, so each flag is read as
     * "present means 1". Numeric fields are cast here rather than trusting the
     * request, and empty strings become 0 so a blank field is not stored as NULL.
     *
     * @return array<string, mixed>
     */
    private function packagePayloadFromRequest(Request $request): array
    {
        $body = $request->body;

        $int = static function (string $key, int $default = 0) use ($body): int {
            $value = $body[$key] ?? null;

            if ($value === null || $value === '') {
                return $default;
            }

            return (int) $value;
        };

        $flag = static fn (string $key, int $default = 0): int => array_key_exists($key, $body)
            ? (int) (bool) $body[$key]
            : $default;

        $description = trim((string) ($body['description'] ?? ''));

        return [
            'name' => trim((string) ($body['name'] ?? '')),
            'description' => $description === '' ? null : $description,
            'max_domains' => $int('max_domains'),
            'max_mailboxes' => $int('max_mailboxes'),
            'max_aliases' => $int('max_aliases'),
            'max_forwarders' => $int('max_forwarders'),
            'max_total_quota_mb' => $int('max_total_quota_mb'),
            'default_mailbox_quota_mb' => $int('default_mailbox_quota_mb'),
            'max_mailbox_quota_mb' => $int('max_mailbox_quota_mb'),
            'max_message_size_mb' => $int('max_message_size_mb'),
            'outbound_per_hour' => $int('outbound_per_hour'),
            'outbound_per_day' => $int('outbound_per_day'),
            'retention_days' => $int('retention_days'),
            // Service toggles default to enabled, matching PackageService::create().
            'enable_pop3' => $flag('enable_pop3', 1),
            'enable_imap' => $flag('enable_imap', 0),
            'enable_managesieve' => $flag('enable_managesieve', 0),
            'enable_catchall' => $flag('enable_catchall', 0),
            'enable_external_forwarding' => $flag('enable_external_forwarding', 0),
            'quarantine_enabled' => $flag('quarantine_enabled', 0),
            'antivirus_enabled' => $flag('antivirus_enabled', 0),
            'dkim_enabled' => $flag('dkim_enabled', 0),
            'custom_smtp_banner_allowed' => $flag('custom_smtp_banner_allowed', 0),
            'spam_level_default' => (string) ($body['spam_level_default'] ?? 'normal'),
        ];
    }
}
