<?php

declare(strict_types=1);

$filters = $filters ?? [];
$editingMailGroup = is_array($editingMailGroup ?? null) ? $editingMailGroup : null;
$editingMembers = $editingMailGroup === null ? '' : implode(",\n", $editingMailGroup['members'] ?? []);
$aliasRows = $aliasRows ?? $aliases;
$aliasesPagination = $aliasesPagination ?? [];
$forwardRows = $forwardRows ?? $forwards;
$forwardsPagination = $forwardsPagination ?? [];
$mailGroupRows = $mailGroupRows ?? $mailGroups;
$mailGroupsPagination = $mailGroupsPagination ?? [];
$mailboxIndex = [];

foreach (($mailboxes ?? []) as $mailbox) {
    $mailboxIndex[(int) ($mailbox['id'] ?? 0)] = $mailbox;
}
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Alias', 'value' => count($aliases), 'hint' => 'Nội bộ']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Forward', 'value' => count($forwards), 'hint' => 'Chuyển tiếp ra ngoài']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Mail Groups', 'value' => count($mailGroups), 'hint' => 'Nhóm nhận mail']) ?>
</section>

<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc mail routing',
    'action' => '/admin/routing',
    'summary' => 'Tìm alias, forward và mail group.',
    'resultCount' => count($aliases) + count($forwards) + count($mailGroups),
    'resultLabel' => 'bản ghi',
    'resetHref' => '/admin/routing',
    'fields' => [
        [
            'label' => 'Tìm kiếm',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'alias@example.com',
        ],
    ],
]) ?>

    <?php if ($canAccess('aliases.create')): ?>
        <div class="panel panel-spaced" id="alias-create">
            <div class="panel-header">
                <h2>Tạo alias nội bộ</h2>
                <p>Tạo thêm địa chỉ nhận mail cho mailbox có sẵn.</p>
            </div>

            <form method="post" action="/admin/routing">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="create_alias">
                <div class="form-grid">
                    <label class="field-span-4">Domain
                        <select name="domain_id" required>
                            <?php foreach (($domains ?? []) as $domain): ?>
                                <option value="<?= (int) $domain['id'] ?>"><?= htmlspecialchars((string) $domain['domain'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field-span-4">Local part<input name="local_part" required placeholder="sales"></label>
                    <label class="field-span-4">Mail account đích
                        <select name="destination_mailbox_id" required>
                            <?php foreach (($mailboxes ?? []) as $mailbox): ?>
                                <option value="<?= (int) $mailbox['id'] ?>"><?= htmlspecialchars((string) $mailbox['email'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="form-actions">
                    <label><input type="checkbox" name="keep_copy"> Giữ bản sao</label>
                    <button class="btn btn-primary" type="submit">Tạo bí danh</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($canAccess('forwards.create')): ?>
        <div class="panel panel-spaced" id="forward-create">
            <div class="panel-header">
                <h2>Tạo forward ra ngoài</h2>
                <p>Forward ra địa chỉ ngoài hệ thống.</p>
            </div>

            <form method="post" action="/admin/routing">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="create_forward">
                <div class="form-grid">
                    <label class="field-span-4">Domain
                        <select name="domain_id" required>
                            <?php foreach (($domains ?? []) as $domain): ?>
                                <option value="<?= (int) $domain['id'] ?>"><?= htmlspecialchars((string) $domain['domain'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field-span-4">Local part<input name="local_part" required placeholder="support"></label>
                    <label class="field-span-4">Email đích<input name="destination_address" type="email" required placeholder="external@example.net"></label>
                </div>
                <div class="form-actions">
                    <label><input type="checkbox" name="keep_copy"> Giữ bản sao</label>
                    <button class="btn btn-primary" type="submit">Tạo chuyển tiếp</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="panel panel-spaced">
        <div class="panel-header">
            <h2>Alias nội bộ</h2>
            <p>Các địa chỉ phụ nội bộ đang trỏ về mailbox đích trong cùng hệ thống.</p>
        </div>

        <?php if ($aliasRows === []): ?>
            <?= $view->render('admin/components/empty_state.php', [
                'title' => 'Chưa có alias',
                'description' => 'Tạo alias đầu tiên để mở rộng địa chỉ nhận mail.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>ID</th><th>Địa chỉ nguồn</th><th>Hộp thư nhận</th><th>Giữ bản sao</th><th>Thao tác</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($aliasRows as $item): ?>
                        <?php $mailbox = $mailboxIndex[(int) ($item['destination_mailbox_id'] ?? 0)] ?? null; ?>
                        <tr>
                            <td>#<?= (int) $item['id'] ?></td>
                            <td><?= htmlspecialchars((string) $item['source_address'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) ($mailbox['email'] ?? ('#' . (int) ($item['destination_mailbox_id'] ?? 0))), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['keep_copy']) ? 'active' : 'default', 'label' => !empty($item['keep_copy']) ? 'Có' : 'Không']) ?></td>
                            <td>
                                <?php if ($canAccess('aliases.delete')): ?>
                                    <form method="post" action="/admin/routing" data-confirm="Xóa alias này?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="_intent" value="delete_alias">
                                        <input type="hidden" name="alias_id" value="<?= (int) $item['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Xóa</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $view->render('admin/components/pagination.php', ['pagination' => $aliasesPagination]) ?>
        <?php endif; ?>
    </div>

    <div class="panel panel-spaced">
        <div class="panel-header">
            <h2>Forward ra ngoài</h2>
            <p>Các rule chuyển tiếp mail sang mailbox ngoài hệ thống.</p>
        </div>

        <?php if ($forwardRows === []): ?>
            <?= $view->render('admin/components/empty_state.php', [
                'title' => 'Chưa có forward',
                'description' => 'Tạo forward đầu tiên để chuyển tiếp mail sang hộp thư ngoài hệ thống.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>ID</th><th>Địa chỉ nguồn</th><th>Chuyển tiếp đến</th><th>Giữ bản sao</th><th>Thao tác</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($forwardRows as $item): ?>
                        <tr>
                            <td>#<?= (int) $item['id'] ?></td>
                            <td><?= htmlspecialchars((string) $item['source_address'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $item['destination_address'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $view->render('admin/components/status_badge.php', ['value' => !empty($item['keep_copy']) ? 'active' : 'default', 'label' => !empty($item['keep_copy']) ? 'Có' : 'Không']) ?></td>
                            <td>
                                <?php if ($canAccess('forwards.delete')): ?>
                                    <form method="post" action="/admin/routing" data-confirm="Xóa forward này?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="_intent" value="delete_forward">
                                        <input type="hidden" name="forward_id" value="<?= (int) $item['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Xóa</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $view->render('admin/components/pagination.php', ['pagination' => $forwardsPagination]) ?>
        <?php endif; ?>
    </div>

<section class="panel" id="mail-group-create">
    <div class="panel-header">
        <h2><?= $editingMailGroup === null ? 'Mail groups' : 'Sửa mail group' ?></h2>
        <p>Gom nhiều recipient vào một địa chỉ chung để hỗ trợ phòng ban, team và các hộp thư dùng chung.</p>
    </div>

    <?php if (($editingMailGroup === null && $canAccess('mail_groups.create')) || ($editingMailGroup !== null && $canAccess('mail_groups.update'))): ?>
        <form method="post" action="/admin/routing">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_intent" value="<?= $editingMailGroup === null ? 'create_group' : 'update_group' ?>">
            <?php if ($editingMailGroup !== null): ?>
                <input type="hidden" name="group_id" value="<?= (int) $editingMailGroup['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <label class="field-span-4">Domain
                    <select name="domain_id" required>
                        <?php foreach (($domains ?? []) as $domain): ?>
                            <option value="<?= (int) $domain['id'] ?>" <?= (int) ($editingMailGroup['domain_id'] ?? 0) === (int) $domain['id'] ? 'selected' : '' ?>><?= htmlspecialchars((string) $domain['domain'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field-span-4">Local part<input name="local_part" required placeholder="all-staff" value="<?= htmlspecialchars((string) ($editingMailGroup['local_part'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label class="field-span-4">Tên hiển thị<input name="display_name" required placeholder="All Staff" value="<?= htmlspecialchars((string) ($editingMailGroup['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                <label class="field-span-12">Recipients
                    <textarea name="members" rows="4" required placeholder="a@example.com,b@example.com"><?= htmlspecialchars($editingMembers, ENT_QUOTES, 'UTF-8') ?></textarea>
                </label>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" type="submit"><?= $editingMailGroup === null ? 'Tạo nhóm mail' : 'Cập nhật nhóm mail' ?></button>
                <?php if ($editingMailGroup !== null): ?>
                    <a class="action-link secondary" href="/admin/routing#mail-group-create">Hủy sửa</a>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($mailGroupRows === []): ?>
        <?= $view->render('admin/components/empty_state.php', [
            'title' => 'Chưa có mail group',
            'description' => 'Tạo mail group đầu tiên để gom nhiều recipient nội bộ hoặc bên ngoài.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>ID</th><th>Địa chỉ nhóm</th><th>Tên hiển thị</th><th>Danh sách nhận</th><th>Trạng thái</th><th>Thao tác</th></tr>
                </thead>
                <tbody>
                <?php foreach ($mailGroupRows as $group): ?>
                    <tr>
                        <td>#<?= (int) $group['id'] ?></td>
                        <td><?= htmlspecialchars((string) $group['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $group['display_name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars(implode(', ', $group['members'] ?? []), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => $group['status'] ?? 'unknown']) ?></td>
                        <td>
                            <div class="inline-actions">
                                <?php if ($canAccess('mail_groups.update')): ?>
                                    <a class="action-link secondary btn-sm" href="/admin/routing?edit_group=<?= (int) $group['id'] ?>#mail-group-create">Sửa</a>
                                <?php endif; ?>
                                <?php if ($canAccess('mail_groups.delete')): ?>
                                    <form method="post" action="/admin/routing" data-confirm="Xóa mail group này?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="_intent" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?= (int) $group['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Xóa</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?= $view->render('admin/components/pagination.php', ['pagination' => $mailGroupsPagination]) ?>
    <?php endif; ?>
</section>
