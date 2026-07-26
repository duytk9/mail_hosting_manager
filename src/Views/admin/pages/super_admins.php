<?php

declare(strict_types=1);

$superAdminRows = $superAdminRows ?? $superAdmins;
$superAdminsPagination = $superAdminsPagination ?? [];
$filters = $filters ?? [];
$superAdminIpAllowlist = $superAdminIpAllowlist ?? [];
$currentClientIp = $currentClientIp ?? '';
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Quản trị viên', 'value' => count($superAdmins), 'hint' => 'Đang hiển thị']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Cho phép SSH', 'value' => count(array_filter($superAdmins, static fn (array $item): bool => !empty($item['ssh_enabled']))), 'hint' => 'Đang bật']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Mật khẩu Sudo', 'value' => count(array_filter($superAdmins, static fn (array $item): bool => !empty($item['ssh_sudo_enabled']))), 'hint' => 'Đang bật']) ?>
</section>

<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc admin level',
    'action' => '/admin/super-admins',
    'summary' => 'Tìm theo tên, email recovery hoặc username.',
    'resultCount' => count($superAdminRows),
    'resultLabel' => 'tài khoản',
    'resetHref' => '/admin/super-admins',
    'fields' => [
        [
            'label' => 'Tìm kiếm',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'mp-admin-ops',
        ],
    ],
]) ?>

<?php if ($canAccess('super_admins.create')): ?>
    <section class="panel" id="super-admin-create">
        <div class="panel-header">
            <h2>Tạo Admin level account</h2>
            <p>Tạo user hệ thống, panel account và quyền SSH.</p>
        </div>

        <form method="post" action="/admin/super-admins" class="tab-container">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            
            <div class="tab-nav">
                <button type="button" class="tab-btn is-active">Tài khoản</button>
                <button type="button" class="tab-btn">Bảo mật & SSH</button>
            </div>

            <div class="tab-pane is-active">
                <div class="form-grid">
                    <label class="field-span-6">Tên<input name="name" required placeholder="Ops Admin"></label>
                    <label class="field-span-6">Email recovery<input name="email" type="email" required placeholder="ops@example.com"></label>
                    <label class="field-span-6">Mật khẩu<input name="password" type="password" required autocomplete="new-password"></label>
                    <label class="field-span-6">Username hệ thống<input name="username" required placeholder="mp-admin-ops"></label>
                </div>
            </div>

            <div class="tab-pane">
                <div class="form-grid">
                    <label class="field-span-6">Mật khẩu admin hiện tại
                        <input name="current_password" type="password" required autocomplete="current-password">
                    </label>
                    <label class="field-span-6">OTP hiện tại nếu đã bật 2FA
                        <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="123456">
                    </label>
                    <label class="field-span-12">SSH public key
                        <textarea name="ssh_public_key" rows="4" placeholder="Để trống nếu dùng SSH password login"></textarea>
                    </label>
                </div>
                <div class="choice-grid mt-16">
                    <label><input type="checkbox" name="ssh_enabled" checked> Bật SSH</label>
                    <label><input type="checkbox" name="ssh_sudo_enabled" checked> Cấp sudo bằng mật khẩu</label>
                    <label><input type="checkbox" name="force_password_change"> Bắt buộc đổi mật khẩu</label>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit">Tạo quản trị viên</button>
            </div>
        </form>

        <div class="notice">
            Tài khoản này sẽ tạo luôn Linux user. Email chỉ dùng cho recovery sau này.
        </div>
    </section>
