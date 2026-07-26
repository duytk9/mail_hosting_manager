<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers;

use MailPanel\Core\Request;
use MailPanel\Contracts\MailboxPasswordManager;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\RequestActorResolver;
use MailPanel\Services\ForwardService;
use MailPanel\Services\QuotaService;
use MailPanel\Support\ApiResponse;
use Throwable;

final class UserController
{
    public function __construct(
        private readonly RequestActorResolver $actorResolver,
        private readonly AuthorizationService $authorization,
        private readonly MailboxPasswordManager $mailboxService,
        private readonly ForwardService $forwardService,
        private readonly QuotaService $quotaService
    ) {
    }

    public function quota(Request $request)
    {
        $actor = $this->actorResolver->resolve($request);

        return ApiResponse::success($this->quotaService->mailboxQuota((int) ($actor->mailboxId ?? 0)));
    }

    public function password(Request $request)
    {
        try {
            $actor = $this->actorResolver->resolve($request);
            $newPassword = (string) ($request->body['new_password'] ?? $request->body['password'] ?? '');
            $this->mailboxService->changePasswordWithCurrent(
                (int) ($actor->mailboxId ?? 0),
                (string) ($request->body['current_password'] ?? ''),
                $newPassword
            );

            return ApiResponse::success(['changed' => true]);
        } catch (Throwable $exception) {
            return ApiResponse::exception($exception, 422, 'Unable to change password.');
        }
    }

    public function forwarding(Request $request)
    {
        try {
            $actor = $this->actorResolver->resolve($request);

            if ($request->method === 'GET') {
                return ApiResponse::success($this->forwardService->listForMailbox((int) ($actor->mailboxId ?? 0)));
            }

            return ApiResponse::success(
                $this->forwardService->createForMailboxUser((int) ($actor->mailboxId ?? 0), $request->body),
                [],
                201
            );
        } catch (Throwable $exception) {
            return ApiResponse::exception($exception, 422, 'Unable to update forwarding.');
        }
    }
}
