<?php

declare(strict_types=1);

$title = $title ?? 'Lọc SPAM cá nhân';
$summary = 'Cấu hình danh sách người gửi an toàn (Whitelist) và danh sách đen (Blacklist) cho từng tên miền của bạn.';
$policies = $policies ?? [];

// Group policies by domain so it's easy to render
?>
<section class="panel">
    <div class="panel-header">
        <h2>Lọc SPAM cá nhân</h2>
        <p><?= htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') ?></p>
        <p><em>Lưu ý: Nếu bị chặn bởi chính sách toàn hệ thống, cấu hình riêng này sẽ không có tác dụng.</em></p>
    </div>

    <?php if (empty($policies)): ?>
        <p class="empty-state">Bạn chưa có tên miền nào để cấu hình.</p>
    <?php else: ?>
        <?php foreach ($policies as $policy): ?>
        <div class="form-section form-section--separated">
            <h3 class="form-section__title">Tên miền: <?= htmlspecialchars($policy['domain'], ENT_QUOTES, 'UTF-8') ?></h3>
            <form action="/admin/spam-policies" method="post">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="domain_id" value="<?= htmlspecialchars((string) $policy['domain_id'], ENT_QUOTES, 'UTF-8') ?>">

                <div class="form-grid">
                    <label class="field-span-6">
                        <span class="text-success font-medium">Whitelist Người Gửi (Bỏ qua lọc SPAM)</span>
                        <textarea name="allowlist_senders" rows="4" placeholder="Nhập email hoặc @domain.com, mỗi dòng 1 mục"><?= htmlspecialchars((string) ($policy['allowlist_senders'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        <small>Nhập email (ví dụ: ceo@doitac.com) hoặc tên miền (ví dụ: @doitac.com), mỗi dòng một mục.</small>
                    </label>
                    
                    <label class="field-span-6">
                        <span class="text-danger font-medium">Blacklist Người Gửi (Chặn ngay lập tức)</span>
                        <textarea name="blocklist_senders" rows="4" placeholder="Nhập email hoặc @domain.com, mỗi dòng 1 mục"><?= htmlspecialchars((string) ($policy['blocklist_senders'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        <small>Nhập email hoặc tên miền bạn muốn chặn hoàn toàn.</small>
                    </label>
                </div>
                
                <div class="form-actions form-actions--spaced">
                    <button type="submit" class="button button--primary">Lưu cấu hình cho <?= htmlspecialchars($policy['domain'], ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>
