<?php

declare(strict_types=1);

$filters = $filters ?? [];
$domainRows = $domainRows ?? $domains;
$domainsPagination = $domainsPagination ?? [];
$domainSslSummary = $domainSslSummary ?? [];
$acmeProfiles = is_array($acmeProfiles ?? null) ? $acmeProfiles : [];
$acmeDefaultEmail = (string) ($acmeDefaultEmail ?? '');
$acmeDefaultProfile = (string) ($acmeDefaultProfile ?? 'mail_only');
$acmeAutoIssueEnabled = (bool) ($acmeAutoIssueEnabled ?? true);
$tenantIndex = [];

foreach (($tenants ?? []) as $tenant) {
    $tenantIndex[(int) ($tenant['id'] ?? 0)] = $tenant;
}
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Tên miền quản lý', 'value' => count($domains), 'hint' => 'Theo bộ lọc hiện tại']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Tên miền chính', 'value' => count(array_filter($domains, static fn (array $item): bool => !empty($item['is_primary']))), 'hint' => 'Domain chính']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'DKIM sẵn sàng', 'value' => count(array_filter($domains, static fn (array $item): bool => !empty($item['dkim_enabled']))), 'hint' => 'Đang bật ký DKIM']) ?>
</section>

<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc domain',
    'action' => '/admin/domains',
    'summary' => 'Tìm domain theo tên và trạng thái.',
    'resultCount' => count($domainRows),
    'resultLabel' => 'domain',
    'resetHref' => '/admin/domains',
    'fields' => [
        [
            'label' => 'Tìm kiếm',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'example.com',
        ],
        [
            'label' => 'Trạng thái',
            'name' => 'status',
            'type' => 'select',
            'value' => (string) ($filters['status'] ?? ''),
            'options' => [
                ['value' => '', 'label' => 'Tất cả'],
                ['value' => 'pending_dns', 'label' => 'pending_dns'],
                ['value' => 'active', 'label' => 'active'],
                ['value' => 'suspended', 'label' => 'suspended'],
                ['value' => 'rejected', 'label' => 'rejected'],
            ],
        ],
    ],
]) ?>

