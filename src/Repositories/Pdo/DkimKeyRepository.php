<?php

declare(strict_types=1);

namespace MailPanel\Repositories\Pdo;

use MailPanel\Services\TenantLifecyclePolicy;

class DkimKeyRepository extends AbstractPdoRepository
{
    public function activeSigningKeys(): array
    {
        return $this->fetchAll(
            sprintf(
                "SELECT dk.*, d.domain, d.dkim_enabled AS domain_dkim_enabled, d.outbound_enabled, t.status AS tenant_status, p.dkim_enabled AS package_dkim_enabled
                 FROM dkim_keys dk
                 INNER JOIN domains d ON d.id = dk.domain_id AND d.deleted_at IS NULL
                 INNER JOIN tenants t ON t.id = dk.tenant_id AND t.deleted_at IS NULL
                 INNER JOIN packages p ON p.id = t.package_id AND p.deleted_at IS NULL
                 WHERE d.status = 'active'
                   AND d.outbound_enabled = 1
                   AND d.dkim_enabled = 1
                   AND p.dkim_enabled = 1
                   AND %s
                 ORDER BY d.domain ASC, dk.id DESC",
                TenantLifecyclePolicy::sqlMailAccessCondition('t')
            )
        );
    }
}
