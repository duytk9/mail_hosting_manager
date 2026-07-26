<?php

declare(strict_types=1);

$filters = $filters ?? [];
$mailboxRows = $mailboxRows ?? $mailboxes;
$mailboxesPagination = $mailboxesPagination ?? [];
$tenantQuotaProfiles = $tenantQuotaProfiles ?? [];
$domainIndex = [];
$tenantIndex = [];

foreach (($domains ?? []) as $domain) {
    $domainIndex[(int) ($domain['id'] ?? 0)] = $domain;
}

foreach (($tenants ?? []) as $tenant) {
    $tenantIndex[(int) ($tenant['id'] ?? 0)] = $tenant;
}

$defaultCreateDomainId = (int) (($domains[0]['id'] ?? 0));
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Tài khoản Mail', 'value' => count($mailboxes), 'hint' => 'Theo bộ lọc hiện tại']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Đã bật SMTP', 'value' => count(array_filter($mailboxes, static fn (array $item): bool => !empty($item['smtp_enabled']))), 'hint' => 'Cho phép gửi mail']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Dung lượng đã cấp', 'value' => array_sum(array_map(static fn (array $item): int => (int) ($item['quota_mb'] ?? 0), $mailboxes)) . ' MB', 'hint' => 'Tổng quota đã cấp']) ?>
</section>

