<?php

declare(strict_types=1);

$userCount = (int) ($stats['tenants'] ?? 0);
$domainCount = (int) ($stats['domains'] ?? 0);
$mailAccountCount = (int) ($stats['mailboxes'] ?? 0);
$aliasCount = (int) ($stats['aliases'] ?? 0);
$forwardCount = (int) ($stats['forwards'] ?? 0);
$quotaUsed = (int) ($stats['quota_used_mb'] ?? 0);
$configCount = count($configVersions ?? []);
$role = (string) ($identity['role'] ?? 'guest');
$roleLevel = match ($role) {
    'super_admin' => 'Admin level',
    'tenant_admin' => 'Cấp khách hàng',
    'domain_admin' => 'Cấp tên miền',
    'support_readonly' => 'Read-only ops',
    default => 'Scoped role',
};
$scopeLabel = match ($role) {
    'super_admin' => 'Toàn hệ thống',
    'tenant_admin' => 'Tài khoản khách hiện tại',
    'domain_admin' => 'Tên miền được cấp quyền',
    'support_readonly' => 'Phạm vi chỉ đọc',
    default => 'Theo phiên hiện tại',
};

$metrics = [
    ['label' => 'Khách hàng', 'value' => $userCount, 'hint' => 'Trong scope hiện tại', 'icon' => 'US', 'tone' => 'cyan'],
    ['label' => 'Tên miền quản lý', 'value' => $domainCount, 'hint' => 'Đang quản lý', 'icon' => 'DM', 'tone' => 'blue'],
    ['label' => 'Tài khoản Mail', 'value' => $mailAccountCount, 'hint' => 'Theo bộ lọc hiện tại', 'icon' => 'MA', 'tone' => 'indigo'],
    ['label' => 'Aliases', 'value' => $aliasCount, 'hint' => 'Đang hoạt động', 'icon' => 'AL', 'tone' => 'green'],
    ['label' => 'Forwards', 'value' => $forwardCount, 'hint' => 'Đang hoạt động', 'icon' => 'FW', 'tone' => 'amber'],
    ['label' => 'Dung lượng đã dùng', 'value' => number_format($quotaUsed) . ' MB', 'hint' => 'Dung lượng đã dùng', 'icon' => 'QT', 'tone' => 'violet'],
];

$overview = [
    ['label' => 'Level', 'value' => $roleLevel, 'hint' => 'Mức quyền của phiên hiện tại'],
    ['label' => 'Scope', 'value' => $scopeLabel, 'hint' => 'Dữ liệu đang được phép truy cập'],
    ['label' => 'Recent configs', 'value' => $role === 'super_admin' ? $configCount . ' bản' : 'Ẩn theo level', 'hint' => 'Draft và apply gần đây'],
    ['label' => 'Hộp thư / tên miền', 'value' => $domainCount > 0 ? number_format($mailAccountCount / max(1, $domainCount), 1) : '0', 'hint' => 'Mật độ trung bình'],
    ['label' => 'Dung lượng đã dùng', 'value' => number_format($quotaUsed) . ' MB', 'hint' => 'Theo usage hiện tại'],
];

$checklist = $role === 'super_admin'
    ? [
        ['label' => 'Cổng dịch vụ Mail', 'text' => 'Kiểm tra 465 và 587 sau mỗi lần đổi config.'],
        ['label' => '2FA', 'text' => 'Rà tài khoản quản trị chưa bật TOTP.'],
        ['label' => 'Apply', 'text' => 'Chỉ apply qua versioning để còn rollback.'],
    ]
    : [
        ['label' => 'Tên miền chính', 'text' => 'Kiểm tra primary domain trước khi tạo mailbox mới.'],
        ['label' => 'Dung lượng (Quota)', 'text' => 'Rà quota trước khi tăng thêm mailbox.'],
        ['label' => 'Cấu hình DNS', 'text' => 'Kiểm tra MX, SPF, DKIM, DMARC trước khi live.'],
    ];
?>
<section class="metrics-grid">
    <?php foreach ($metrics as $metric): ?>
        <?= $view->render('admin/components/metric_card.php', $metric) ?>
    <?php endforeach; ?>
</section>

<section class="panel panel-spaced">
    <div class="panel-header">
        <h2>Tổng quan nhanh</h2>
        <p>Số liệu chính của phiên hiện tại.</p>
    </div>

    <div class="ops-grid">
        <?php foreach ($overview as $item): ?>
            <article class="ops-item">
                <div class="ops-item__label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="ops-item__value"><?= htmlspecialchars((string) $item['value'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="ops-item__hint"><?= htmlspecialchars((string) $item['hint'], ENT_QUOTES, 'UTF-8') ?></div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($role === 'super_admin'): ?>
    <section class="panel panel-spaced">
        <div class="panel-header">
            <h2>Cấu hình gần đây</h2>
            <p>Theo dõi revision mới nhất.</p>
        </div>

        <?php if ($configVersions === []): ?>
            <?= $view->render('admin/components/empty_state.php', [
                'title' => 'Chưa có lịch sử cấu hình',
                'description' => 'Tạo draft đầu tiên để theo dõi lịch sử apply.',
            ]) ?>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>ID</th><th>Dịch vụ</th><th>Phiên bản</th><th>Trạng thái</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($configVersions as $item): ?>
                        <tr>
                            <td>#<?= (int) $item['id'] ?></td>
                            <td><?= htmlspecialchars((string) $item['service'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $item['version'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= $view->render('admin/components/status_badge.php', ['value' => $item['status'] ?? 'unknown']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <section class="panel panel-spaced">
        <div class="panel-header">
            <h2>Trọng tâm tài khoản</h2>
            <p>Các điểm cần rà trong scope hiện tại.</p>
        </div>

        <div class="checklist">
            <div class="checklist__item">
                <div>
                    <div class="checklist__label">Tên miền</div>
                    <div class="checklist__text"><?= number_format($domainCount) ?> domain trong phạm vi hiện tại.</div>
                </div>
            </div>
            <div class="checklist__item">
                <div>
                    <div class="checklist__label">Mail Accounts</div>
                    <div class="checklist__text"><?= number_format($mailAccountCount) ?> mail account, <?= number_format($quotaUsed) ?> MB usage.</div>
                </div>
            </div>
            <div class="checklist__item">
                <div>
                    <div class="checklist__label">Routing</div>
                    <div class="checklist__text"><?= number_format($aliasCount) ?> alias và <?= number_format($forwardCount) ?> forward.</div>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-header">
        <h2>Checklist vận hành</h2>
        <p>Những việc nên rà lại trước khi đổi cấu hình.</p>
    </div>

    <div class="checklist">
        <?php foreach ($checklist as $item): ?>
            <div class="checklist__item">
                <div>
                    <div class="checklist__label"><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="checklist__text"><?= htmlspecialchars((string) $item['text'], ENT_QUOTES, 'UTF-8') ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
