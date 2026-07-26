<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers;

use MailPanel\Core\Request;
use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Services\AliasService;
use MailPanel\Services\ConfigDeploymentService;
use MailPanel\Services\DashboardService;
use MailPanel\Services\DomainService;
use MailPanel\Services\ForwardService;
use MailPanel\Services\MailboxService;
use MailPanel\Services\PackageService;
use MailPanel\Services\QuotaService;
use MailPanel\Services\TenantService;
use MailPanel\Support\ApiResponse;
use Throwable;

final class AdminController
{
    public function __construct(
        private readonly RequestActorResolver $actorResolver,
        private readonly AuthorizationService $authorization,
        private readonly DashboardService $dashboardService,
        private readonly PackageService $packageService,
        private readonly TenantService $tenantService,
        private readonly DomainService $domainService,
        private readonly MailboxService $mailboxService,
        private readonly AliasService $aliasService,
        private readonly ForwardService $forwardService,
        private readonly ConfigDeploymentService $configDeploymentService,
        private readonly QuotaService $quotaService
    ) {
    }

    public function dashboard(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        if ($actor->tenantId === null) {
            return ApiResponse::success($this->dashboardService->systemOverview());
        }

        $tenantStats = $this->dashboardService->tenantOverview($actor->tenantId);
        $scope = $this->tenantScope($actor);
        $domains = $this->domainService->list($scope);
        $mailboxes = $this->mailboxService->list($scope);
        $aliases = $this->aliasService->list($scope);
        $forwards = $this->forwardService->list($scope);

        return ApiResponse::success([
            'tenants' => 1,
            'domains' => count($domains),
            'mailboxes' => count($mailboxes),
            'aliases' => count($aliases),
            'forwards' => count($forwards),
            'quota_used_mb' => (int) ($tenantStats['quota_used_mb'] ?? 0),
            'mailboxes_with_usage' => (int) ($tenantStats['mailboxes_with_usage'] ?? 0),
        ]);
    }

    public function packages(Request $request)
    {
        if ($request->method === 'GET') {
            return ApiResponse::success($this->packageService->list());
        }

        return $this->wrap(fn () => $this->packageService->create($request->body), 201);
    }

    public function tenants(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        if ($request->method === 'GET') {
            return ApiResponse::success($this->tenantService->list($this->tenantScope($actor)));
        }

        return $this->wrap(fn () => $this->tenantService->create($request->body), 201);
    }

    public function domains(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        if ($request->method === 'GET') {
            return ApiResponse::success($this->domainService->list($this->tenantScope($actor)));
        }

        return $this->wrap(function () use ($actor, $request) {
            $this->authorization->requireTenantScope($actor, (int) ($request->body['tenant_id'] ?? 0));

            return $this->domainService->create($request->body);
        }, 201);
    }

    public function mailboxes(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        if ($request->method === 'GET') {
            return ApiResponse::success($this->mailboxService->list($this->tenantScope($actor)));
        }

        return $this->wrap(function () use ($actor, $request) {
            $targetTenantId = $this->mailboxService->resolveTenantIdForCreate($request->body);
            $this->authorization->requireTenantScope($actor, $targetTenantId);

            return $this->mailboxService->create($request->body + ['tenant_id' => $targetTenantId]);
        }, 201);
    }

    public function aliases(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        if ($request->method === 'GET') {
            return ApiResponse::success($this->aliasService->list($this->tenantScope($actor)));
        }

        return $this->wrap(function () use ($actor, $request) {
            $this->authorization->requireTenantScope($actor, (int) ($request->body['tenant_id'] ?? 0));

            return $this->aliasService->create($request->body);
        }, 201);
    }

    public function forwards(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        if ($request->method === 'GET') {
            return ApiResponse::success($this->forwardService->list($this->tenantScope($actor)));
        }

        return $this->wrap(function () use ($actor, $request) {
            $this->authorization->requireTenantScope($actor, (int) ($request->body['tenant_id'] ?? 0));

            return $this->forwardService->create($request->body);
        }, 201);
    }

    public function generateConfig(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        return $this->wrap(fn () => $this->configDeploymentService->generateValidationPlan($actor->id), 201);
    }

    public function configVersions(Request $request)
    {
        return ApiResponse::success($this->configDeploymentService->listVersions());
    }

    public function applyConfigVersion(Request $request)
    {
        return $this->wrap(
            fn () => $this->configDeploymentService->applyVersion(
                (int) ($request->body['version_id'] ?? 0),
                $this->booleanBody($request, 'simulate', true)
            ),
            200
        );
    }

    public function rollbackConfigVersion(Request $request)
    {
        return $this->wrap(
            fn () => $this->configDeploymentService->rollbackVersion(
                (int) ($request->body['version_id'] ?? 0),
                $this->booleanBody($request, 'simulate', true)
            ),
            200
        );
    }

    public function updateQuota(Request $request)
    {
        return $this->wrap(function () use ($request) {
            $actor = $this->actorResolver->resolve($request);
            $mailboxId = (int) ($request->body['mailbox_id'] ?? 0);
            $mailbox = $this->mailboxService->find($mailboxId);

            if ($mailbox === null) {
                throw new \InvalidArgumentException('Mailbox not found.');
            }

            if ($actor->role !== 'super_admin') {
                throw new \RuntimeException('Permission denied.');
            }

            return $this->quotaService->recordUsage(
                $mailboxId,
                (int) ($request->body['used_mb'] ?? 0),
                'system'
            );
        }, 200);
    }

    private function booleanBody(Request $request, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $request->body)) {
            return $default;
        }

        $value = $request->body[$key];
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }

    /**
     * Tenant id to pass into a service list() call, or null for unrestricted access.
     *
     * This replaces the previous scopeRows() helper, which fetched every row in the
     * table and filtered the array in PHP. That was both a scaling problem and a
     * correctness risk: isolation depended on every call site remembering to wrap
     * its query. Returning the scope here pushes the filter into the SQL WHERE
     * clause, so a forgotten scope is a missing argument rather than a silent leak.
     *
     * support_readonly deliberately has no tenant, so it keeps cross-tenant read
     * access; AuthorizationService::assertWritable() blocks it from mutating.
     */
    private function tenantScope(Actor $actor): ?int
    {
        if ($actor->role === 'super_admin' || $actor->role === 'support_readonly') {
            return null;
        }

        return $actor->tenantId;
    }

    private function wrap(callable $callback, int $status = 200)
    {
        try {
            return ApiResponse::success($callback(), [], $status);
        } catch (Throwable $exception) {
            return ApiResponse::exception($exception, 422, 'Admin API request failed.');
        }
    }
}