<?php
$domainOptions = [['value' => '', 'label' => 'Tất cả domain']];
foreach (($domains ?? []) as $domain) {
    $tenant = $tenantIndex[(int) ($domain['tenant_id'] ?? 0)] ?? null;
    $tenantLabel = is_array($tenant) ? (string) ($tenant['name'] ?? ('#' . (int) ($domain['tenant_id'] ?? 0))) : ('#' . (int) ($domain['tenant_id'] ?? 0));
    $domainOptions[] = [
        'value' => (string) $domain['id'],
        'label' => sprintf('%s · %s', (string) $domain['domain'], $tenantLabel),
    ];
}
?>
<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc mail account',
    'action' => '/admin/mailboxes',
    'summary' => 'Tìm mail account theo domain, từ khóa và trạng thái.',
    'resultCount' => count($mailboxRows),
    'resultLabel' => 'mail account',
    'resetHref' => '/admin/mailboxes',
    'fields' => [
        [
            'label' => 'Domain',
            'name' => 'domain_id',
            'type' => 'select',
            'value' => (string) ($filters['domain_id'] ?? ''),
            'options' => $domainOptions,
        ],
        [
            'label' => 'Tìm kiếm',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'sales@example.com',
        ],
        [
            'label' => 'Trạng thái',
            'name' => 'status',
            'type' => 'select',
            'value' => (string) ($filters['status'] ?? ''),
            'options' => [
                ['value' => '', 'label' => 'Tất cả'],
                ['value' => 'active', 'label' => 'active'],
                ['value' => 'suspended', 'label' => 'suspended'],
                ['value' => 'disabled', 'label' => 'disabled'],
            ],
        ],
    ],
]) ?>

    <?php if ($canAccess('mailboxes.create')): ?>
        <details class="panel panel-accordion panel-spaced" id="mailbox-create">
            <summary>Tạo mail account mới</summary>
            <form method="post" action="/admin/mailboxes">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-grid">
                    <label class="field-span-6">Domain
                        <select name="domain_id" id="mailbox_domain_id" required>
                            <?php foreach (($domains ?? []) as $domain): ?>
                                <?php
                                $tenant = $tenantIndex[(int) ($domain['tenant_id'] ?? 0)] ?? null;
                                $quotaProfile = $tenantQuotaProfiles[(int) ($domain['tenant_id'] ?? 0)] ?? [];
                                $tenantName = (string) ($quotaProfile['tenant_name'] ?? ($tenant['name'] ?? ('#' . (int) ($domain['tenant_id'] ?? 0))));
                                ?>
                                <option
                                    value="<?= (int) $domain['id'] ?>"
                                    data-tenant-id="<?= (int) ($domain['tenant_id'] ?? 0) ?>"
                                    data-tenant-name="<?= htmlspecialchars($tenantName, ENT_QUOTES, 'UTF-8') ?>"
                                    data-allocated-quota-mb="<?= (int) ($quotaProfile['allocated_quota_mb'] ?? 0) ?>"
                                    data-assigned-quota-mb="<?= (int) ($quotaProfile['assigned_quota_mb'] ?? 0) ?>"
                                    data-assigned-overage-mb="<?= (int) ($quotaProfile['assigned_overage_mb'] ?? 0) ?>"
                                    data-used-quota-mb="<?= (int) ($quotaProfile['used_quota_mb'] ?? 0) ?>"
                                    data-remaining-quota-mb="<?= (int) ($quotaProfile['remaining_quota_mb'] ?? 0) ?>"
                                    data-max-mailbox-quota-mb="<?= (int) ($quotaProfile['max_single_mailbox_quota_mb'] ?? 0) ?>"
                                    data-default-quota-mb="<?= (int) ($quotaProfile['default_mailbox_quota_mb'] ?? 1024) ?>"
                                    data-recommended-quota-mb="<?= (int) ($quotaProfile['recommended_quota_mb'] ?? 1024) ?>"
                                    data-remaining-mailbox-slots="<?= (int) ($quotaProfile['remaining_mailbox_slots'] ?? 0) ?>"
                                    <?= $defaultCreateDomainId === (int) ($domain['id'] ?? 0) ? 'selected' : '' ?>
                                ><?= htmlspecialchars((string) $domain['domain'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars((string) ($tenant['name'] ?? ('#' . (int) ($domain['tenant_id'] ?? 0))), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field-span-6">Local part<input name="local_part" required placeholder="sales"></label>
                    <label class="field-span-4">Tên hiển thị<input name="display_name" required placeholder="Sales Team"></label>
                    <label class="field-span-4">Mật khẩu<input name="password" type="password" required autocomplete="new-password"></label>
                    <label class="field-span-4">Quota MB<input id="mailbox_quota_mb" name="quota_mb" type="number" value="1024" min="1" step="1"></label>
                </div>

                <div class="notice" id="mailbox_quota_notice"></div>

                <div class="form-actions">
                    <label><input type="checkbox" name="force_password_change"> Bắt đổi mật khẩu</label>
                    <button class="btn btn-primary" id="mailbox_create_submit" type="submit">Tạo tài khoản mail</button>
                </div>
            </form>
        </details>
    <?php endif; ?>

    <div class="panel panel-spaced">
        <div class="panel-header">
            <h2>Ghi chú nhanh</h2>
            <p>Kiểm tra trạng thái account, domain, quota và quyền SMTP.</p>
        </div>
        <div class="notice">Nếu không gửi được mail, rà trạng thái account, domain, quota và quyền SMTP trước.</div>
    </div>

<section class="panel">
    <div class="panel-header">
        <h2>Danh sách mail account</h2>
        <p>Theo dõi quota, trạng thái và quyền giao thức của từng mailbox.</p>
    </div>

    <?php if ($mailboxRows === []): ?>
        <?= $view->render('admin/components/empty_state.php', [
            'title' => 'Chưa có mail account phù hợp',
            'description' => 'Tạo mail account đầu tiên hoặc đổi bộ lọc hiện tại.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>ID</th><th>Địa chỉ Email</th><th>Khách hàng</th><th>Trạng thái</th><th>Dung lượng (Quota)</th><th>IMAP</th><th>POP3</th><th>SMTP</th><th>Thao tác</th></tr>
                </thead>
                <tbody>
                <?php foreach ($mailboxRows as $item): ?>
                    <?php
                    $tenant = $tenantIndex[(int) ($item['tenant_id'] ?? 0)] ?? null;
                    $domain = $domainIndex[(int) ($item['domain_id'] ?? 0)] ?? null;
                    $quotaProfile = $tenantQuotaProfiles[(int) ($item['tenant_id'] ?? 0)] ?? [];
                    $maxMailboxQuotaMb = (int) ($quotaProfile['max_single_mailbox_quota_mb'] ?? 0);
                    ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars((string) $item['email'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="table-note"><?= htmlspecialchars((string) ($item['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (is_array($domain)): ?>
                                <div class="table-note"><?= htmlspecialchars((string) ($domain['domain'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (is_array($tenant)): ?>
                                <strong><?= htmlspecialchars((string) ($tenant['name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="table-note">#<?= (int) ($tenant['id'] ?? 0) ?></div>
                            <?php else: ?>
                                #<?= (int) ($item['tenant_id'] ?? 0) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => $item['status'] ?? 'unknown']) ?></td>
                        <td>
                            <?php if ($canAccess('mailboxes.update')): ?>
                                <form method="post" action="/admin/mailboxes" class="inline-actions">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_intent" value="action">
                                    <input type="hidden" name="mailbox_id" value="<?= (int) $item['id'] ?>">
                                    <input type="hidden" name="action" value="quota">
                                    <input
                                        name="quota_mb"
                                        type="number"
                                        min="1"
                                        <?= $maxMailboxQuotaMb > 0 ? 'max="' . (int) $maxMailboxQuotaMb . '"' : '' ?>
                                        step="1"
                                        value="<?= (int) ($item['quota_mb'] ?? 0) ?>"
                                        class="quota-input"
                                    >
                                    <button class="btn btn-secondary btn-sm" type="submit">Lưu</button>
                                </form>
                                <?php if ($maxMailboxQuotaMb > 0): ?>
                                    <div class="table-note">Tối đa <?= (int) $maxMailboxQuotaMb ?> MB</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <?= (int) ($item['quota_mb'] ?? 0) ?> MB
                            <?php endif; ?>
                        </td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['imap_enabled']) ? 'enabled' : 'disabled', 'label' => !empty($item['imap_enabled']) ? 'Bật' : 'Tắt']) ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['pop3_enabled']) ? 'enabled' : 'disabled', 'label' => !empty($item['pop3_enabled']) ? 'Bật' : 'Tắt']) ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['smtp_enabled']) ? 'enabled' : 'disabled', 'label' => !empty($item['smtp_enabled']) ? 'Bật' : 'Tắt']) ?></td>
                        <td>
                            <details class="action-menu">
                                <summary class="btn btn-secondary btn-sm">Thao tác</summary>
                                <div class="action-menu__content">
                                    <?php if ($canAccess('mailboxes.update')): ?>
                                        <form method="post" action="/admin/mailboxes">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="mailbox_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="<?= ($item['status'] ?? '') === 'active' ? 'suspended' : 'active' ?>">
                                            <button class="btn btn-secondary btn-sm" type="submit"><?= ($item['status'] ?? '') === 'active' ? 'Tạm khóa' : 'Kích hoạt' ?></button>
                                        </form>

                                        <form method="post" action="/admin/mailboxes">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="mailbox_id" value="<?= (int) $item['id'] ?>">
                                            <input type="hidden" name="action" value="password">
                                            <input name="current_password" type="password" required autocomplete="current-password" placeholder="Mật khẩu admin hiện tại">
                                            <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="OTP nếu có">
                                            <button class="btn btn-secondary btn-sm" type="submit">Đặt lại mật khẩu</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($canAccess('mailboxes.delete')): ?>
                                        <form method="post" action="/admin/mailboxes" data-confirm="Xóa mail account này?">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="action">
                                            <input type="hidden" name="mailbox_id" value="<?= (int) $item['id'] ?>">
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

        <?= $view->render('admin/components/pagination.php', ['pagination' => $mailboxesPagination]) ?>
    <?php endif; ?>
</section>

