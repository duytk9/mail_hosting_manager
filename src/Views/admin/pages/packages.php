<?php

declare(strict_types=1);

$filters = $filters ?? [];
$packageRows = $packageRows ?? $packages;
$packagesPagination = $packagesPagination ?? [];
$editingPackage = $editingPackage ?? null;
$packageTenantCounts = $packageTenantCounts ?? [];
$totalDomains = array_sum(array_map(static fn (array $item): int => (int) ($item['max_domains'] ?? 0), $packages));
$totalMailAccounts = array_sum(array_map(static fn (array $item): int => (int) ($item['max_mailboxes'] ?? 0), $packages));
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Gói dịch vụ', 'value' => count($packages), 'hint' => 'Đang hiển thị']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Số tên miền tối đa', 'value' => $totalDomains, 'hint' => 'Quota toàn catalog']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Số hộp thư tối đa', 'value' => $totalMailAccounts, 'hint' => 'Quota toàn catalog']) ?>
</section>

<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc package',
    'action' => '/admin/packages',
    'summary' => 'Tìm package theo tên hoặc mô tả.',
    'resultCount' => count($packageRows),
    'resultLabel' => 'package',
    'resetHref' => '/admin/packages',
    'fields' => [
        [
            'label' => 'Tìm kiếm',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'Tên hoặc mô tả package',
        ],
    ],
]) ?>

    <?php if ($canAccess('packages.create') || ($editingPackage && $canAccess('packages.update'))): ?>
        <div class="panel panel-spaced" id="package-create">
            <div class="panel-header">
                <h2><?= $editingPackage ? 'Cập nhật package' : 'Tạo package' ?></h2>
                <p>Thiết lập quota và tính năng mail cơ bản.</p>
            </div>

            <form method="post" action="/admin/packages" class="tab-container">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($editingPackage): ?>
                    <input type="hidden" name="_intent" value="update">
                    <input type="hidden" name="package_id" value="<?= (int) $editingPackage['id'] ?>">
                <?php endif; ?>

                <div class="tab-nav">
                    <button type="button" class="tab-btn is-active">Giới hạn Quota</button>
                    <button type="button" class="tab-btn">Tính năng Giao thức</button>
                </div>

                <div class="tab-pane is-active">
                    <div class="form-grid">
                        <label class="field-span-6">Tên
                            <input name="name" required value="<?= htmlspecialchars((string) ($editingPackage['name'] ?? 'Starter Plus'), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="field-span-6">Mô tả
                            <input name="description" value="<?= htmlspecialchars((string) ($editingPackage['description'] ?? 'Package tạo từ admin portal'), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="field-span-4">Tên miền tối đa<input name="max_domains" type="number" value="<?= (int) ($editingPackage['max_domains'] ?? 5) ?>" min="1"></label>
                        <label class="field-span-4">Hộp thư tối đa<input name="max_mailboxes" type="number" value="<?= (int) ($editingPackage['max_mailboxes'] ?? 50) ?>" min="1"></label>
                        <label class="field-span-4">Tổng dung lượng MB<input name="max_total_quota_mb" type="number" value="<?= (int) ($editingPackage['max_total_quota_mb'] ?? 20480) ?>" min="1"></label>
                        <label class="field-span-4">Bí danh tối đa<input name="max_aliases" type="number" value="<?= (int) ($editingPackage['max_aliases'] ?? 50) ?>" min="0"></label>
                        <label class="field-span-4">Chuyển tiếp tối đa<input name="max_forwarders" type="number" value="<?= (int) ($editingPackage['max_forwarders'] ?? 20) ?>" min="0"></label>
                        <label class="field-span-4">Message size MB<input name="max_message_size_mb" type="number" value="<?= (int) ($editingPackage['max_message_size_mb'] ?? 25) ?>" min="1"></label>
                        <label class="field-span-4">Gửi / giờ<input name="outbound_per_hour" type="number" value="<?= (int) ($editingPackage['outbound_per_hour'] ?? 500) ?>" min="1"></label>
                        <label class="field-span-4">Gửi / ngày<input name="outbound_per_day" type="number" value="<?= (int) ($editingPackage['outbound_per_day'] ?? 5000) ?>" min="1"></label>
                        <label class="field-span-4">Lưu thư (ngày)<input name="retention_days" type="number" value="<?= (int) ($editingPackage['retention_days'] ?? 30) ?>" min="1"></label>
                        <label class="field-span-6">Dung lượng mặc định MB<input name="default_mailbox_quota_mb" type="number" value="<?= (int) ($editingPackage['default_mailbox_quota_mb'] ?? 1024) ?>" min="1"></label>
                        <label class="field-span-6">Dung lượng tối đa/hộp thư MB<input name="max_mailbox_quota_mb" type="number" value="<?= (int) ($editingPackage['max_mailbox_quota_mb'] ?? 4096) ?>" min="1"></label>
                    </div>
                </div>

                <div class="tab-pane">
                    <div class="choice-grid">
                        <label><input type="checkbox" name="enable_imap" <?= ($editingPackage['enable_imap'] ?? 1) ? 'checked' : '' ?>> IMAP</label>
                        <label><input type="checkbox" name="enable_pop3" <?= ($editingPackage['enable_pop3'] ?? 1) ? 'checked' : '' ?>> POP3</label>
                        <label><input type="checkbox" name="enable_managesieve" <?= ($editingPackage['enable_managesieve'] ?? 1) ? 'checked' : '' ?>> Sieve</label>
                        <label><input type="checkbox" name="enable_catchall" <?= ($editingPackage['enable_catchall'] ?? 0) ? 'checked' : '' ?>> Catch-all</label>
                        <label><input type="checkbox" name="enable_external_forwarding" <?= ($editingPackage['enable_external_forwarding'] ?? 0) ? 'checked' : '' ?>> Forward ngoài</label>
                        <label><input type="checkbox" name="quarantine_enabled" <?= ($editingPackage['quarantine_enabled'] ?? 1) ? 'checked' : '' ?>> Quarantine</label>
                        <label><input type="checkbox" name="antivirus_enabled" <?= ($editingPackage['antivirus_enabled'] ?? 1) ? 'checked' : '' ?>> Antivirus</label>
                        <label><input type="checkbox" name="dkim_enabled" <?= ($editingPackage['dkim_enabled'] ?? 1) ? 'checked' : '' ?>> DKIM</label>
                    </div>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><?= $editingPackage ? 'Cập nhật gói dịch vụ' : 'Tạo gói dịch vụ' ?></button>
                    <?php if ($editingPackage): ?>
                        <a href="/admin/packages" class="action-link secondary">Hủy</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel-header">
            <h2>Danh sách package</h2>
            <p>Rà nhanh quota nền, tenant đã gán và giới hạn gửi trước khi đổi gói cho tenant thực tế.</p>
        </div>

        <?php if ($packageRows === []): ?>
            <?= $view->render('admin/components/empty_state.php', [
                'title' => 'Chưa có package phù hợp',
                'description' => 'Tạo package đầu tiên hoặc đổi bộ lọc hiện tại.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>ID</th><th>Tên gói</th><th>Tên miền</th><th>Hộp thư</th><th>Bí danh / Chuyển tiếp</th><th>Dung lượng</th><th>Khách sử dụng</th><th>Gửi / giờ</th><th>Thao tác</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($packageRows as $item): ?>
                        <?php $assignedTenants = (int) ($packageTenantCounts[(int) ($item['id'] ?? 0)] ?? 0); ?>
                        <tr>
                            <td>#<?= (int) $item['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="table-note"><?= htmlspecialchars((string) ($item['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            </td>
                            <td><?= (int) ($item['max_domains'] ?? 0) ?></td>
                            <td><?= (int) ($item['max_mailboxes'] ?? 0) ?></td>
                            <td><?= (int) ($item['max_aliases'] ?? 0) ?> / <?= (int) ($item['max_forwarders'] ?? 0) ?></td>
                            <td><?= (int) ($item['max_total_quota_mb'] ?? 0) ?> MB</td>
                            <td><?= $assignedTenants ?></td>
                            <td><?= (int) ($item['outbound_per_hour'] ?? 0) ?></td>
                            <td>
                                <div class="inline-actions">
                                    <?php if ($canAccess('packages.update')): ?>
                                        <a href="/admin/packages?edit_package=<?= (int) $item['id'] ?>#package-create" class="btn btn-secondary btn-sm">Sửa</a>
                                    <?php endif; ?>
                                    <?php if ($canAccess('packages.delete')): ?>
                                        <form method="post" action="/admin/packages" data-confirm="Bạn có chắc muốn xóa package này không?">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="_intent" value="delete">
                                            <input type="hidden" name="package_id" value="<?= (int) $item['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Xóa</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?= $view->render('admin/components/pagination.php', ['pagination' => $packagesPagination]) ?>
        <?php endif; ?>
    </div>
