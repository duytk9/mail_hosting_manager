<?php

declare(strict_types=1);

$filters = $filters ?? [];
$tenantRows = $tenantRows ?? $tenants;
$tenantsPagination = $tenantsPagination ?? [];
$tenantAdminRows = $tenantAdminRows ?? $tenantAdmins;
$tenantAdminsPagination = $tenantAdminsPagination ?? [];
$editingTenant = $editingTenant ?? null;
$editingTenantAdmin = $editingTenantAdmin ?? null;
$editingTenantAdminEmail = strtolower((string) ($editingTenantAdmin['email'] ?? ''));
$editingTenantAdminLocalPart = $editingTenantAdminEmail !== '' && str_contains($editingTenantAdminEmail, '@')
    ? substr($editingTenantAdminEmail, 0, (int) strpos($editingTenantAdminEmail, '@'))
    : $editingTenantAdminEmail;
$editingTenantAdminDomain = $editingTenantAdminEmail !== '' && str_contains($editingTenantAdminEmail, '@')
    ? substr($editingTenantAdminEmail, (int) strpos($editingTenantAdminEmail, '@') + 1)
    : '';
$tenantUsage = $tenantUsage ?? [];
$tenantAdminLookup = $tenantAdminLookup ?? $tenantAdmins;
$tenantAdminIndex = [];
$packages = $packages ?? [];
$billingStatusOptions = ['active', 'grace', 'expired', 'suspended', 'terminated'];

foreach ($tenantAdminLookup as $tenantAdmin) {
    $tenantAdminIndex[(int) ($tenantAdmin['id'] ?? 0)] = $tenantAdmin;
}
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Khách hàng', 'value' => count($tenants), 'hint' => 'Theo bộ lọc hiện tại']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Tài khoản Owner', 'value' => count($tenantAdmins), 'hint' => 'Tài khoản user level']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Đang hoạt động', 'value' => count(array_filter($tenants, static fn (array $item): bool => ($item['status'] ?? '') === 'active')), 'hint' => 'Đang hoạt động']) ?>
</section>

<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc users / user level',
    'action' => '/admin/tenants',
    'summary' => 'Tìm user, trạng thái và owner account.',
    'resultCount' => count($tenantRows),
    'resultLabel' => 'user',
    'resetHref' => '/admin/tenants',
    'fields' => [
        [
            'label' => 'Tìm user',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'Tên user hoặc slug',
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
                ['value' => 'archived', 'label' => 'archived'],
            ],
        ],
        [
            'label' => 'Tìm owner',
            'name' => 'admin_search',
            'type' => 'search',
            'value' => (string) ($filters['admin_search'] ?? ''),
            'placeholder' => 'Mailbox owner hoặc username',
        ],
    ],
]) ?>

