<?php

declare(strict_types=1);

$filters = $filters ?? [];
$configVersionRows = $configVersionRows ?? $configVersions;
$configVersionsPagination = $configVersionsPagination ?? [];
?>
<section class="metrics-grid">
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Lịch sử cấu hình', 'value' => count($configVersions), 'hint' => 'Theo bộ lọc hiện tại']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Đã áp dụng', 'value' => count(array_filter($configVersions, static fn (array $item): bool => ($item['status'] ?? '') === 'applied')), 'hint' => 'Đang active']) ?>
    <?= $view->render('admin/components/metric_card.php', ['label' => 'Bản nháp', 'value' => count(array_filter($configVersions, static fn (array $item): bool => ($item['status'] ?? '') === 'generated')), 'hint' => 'Bản nháp chờ validate/apply']) ?>
</section>

<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc service config',
    'action' => '/admin/config-versions',
    'summary' => 'Tìm service config theo service, version hoặc checksum.',
    'resultCount' => count($configVersionRows),
    'resultLabel' => 'revision',
    'resetHref' => '/admin/config-versions',
    'fields' => [
        [
            'label' => 'Tìm kiếm',
            'name' => 'search',
            'type' => 'search',
            'value' => (string) ($filters['search'] ?? ''),
            'placeholder' => 'nginx, exim, checksum...',
        ],
        [
            'label' => 'Trạng thái',
            'name' => 'status',
            'type' => 'select',
            'value' => (string) ($filters['status'] ?? ''),
            'options' => [
                ['value' => '', 'label' => 'Tất cả'],
                ['value' => 'generated', 'label' => 'generated'],
                ['value' => 'validated', 'label' => 'validated'],
                ['value' => 'applied', 'label' => 'applied'],
                ['value' => 'failed', 'label' => 'failed'],
                ['value' => 'rolled_back', 'label' => 'rolled_back'],
            ],
        ],
    ],
]) ?>

<?php if ($canAccess('config_versions.create')): ?>
    <div class="page-action-seed" data-page-action-seed>
        <form method="post" action="/admin/config-versions" class="form-actions">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="generate">
            <button class="btn btn-primary" type="submit">Tạo bản nháp mới</button>
        </form>
    </div>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <h2>Lịch sử service config</h2>
        <p>Theo dõi generate, validate, apply và rollback.</p>
    </div>

    <?php if ($configVersionRows === []): ?>
        <?= $view->render('admin/components/empty_state.php', [
            'title' => 'Chưa có lịch sử cấu hình',
            'description' => 'Tạo draft đầu tiên để theo dõi lịch sử thay đổi và rollback an toàn.',
        ]) ?>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>ID</th><th>Dịch vụ</th><th>Phiên bản</th><th>Trạng thái</th><th>Checksum</th><th>Thời gian áp dụng</th><th>Thao tác</th></tr>
                </thead>
                <tbody>
                <?php foreach ($configVersionRows as $item): ?>
                    <tr>
                        <td>#<?= (int) $item['id'] ?></td>
                        <td><?= htmlspecialchars((string) $item['service'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $item['version'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => $item['status'] ?? 'unknown']) ?></td>
                        <td><code><?= htmlspecialchars(substr((string) ($item['checksum'] ?? ''), 0, 16), ENT_QUOTES, 'UTF-8') ?></code>…</td>
                        <td><?= htmlspecialchars((string) ($item['applied_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <div class="inline-actions">
                                <?php if ($canAccess('config_versions.update')): ?>
                                    <form method="post" action="/admin/config-versions" data-confirm="Apply config revision này?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="apply">
                                        <input type="hidden" name="version_id" value="<?= (int) $item['id'] ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit">Áp dụng</button>
                                    </form>
                                <?php endif; ?>

                                <?php if (!empty($item['previous_version_id']) && $canAccess('config_versions.restore')): ?>
                                    <form method="post" action="/admin/config-versions" data-confirm="Rollback về revision này?">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="rollback">
                                        <input type="hidden" name="version_id" value="<?= (int) $item['id'] ?>">
                                        <button class="btn btn-secondary btn-sm" type="submit">Khôi phục</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?= $view->render('admin/components/pagination.php', ['pagination' => $configVersionsPagination]) ?>
    <?php endif; ?>
</section>
