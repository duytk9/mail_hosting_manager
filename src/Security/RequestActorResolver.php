<?php

declare(strict_types=1);

namespace MailPanel\Security;

use MailPanel\Core\Request;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Repositories\Pdo\TenantRepository;
use MailPanel\Repositories\Pdo\UserRepository;
use MailPanel\Services\TenantLifecyclePolicy;

final class RequestActorResolver
{
    public function __construct(
        private readonly SessionManager $sessions,
        private readonly ?TokenGuard $tokenGuard = null,
        private readonly ?UserRepository $users = null,
        private readonly ?MailboxRepository $mailboxes = null,
        private readonly ?TenantRepository $tenants = null,
        private readonly ?DomainRepository $domains = null,
    ) {
    }

    public function resolve(Request $request): Actor
    {
        $authorization = $request->header('Authorization', '') ?? '';
        $token = $this->tokenGuard?->resolve($request);

        if ($token !== null) {
            return new Actor(
                id: (int) ($token['user_id'] ?? $token['mailbox_id'] ?? 0),
                role: (string) ($token['actor_role'] ?? ($token['user_id'] ? 'super_admin' : 'mailbox_user')),
                tenantId: isset($token['tenant_id']) ? (int) $token['tenant_id'] : null,
                mailboxId: isset($token['mailbox_id']) ? (int) $token['mailbox_id'] : null
            );
        }

        if (TokenGuard::hasBearerCredential($authorization)) {
            return new Actor(id: 0, role: 'guest');
        }

        $identity = $this->sessions->identity();

        if (is_array($identity)) {
            $identity = $this->freshSessionIdentity($identity, $this->sessions->guard());
            if ($identity === null) {
                $this->sessions->clear();

                return new Actor(id: 0, role: 'guest');
            }

            return new Actor(
                id: (int) ($identity['id'] ?? 0),
                role: (string) ($identity['role'] ?? ($this->sessions->guard() === 'mailbox' ? 'mailbox_user' : 'super_admin')),
                tenantId: isset($identity['tenant_id']) ? (int) $identity['tenant_id'] : null,
                mailboxId: $this->sessions->guard() === 'mailbox' ? (int) ($identity['id'] ?? 0) : null
            );
        }

        return new Actor(id: 0, role: 'guest');
    }

    public function sessionIdentity(): ?array
    {
        return $this->sessions->identity();
    }

    public function hasSessionIdentity(): bool
    {
        return is_array($this->sessions->identity());
    }

    private function freshSessionIdentity(array $identity, ?string $guard): ?array
    {
        $id = (int) ($identity['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        if ($guard === 'mailbox') {
            if ($this->mailboxes === null) {
                return $identity;
            }

            $fresh = $this->mailboxes->find($id);
            if ($fresh === null || (string) ($fresh['status'] ?? '') !== 'active') {
                return null;
            }

            if ($this->domains !== null) {
                $domain = $this->domains->find((int) ($fresh['domain_id'] ?? 0));
                if ($domain === null || (string) ($domain['status'] ?? '') !== 'active' || (int) ($domain['inbound_enabled'] ?? 0) !== 1) {
                    return null;
                }
            }

            if ($this->tenants !== null) {
                $tenant = $this->tenants->find((int) ($fresh['tenant_id'] ?? 0));
                if ($tenant === null || !TenantLifecyclePolicy::canUseMail($tenant)) {
                    return null;
                }
            }

            $fresh = $this->sanitizeIdentity($fresh);
            $this->sessions->replaceIdentity($fresh);

            return $fresh;
        }

        if ($this->users === null) {
            return $identity;
        }

        $fresh = $this->users->find($id);
        if ($fresh === null) {
            return null;
        }

        if (isset($identity['role']) && (string) $identity['role'] !== (string) ($fresh['role'] ?? '')) {
            return null;
        }

        if (($fresh['tenant_id'] ?? null) !== null && $this->tenants !== null) {
            $tenant = $this->tenants->find((int) $fresh['tenant_id']);
            if ($tenant === null || !TenantLifecyclePolicy::canUseMail($tenant)) {
                return null;
            }
        }

        $fresh = $this->sanitizeIdentity($fresh);
        $this->sessions->replaceIdentity($fresh);

        return $fresh;
    }

    private function sanitizeIdentity(array $identity): array
    {
        unset($identity['password_hash'], $identity['totp_secret'], $identity['totp_pending_secret']);

        return $identity;
    }
}