<?php if ($isSuperAdmin && ($canAccess('tenants.create') || ($editingTenant && $canAccess('tenants.update')))): ?>
    <section class="panel" id="tenant-create">
        <div class="panel-header">
            <h2><?= $editingTenant ? 'Cập nhật user level' : 'Tạo user level' ?></h2>
            <p>Thiết lập tenant, package và owner account.</p>
        </div>



            <form method="post" action="/admin/tenants" class="tab-container">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($editingTenant): ?>
                    <input type="hidden" name="_intent" value="update">
                    <input type="hidden" name="tenant_id" value="<?= (int) $editingTenant['id'] ?>">
                <?php endif; ?>

                <div class="tab-nav">
                    <button type="button" class="tab-btn is-active">Cơ bản</button>
                    <?php if (!$editingTenant): ?>
                        <button type="button" class="tab-btn">Khởi tạo domain</button>
                    <?php endif; ?>
                    <button type="button" class="tab-btn">Slot bổ sung</button>
                    <button type="button" class="tab-btn">Giới hạn tùy chỉnh</button>
                </div>

                <div class="tab-pane is-active">
                    <div class="form-grid">
                        <label class="field-span-6">Tên user
                            <input name="name" id="tenant_name" required placeholder="Demo User 2" value="<?= htmlspecialchars((string) ($editingTenant['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="field-span-6">Username / slug
                            <input name="slug" id="tenant_slug" required placeholder="demo-user-2" value="<?= htmlspecialchars((string) ($editingTenant['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="field-span-6">Gói được gán
                            <select name="package_id" required>
                                <?php foreach ($packages as $package): ?>
                                    <option value="<?= (int) $package['id'] ?>" <?= (($editingTenant['package_id'] ?? 0) == $package['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars((string) $package['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field-span-6">Trạng thái
                            <select name="status">
                                <option value="active" <?= (($editingTenant['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>active</option>
                                <option value="suspended" <?= (($editingTenant['status'] ?? '') === 'suspended') ? 'selected' : '' ?>>suspended</option>
                                <option value="archived" <?= (($editingTenant['status'] ?? '') === 'archived') ? 'selected' : '' ?>>archived</option>
                            </select>
                        </label>
                        <label class="field-span-6">Trạng thái thanh toán
                            <select name="billing_status">
                                <?php foreach ($billingStatusOptions as $status): ?>
                                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>" <?= (($editingTenant['billing_status'] ?? 'active') === $status) ? 'selected' : '' ?>><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field-span-6">Ngày bắt đầu
                            <input name="starts_at" type="date" value="<?= htmlspecialchars(substr((string) ($editingTenant['starts_at'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="field-span-6">Ngày hết hạn
                            <input id="tenant_expires_at" name="expires_at" type="date" value="<?= htmlspecialchars(substr((string) ($editingTenant['expires_at'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8') ?>">
                        </label>
                        <label class="field-span-6">Grace đến ngày
                            <input id="tenant_grace_until" name="grace_until" type="date" value="<?= htmlspecialchars(substr((string) ($editingTenant['grace_until'] ?? ''), 0, 10), ENT_QUOTES, 'UTF-8') ?>">
                            <span class="field-hint">Tối thiểu sau ngày hết hạn 1 ngày.</span>
                        </label>
                        <label class="field-span-12">Tenant note
                            <textarea name="note" rows="3" placeholder="Ghi chú nội bộ cho tenant này"><?= htmlspecialchars((string) ($editingTenant['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </label>
                    </div>
                </div>

                <?php if (!$editingTenant): ?>
                    <div class="tab-pane">
                        <p class="form-section__description form-section__description--spaced">Tạo primary domain và owner account ngay từ đầu.</p>
                        <div class="form-grid">
                            <label class="field-span-6">Primary domain
                                <input name="primary_domain" type="text" placeholder="company-a.com" required>
                            </label>
                            <label class="field-span-6">Tên owner
                                <input name="admin_name" type="text" placeholder="Nguyễn Văn A" required>
                            </label>
                            <label class="field-span-6">Mailbox owner
                                <div class="field-with-addon">
                                    <input name="admin_local_part" type="text" placeholder="admin" required>
                                    <span class="field-addon">@ &lt;Primary Domain&gt;</span>
                                </div>
                            </label>
                            <label class="field-span-6">Username hệ thống
                                <input name="admin_username" type="text" placeholder="usera-owner" required>
                            </label>
                            <label class="field-span-6">Mật khẩu owner
                                <input name="admin_password" id="admin_password" type="password" required autocomplete="new-password">
                            </label>
                            <label class="field-span-6 field--align-end">
                                <div><input type="checkbox" name="admin_force_change" value="1" checked> Bắt buộc owner đổi mật khẩu lần tới</div>
                            </label>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="tab-pane">
                    <p class="form-section__description form-section__description--spaced">Các giá trị này cộng thêm vào quota của package.</p>
                    <div class="form-grid">
                        <label class="field-span-4">Số tên miền bổ sung<input name="extra_domains" type="number" min="0" value="<?= (int) ($editingTenant['extra_domains'] ?? 0) ?>"></label>
                        <label class="field-span-4">Số hộp thư bổ sung<input name="extra_mailboxes" type="number" min="0" value="<?= (int) ($editingTenant['extra_mailboxes'] ?? 0) ?>"></label>
                        <label class="field-span-4">Dung lượng bổ sung (MB)<input name="extra_total_quota_mb" type="number" min="0" value="<?= (int) ($editingTenant['extra_total_quota_mb'] ?? 0) ?>"></label>
                        <label class="field-span-6">Số bí danh bổ sung<input name="extra_aliases" type="number" min="0" value="<?= (int) ($editingTenant['extra_aliases'] ?? 0) ?>"></label>
                        <label class="field-span-6">Số chuyển tiếp bổ sung<input name="extra_forwarders" type="number" min="0" value="<?= (int) ($editingTenant['extra_forwarders'] ?? 0) ?>"></label>
                    </div>
                </div>

                <div class="tab-pane">
                    <div class="mb-16">
                        <input type="hidden" name="is_custom_limits" value="0">
                        <label><input type="checkbox" name="is_custom_limits" id="toggle-custom-limits" value="1" <?= !empty($editingTenant['is_custom_limits']) ? 'checked' : '' ?>> Tùy chỉnh giới hạn custom (ghi đè package và phần bổ sung)</label>
                    </div>
                    <div id="custom-limits-fields" class="form-grid" <?= empty($editingTenant['is_custom_limits']) ? 'hidden' : '' ?>>
                        <label class="field-span-4">Số tên miền tối đa<input name="max_domains" type="number" min="0" value="<?= (int) ($editingTenant['max_domains'] ?? 0) ?>"></label>
                        <label class="field-span-4">Số hộp thư tối đa<input name="max_mailboxes" type="number" min="0" value="<?= (int) ($editingTenant['max_mailboxes'] ?? 0) ?>"></label>
                        <label class="field-span-4">Dung lượng tối đa (MB)<input name="max_total_quota_mb" type="number" min="0" value="<?= (int) ($editingTenant['max_total_quota_mb'] ?? 0) ?>"></label>
                        <label class="field-span-6">Số bí danh tối đa<input name="max_aliases" type="number" min="0" value="<?= (int) ($editingTenant['max_aliases'] ?? 0) ?>"></label>
                        <label class="field-span-6">Số chuyển tiếp tối đa<input name="max_forwarders" type="number" min="0" value="<?= (int) ($editingTenant['max_forwarders'] ?? 0) ?>"></label>
                    </div>
                </div>

                <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editingTenant ? 'Cập nhật user level' : 'Tạo user level' ?></button>
                <?php if ($editingTenant): ?>
                    <a href="/admin/tenants" class="action-link secondary">Hủy</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <h2>Danh sách user level</h2>
        <p>Rà package, owner account và quota của từng tenant.</p>
    </div>

    <?php if ($tenantRows === []): ?>
        <?= $view->render('admin/components/empty_state.php', [
            'title' => 'Chưa có user phù hợp',
            'description' => 'Tạo user level đầu tiên hoặc đổi bộ lọc hiện tại.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>ID</th><th>Tên khách hàng</th><th>Đường dẫn (Slug)</th><th>Trạng thái</th><th>Hạn dịch vụ</th><th>Gói dịch vụ</th><th>Chủ sở hữu (Owner)</th><th>Tên miền</th><th>Hộp thư</th><th>Dung lượng (MB)</th><th>Thao tác</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tenantRows as $item): ?>
                    <?php $usage = $tenantUsage[$item['id']] ?? ['domains' => 0, 'mailboxes' => 0, 'quota_mb' => 0]; ?>
                    <?php $owner = $tenantAdminIndex[(int) ($item['admin_user_id'] ?? 0)] ?? null; ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?> <?= !empty($item['is_custom_limits']) ? '<span class="chip chip--compact">Custom</span>' : '' ?></td>
                        <td>
                            <strong><?= htmlspecialchars((string) $item['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($item['note'])): ?>
                                <div class="table-note"><?= htmlspecialchars((string) $item['note'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td><code><?= htmlspecialchars((string) $item['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td>
                            <?= $view->render('admin/components/status_badge.php', ['value' => $item['status'] ?? 'unknown']) ?>
                            <?= $view->render('admin/components/status_badge.php', ['value' => $item['effective_billing_status'] ?? ($item['billing_status'] ?? 'active'), 'label' => $item['effective_billing_status'] ?? ($item['billing_status'] ?? 'active')]) ?>
                        </td>
                        <td>
                            <?php if (!empty($item['expires_at'])): ?>
                                <strong><?= htmlspecialchars(substr((string) $item['expires_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (array_key_exists('days_until_expiry', $item) && $item['days_until_expiry'] !== null): ?>
                                    <div class="table-note"><?= (int) $item['days_until_expiry'] ?> ngày</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="table-note">Không giới hạn</span>
                            <?php endif; ?>
                            <?php if (!empty($item['grace_until'])): ?>
                                <div class="table-note">Grace: <?= htmlspecialchars(substr((string) $item['grace_until'], 0, 10), ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars((string) ($item['package_label'] ?? ('#' . (int) ($item['package_id'] ?? 0))), ENT_QUOTES, 'UTF-8') ?></strong>
                            <?php if (!empty($item['extra_allocations_summary'])): ?>
                                <div class="table-note"><?= htmlspecialchars((string) $item['extra_allocations_summary'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                            <?php if (!empty($item['is_custom_limits'])): ?>
                                <div class="table-note">Custom effective limits</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (is_array($owner)): ?>
                                <strong><?= htmlspecialchars((string) (($owner['linux_username'] ?? '') !== '' ? $owner['linux_username'] : ($owner['email'] ?? ('#' . (int) ($item['admin_user_id'] ?? 0)))), ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($owner['email'])): ?>
                                    <div class="table-note"><?= htmlspecialchars((string) $owner['email'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                #<?= (int) ($item['admin_user_id'] ?? 0) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $usage['domains'] ?> / <?= (int) ($item['max_domains'] ?? 0) ?></td>
                        <td><?= (int) $usage['mailboxes'] ?> / <?= (int) ($item['max_mailboxes'] ?? 0) ?></td>
                        <td>
                            <?= (int) $usage['quota_mb'] ?> / <?= (int) ($item['max_total_quota_mb'] ?? 0) ?>
                            <?php if ((int) ($item['extra_total_quota_mb'] ?? 0) > 0 || !empty($item['is_custom_limits'])): ?>
                                <div class="table-note">Base <?= (int) ($item['package_base_total_quota_mb'] ?? 0) ?> MB<?= (int) ($item['extra_total_quota_mb'] ?? 0) > 0 ? ' + extra ' . (int) ($item['extra_total_quota_mb'] ?? 0) . ' MB' : '' ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="inline-actions">
                                <?php if ($isSuperAdmin && $canAccess('tenants.update')): ?>
                                    <a href="/admin/tenants?edit_tenant=<?= (int) $item['id'] ?>#tenant-create" class="btn btn-secondary btn-sm">Sửa</a>
                                <?php endif; ?>
                                <?php if ($isSuperAdmin && $canAccess('tenants.delete')): ?>
                                    <form method="post" action="/admin/tenants" data-confirm="Bạn có chắc muốn xóa user level này không?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="_intent" value="delete">
                                        <input type="hidden" name="tenant_id" value="<?= (int) $item['id'] ?>">
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

        <?= $view->render('admin/components/pagination.php', ['pagination' => $tenantsPagination]) ?>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Owner accounts</h2>
        <p>Mỗi user level có một owner account đăng nhập bằng username hệ thống; mailbox quản trị luôn bám theo primary domain của user đó.</p>
    </div>

    <?php if ($editingTenantAdmin): ?>
        <div class="form-section" id="tenant-admin-edit">
            <h3 class="form-section__title">Sửa owner account #<?= (int) $editingTenantAdmin['id'] ?></h3>
            <form method="post" action="/admin/tenants">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="edit_tenant_admin">
                <input type="hidden" name="admin_id" value="<?= (int) $editingTenantAdmin['id'] ?>">

                <div class="form-grid">
                    <label>Tên
                        <input name="name" required value="<?= htmlspecialchars((string) $editingTenantAdmin['name'], ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label class="field-span-6">Mailbox owner
                        <div class="field-with-addon">
                            <input name="local_part" required value="<?= htmlspecialchars((string) $editingTenantAdminLocalPart, ENT_QUOTES, 'UTF-8') ?>">
                            <span class="field-addon">@ <?= htmlspecialchars((string) ($editingTenantAdminDomain !== '' ? $editingTenantAdminDomain : '<primary-domain>'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </label>
                    <label>Username hệ thống
                        <input name="username" required value="<?= htmlspecialchars((string) ($editingTenantAdmin['linux_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    </label>
                    <label><input type="checkbox" name="reset_password" value="1"> Reset Linux/panel password</label>
                    <label class="field-span-6">Mật khẩu admin hiện tại khi reset
                        <input name="current_password" type="password" autocomplete="current-password">
                    </label>
                    <label class="field-span-6">OTP hiện tại nếu đã bật 2FA
                        <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="123456">
                    </label>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">Lưu thay đổi</button>
                    <a href="/admin/tenants" class="action-link secondary">Hủy</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($tenantAdminRows === []): ?>
        <?= $view->render('admin/components/empty_state.php', [
            'title' => 'Chưa có owner account',
            'description' => 'Tạo user level mới để khởi tạo owner account đầu tiên.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>ID</th><th>Họ tên</th><th>Hộp thư Owner</th><th>Tên đăng nhập</th><th>ID khách</th><th>Thao tác</th></tr>
                </thead>
                <tbody>
                <?php foreach ($tenantAdminRows as $admin): ?>
                    <tr>
                        <td>#<?= (int) $admin['id'] ?></td>
                        <td><?= htmlspecialchars((string) $admin['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $admin['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars((string) ($admin['linux_username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td>#<?= (int) ($admin['tenant_id'] ?? 0) ?></td>
                        <td>
                            <div class="inline-actions">
                                <a href="?edit_tenant_admin=<?= (int) $admin['id'] ?>" class="btn btn-secondary btn-sm">Sửa</a>
                                <?php if ($isSuperAdmin): ?>
                                    <form method="post" action="/admin/impersonate" data-confirm="Impersonate owner account này để xem đúng scope user level?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                                        <button type="submit" class="btn btn-primary btn-sm">Đăng nhập thay</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($isSuperAdmin): ?>
                                    <form method="post" action="/admin/tenants" data-confirm="Bạn có chắc muốn xóa owner account này không?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="_intent" value="delete_tenant_admin">
                                        <input type="hidden" name="admin_id" value="<?= (int) $admin['id'] ?>">
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

        <?= $view->render('admin/components/pagination.php', ['pagination' => $tenantAdminsPagination]) ?>
    <?php endif; ?>
</section>