<?php endif; ?>

    <?php if ($canAccess('super_admins.update')): ?>
        <div class="panel panel-spaced" id="super-admin-ip-allowlist">
            <div class="panel-header">
                <h2>IP allowlist cho Admin level</h2>
                <p>Giới hạn đăng nhập Admin level theo IP hoặc CIDR. Cấu hình này áp dụng cho lần đăng nhập tiếp theo.</p>
            </div>

            <div class="notice">
                IP hiện tại của bạn: <code><?= htmlspecialchars((string) ($currentClientIp ?? '-'), ENT_QUOTES, 'UTF-8') ?></code><br>
                Hỗ trợ IP đơn hoặc CIDR, mỗi dòng một mục. Ví dụ: <code>203.0.113.10</code>, <code>203.0.113.0/24</code>.
            </div>

            <form method="post" action="/admin/super-admins">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="update_ip_allowlist">
                <div class="form-grid">
                    <label class="field-span-12">Danh sách IP / CIDR
                        <textarea name="super_admin_ip_allowlist" rows="6" placeholder="203.0.113.10&#10;203.0.113.0/24"><?= htmlspecialchars((string) ($superAdminIpAllowlist['raw'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                    <label class="field-span-6">Mật khẩu admin hiện tại
                        <input name="current_password" type="password" required autocomplete="current-password">
                    </label>
                    <label class="field-span-6">OTP hiện tại nếu đã bật 2FA
                        <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="123456">
                    </label>
                </div>

                <div class="form-actions">
                    <label><input type="checkbox" name="super_admin_ip_allowlist_enabled" <?= !empty($superAdminIpAllowlist['enabled']) ? 'checked' : '' ?>> Bật IP allowlist</label>
                    <button class="btn btn-primary" type="submit">Lưu danh sách IP</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <h2>Admin level accounts</h2>
        <p>Tài khoản có quyền cao nhất trên Mailhosting Panel và có thể ssh vào server.</p>
    </div>

    <?php if (empty($superAdminRows)): ?>
        <div class="empty-state">
            <p>Không có Admin level account nào phù hợp.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Tên</th>
                        <th>Linux username</th>
                        <th>Recovery Email</th>
                        <th>SSH</th>
                        <th>Sudo</th>
                        <?php if ($canAccess('super_admins.update') || $canAccess('super_admins.delete')): ?>
                            <th class="table-col-actions"></th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($superAdminRows as $admin): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $admin['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if (!empty($admin['force_password_change'])): ?>
                                    <span class="badge warning badge-spaced">Must change pass</span>
                                <?php endif; ?>
                            </td>
                            <td><code class="selectable"><?= htmlspecialchars((string) ($admin['linux_username'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars((string) ($admin['email'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($admin['ssh_enabled']) ? '<span class="badge enabled">Bật</span>' : '<span class="badge disabled">Tắt</span>' ?></td>
                            <td><?= !empty($admin['ssh_sudo_enabled']) ? '<span class="badge enabled">Bật</span>' : '<span class="badge disabled">Tắt</span>' ?></td>
                            
                            <?php if ($canAccess('super_admins.update') || $canAccess('super_admins.delete')): ?>
                                <td>
                                    <details class="action-menu">
                                        <summary class="btn btn-sm">Thao tác</summary>
                                        <div class="action-menu__content">
                                            <div class="action-menu__header">
                                                <span><?= htmlspecialchars((string) $admin['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>

                                            <?php if ($canAccess('super_admins.update')): ?>
                                                <form method="post" action="/admin/super-admins" class="inline-actions">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_intent" value="reset_password">
                                                    <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                                                    <button class="action-link" type="button" data-open-modal="reset-pwd-<?= (int) $admin['id'] ?>">Đặt lại mật khẩu</button>
                                                </form>

                                                <form method="post" action="/admin/super-admins" data-confirm="<?= htmlspecialchars(empty($admin['ssh_enabled']) ? 'Bật SSH cho tài khoản này?' : 'Tắt SSH của tài khoản này?', ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_intent" value="toggle_ssh">
                                                    <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                                                    <input type="hidden" name="enable" value="<?= empty($admin['ssh_enabled']) ? '1' : '0' ?>">
                                                    <input name="current_password" type="password" required autocomplete="current-password" placeholder="Mật khẩu hiện tại">
                                                    <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="OTP nếu có">
                                                    <button class="action-link" type="submit"><?= empty($admin['ssh_enabled']) ? 'Bật SSH' : 'Tắt SSH' ?></button>
                                                </form>

                                                <form method="post" action="/admin/super-admins" data-confirm="<?= htmlspecialchars(empty($admin['ssh_sudo_enabled']) ? 'Bật Sudo cho tài khoản này?' : 'Tắt Sudo của tài khoản này?', ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_intent" value="toggle_sudo">
                                                    <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                                                    <input type="hidden" name="enable" value="<?= empty($admin['ssh_sudo_enabled']) ? '1' : '0' ?>">
                                                    <input name="current_password" type="password" required autocomplete="current-password" placeholder="Mật khẩu hiện tại">
                                                    <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="OTP nếu có">
                                                    <button class="action-link" type="submit"><?= empty($admin['ssh_sudo_enabled']) ? 'Bật Sudo' : 'Tắt Sudo' ?></button>
                                                </form>
                                            <?php endif; ?>

                                            <?php if ($canAccess('super_admins.delete')): ?>
                                                <form method="post" action="/admin/super-admins" data-confirm="Xóa Admin level account này?" class="danger-zone-form">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_intent" value="delete">
                                                    <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">
                                                    <input name="current_password" type="password" required autocomplete="current-password" placeholder="Mật khẩu hiện tại">
                                                    <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="OTP nếu có">
                                                    <label class="checkbox-row">
                                                        <input type="checkbox" name="purge_linux_account" class="compact-checkbox"> Xóa luôn Linux user
                                                    </label>
                                                    <button class="btn btn-danger btn-sm btn-block" type="submit">Xóa tài khoản</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </details>

                                    <?php if ($canAccess('super_admins.update')): ?>
                                        <dialog class="admin-modal admin-modal--compact" id="reset-pwd-<?= (int) $admin['id'] ?>">
                                            <section class="panel admin-modal__panel">
                                                <button type="button" class="icon-btn admin-modal__close" data-modal-close aria-label="Đóng">×</button>
                                                <div class="panel-header">
                                                    <h2>Đặt lại mật khẩu</h2>
                                                    <p>Đổi mật khẩu cho: <?= htmlspecialchars((string) $admin['name'], ENT_QUOTES, 'UTF-8') ?></p>
                                                </div>
                                                <form method="post" action="/admin/super-admins">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="_intent" value="reset_password">
                                                    <input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>">

                                                    <div class="form-grid">
                                                        <label class="field-span-6">Mật khẩu admin hiện tại
                                                            <input name="current_password" type="password" required autocomplete="current-password">
                                                        </label>
                                                        <label class="field-span-6">OTP hiện tại nếu đã bật 2FA
                                                            <input name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="123456">
                                                        </label>
                                                        <p class="field-span-12 form-hint">Hệ thống sẽ sinh mật khẩu tạm thời mạnh, đồng bộ Linux user và bắt buộc đổi mật khẩu sau đăng nhập.</p>
                                                    </div>

                                                    <div class="form-actions">
                                                        <button class="btn btn-primary" type="submit">Đổi mật khẩu</button>
                                                    </div>
                                                </form>
                                            </section>
                                        </dialog>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $view->render('admin/components/pagination.php', ['pagination' => $superAdminsPagination]) ?>
    <?php endif; ?>
</section>

