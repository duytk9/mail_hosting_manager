<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers;

use MailPanel\Core\Request;
use MailPanel\Core\Response;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Support\View;
use MailPanel\Http\Controllers\Traits\AdminWebLayoutTrait;
use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\SessionManager;
use MailPanel\Services\SpamPolicyService;
use MailPanel\Services\TenantLifecyclePolicy;
use MailPanel\Support\UiMessage;

class SpamPolicyController
{
    use AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly SessionManager $sessions,
        private readonly SpamPolicyService $spamPolicyService,
        private readonly TenantRepository $tenants,
        private readonly View $view
    ) {}

    public function manage(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/spam-policies')) {
            return $redirect;
        }

        $identity = $this->sessions->identity();
        if (!$identity) {
            return Response::redirect('/admin/login');
        }

        $actor = new Actor(
            (int) ($identity['id'] ?? 0),
            (string) ($identity['role'] ?? 'guest'),
            (int) ($identity['tenant_id'] ?? 0)
        );

        if (!$this->authorization->can($actor, 'spam_policies.view')) {
            $this->sessions->flash('error', 'Bạn không có quyền truy cập trang này.');
            return Response::redirect('/admin/dashboard');
        }

        $tenantId = $identity['tenant_id'] ?? null;
        if (!$tenantId) {
            $this->sessions->flash('error', 'Tính năng Lọc SPAM cá nhân chỉ dành cho Khách hàng (Tenant).');
            return Response::redirect('/admin/dashboard');
        }

        $tenant = $this->tenants->find((int) $tenantId);
        if ($tenant === null || !TenantLifecyclePolicy::canUseMail($tenant)) {
            $this->sessions->flash('error', 'Tài khoản user level đã hết hạn hoặc bị tạm khóa. Vui lòng gia hạn trước khi thao tác quản trị mail.');
            return Response::redirect('/admin/dashboard');
        }

        if ($request->method === 'POST') {
            $csrfToken = (string) ($request->body['_csrf'] ?? '');
            if (!$this->sessions->verifyCsrf($csrfToken)) {
                $this->sessions->flash('error', 'Lỗi bảo mật (CSRF). Vui lòng thử lại.');

                return Response::redirect('/admin/spam-policies');
            }

            if (!$this->authorization->can($actor, 'spam_policies.update')) {
                $this->sessions->flash('error', 'Bạn không có quyền chỉnh sửa Lọc SPAM cá nhân.');
                return Response::redirect('/admin/spam-policies');
            }

            $domainId = (int) ($request->body['domain_id'] ?? 0);
            $allowlist = trim((string) ($request->body['allowlist_senders'] ?? ''));
            $blocklist = trim((string) ($request->body['blocklist_senders'] ?? ''));

            try {
                $this->spamPolicyService->updatePolicy($tenantId, $domainId, $allowlist, $blocklist);
                $this->sessions->flash('success', 'Đã cập nhật Lọc SPAM cá nhân thành công.');
            } catch (\Exception $e) {
                $this->sessions->flash('error', UiMessage::exception($e));
            }

            return Response::redirect('/admin/spam-policies');
        }

        $policies = $this->spamPolicyService->getSpamPoliciesForTenant($tenantId);

        return Response::html($this->renderPage('admin/pages/spam_policies.php', [
            'policies' => $policies,
        ], 'spam_policies', 'Lọc SPAM cá nhân'));
    }
}