<?php if ($canAccess('domains.create')): ?>
    <section class="panel" id="domain-create">
            <div class="panel-header">
                <h2>Thêm tên miền cho tài khoản khách</h2>
                <p>Khởi tạo domain và cờ mail flow cơ bản.</p>
            </div>

            <form method="post" action="/admin/domains">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-grid">
                    <label class="field-span-6">Khách hàng (Tenant)
                        <select name="tenant_id" required>
                            <?php foreach (($tenants ?? []) as $tenant): ?>
                                <option value="<?= (int) $tenant['id'] ?>"><?= htmlspecialchars((string) $tenant['name'], ENT_QUOTES, 'UTF-8') ?> · #<?= (int) $tenant['id'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field-span-6">Domain<input name="domain" required placeholder="example.test"></label>
                    <label class="field-span-6">Trạng thái
                        <select name="status">
                            <option value="pending_dns">pending_dns</option>
                            <option value="active">active</option>
                            <option value="suspended">suspended</option>
                        </select>
                    </label>
                    <label class="field-span-6">Email ACME / Let's Encrypt
                        <input name="acme_email" type="email" value="<?= htmlspecialchars($acmeDefaultEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="admin@example.com">
                    </label>
                    <label class="field-span-6">Phạm vi SSL SNI
                        <select name="acme_profile">
                            <?php foreach ($acmeProfiles as $key => $profile): ?>
                                <option value="<?= htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') ?>" <?= $acmeDefaultProfile === (string) $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($profile['label'] ?? $key), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="choice-grid">
                    <label><input type="checkbox" name="is_primary"> Primary</label>
                    <label><input type="checkbox" name="inbound_enabled" checked> Inbound</label>
                    <label><input type="checkbox" name="outbound_enabled" checked> Outbound</label>
                    <label><input type="checkbox" name="dkim_enabled" checked> DKIM</label>
                    <input type="hidden" name="acme_auto_issue_present" value="1">
                    <label><input type="checkbox" name="acme_auto_issue" <?= $acmeAutoIssueEnabled ? 'checked' : '' ?>> Tự cấp SSL SNI cho Exim</label>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Tạo domain</button>
                </div>
            </form>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <h2>Danh sách domain đang quản lý</h2>
        <p>Hiển thị tenant sở hữu, trạng thái mail TLS và các cờ inbound, outbound, DKIM trên từng domain.</p>
    </div>

    <?php if ($domainRows === []): ?>
        <?= $view->render('admin/components/empty_state.php', [
            'title' => 'Chưa có domain phù hợp',
            'description' => 'Thêm domain đầu tiên hoặc nới bộ lọc hiện tại.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>ID</th><th>Tên miền</th><th>Khách hàng</th><th>Mặc định</th><th>Trạng thái</th><th>Mail TLS</th><th>Inbound</th><th>Outbound</th><th>DKIM</th><th>Thao tác</th></tr>
                </thead>
                <tbody>
                <?php foreach ($domainRows as $item): ?>
                    <?php
                    $ssl = $domainSslSummary[(int) $item['id']] ?? ['status' => 'missing', 'status_label' => 'missing', 'hostname' => 'mail.' . ($item['domain'] ?? ''), 'expires_at' => null, 'expires_in_days' => null];
                    $tenant = $tenantIndex[(int) ($item['tenant_id'] ?? 0)] ?? null;
                    ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><strong><?= htmlspecialchars((string) $item['domain'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                        <td>
                            <?php if (is_array($tenant)): ?>
                                <strong><?= htmlspecialchars((string) ($tenant['name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="table-note">#<?= (int) ($tenant['id'] ?? 0) ?></div>
                            <?php else: ?>
                                #<?= (int) ($item['tenant_id'] ?? 0) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['is_primary']) ? 'active' : 'default', 'label' => !empty($item['is_primary']) ? 'Có' : 'Không']) ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => $item['status'] ?? 'unknown']) ?></td>
                        <td>
                            <?= $view->render('admin/components/status_badge.php', ['value' => $ssl['status'] ?? 'missing', 'label' => $ssl['status_label'] ?? ($ssl['status'] ?? 'missing')]) ?>
                            <div class="table-note">
                                <code><?= htmlspecialchars((string) ($ssl['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code><br>
                                <?php if (isset($ssl['expires_at']) && is_int($ssl['expires_at'])): ?>
                                    Hết hạn: <?= htmlspecialchars(gmdate('Y-m-d H:i', (int) $ssl['expires_at']), ENT_QUOTES, 'UTF-8') ?> UTC
                                    <?php if (isset($ssl['expires_in_days']) && is_int($ssl['expires_in_days'])): ?>
                                        (<?= (int) $ssl['expires_in_days'] ?> ngày)
                                    <?php endif; ?>
                                <?php else: ?>
                                    Chưa có cert.
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['inbound_enabled']) ? 'enabled' : 'disabled', 'label' => !empty($item['inbound_enabled']) ? 'Bật' : 'Tắt']) ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['outbound_enabled']) ? 'enabled' : 'disabled', 'label' => !empty($item['outbound_enabled']) ? 'Bật' : 'Tắt']) ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['dkim_enabled']) ? 'enabled' : 'disabled', 'label' => !empty($item['dkim_enabled']) ? 'Bật' : 'Tắt']) ?></td>
                        <td>
                            <details class="action-menu">
                                <summary class="btn btn-secondary btn-sm">Thao tác</summary>
                                <div class="action-menu__content">
                                    <?php if (empty($item['is_primary']) && $canAccess('domains.update')): ?>
                                        <form method="post" action="/admin/domains">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="domain_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="set_primary">
                                        <button class="btn btn-secondary btn-sm" type="submit">Đặt làm chính</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canAccess('domains.update')): ?>
                                        <form method="post" action="/admin/domains">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="domain_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="<?= ($item['status'] ?? '') === 'active' ? 'suspended' : 'active' ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit"><?= ($item['status'] ?? '') === 'active' ? 'Tạm khóa' : 'Kích hoạt' ?></button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canAccess('domains.update')): ?>
                                        <form method="post" action="/admin/domains" data-domain-rename="1" data-current-domain="<?= htmlspecialchars((string) $item['domain'], ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="domain_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="rename_domain">
                                            <input type="hidden" name="new_domain" value="">
                                            <button class="btn btn-secondary btn-sm" type="submit">Đổi tên</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canAccess('dns_checks.view')): ?>
                                        <a class="action-link secondary btn-sm" href="/admin/dns-checks?domain_id=<?= (int) $item['id'] ?>#dns-check-ssl">DNS / TLS</a>
                                    <?php endif; ?>

                                    <?php if ($canAccess(['domains.update', 'dns_checks.update'], true) && $acmeDefaultEmail !== ''): ?>
                                        <form method="post" action="/admin/domains">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="domain_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="issue_acme_tls">
                                            <button class="btn btn-secondary btn-sm" type="submit">Cấp SSL SNI</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canAccess('domains.delete')): ?>
                                        <form method="post" action="/admin/domains" data-confirm="Xóa domain này?">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="domain_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button class="btn btn-danger btn-sm" type="submit">Xóa</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $view->render('admin/components/pagination.php', ['pagination' => $domainsPagination]) ?>
    <?php endif; ?>
</section>
