<?php

declare(strict_types=1);

$title = 'Cấu hình lọc spam (Rspamd)';
$summary = 'Tùy chỉnh các mốc điểm spam để quyết định hành động đối với email đến.';
$scores = $scores ?? [];
$error = $scores['error'] ?? null;
?>
<section class="panel">
    <div class="panel-header">
        <h2>Cấu hình Spam Scores</h2>
        <p>Rspamd đánh giá mỗi email bằng một điểm spam. Điểm càng cao thì thư càng giống spam và hành động xử lý sẽ càng mạnh.</p>
    </div>

    <?php if ($error): ?><div class="flash error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form action="/admin/rspamd" method="post">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="form-section">
            <h3 class="form-section__title">Điểm số chống Spam (Spam Scores)</h3>
            <p class="form-section__description">Tùy chỉnh các mốc điểm rủi ro. Điểm càng cao thì hệ thống sẽ thực hiện hành động càng mạnh.</p>
            <div class="form-grid">
                <label class="field-span-4">
                    <span class="text-danger font-medium">Điểm chặn (Reject)</span>
                    <input type="number" step="0.1" name="reject" id="reject" value="<?= htmlspecialchars((string) ($scores['reject'] ?? 15), ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <label class="field-span-4">
                    <span class="text-warning font-medium">Điểm gắn thẻ (Add Header)</span>
                    <input type="number" step="0.1" name="add_header" id="add_header" value="<?= htmlspecialchars((string) ($scores['add_header'] ?? 6), ENT_QUOTES, 'UTF-8') ?>">
                </label>
                <label class="field-span-4">
                    <span class="text-muted font-medium">Điểm danh sách xám (Greylist)</span>
                    <input type="number" step="0.1" name="greylist" id="greylist" value="<?= htmlspecialchars((string) ($scores['greylist'] ?? 4), ENT_QUOTES, 'UTF-8') ?>">
                </label>
            </div>
        </div>

        <div class="form-section">
            <h3 class="form-section__title">Whitelist & Blacklist (Danh sách Trắng / Đen)</h3>
            <p class="form-section__description">Nhập IP, Email hoặc Tên miền (mỗi mục 1 dòng) để Bypass hoặc Block hoàn toàn hệ thống lọc Rspamd.</p>
            <div class="form-grid">
                <div class="field-span-6">
                    <label>
                        <span class="text-success font-medium">IP Whitelist (Bỏ qua kiểm tra)</span>
                        <textarea name="ip_wl" rows="5" placeholder="1.2.3.4&#10;192.168.1.0/24"><?= htmlspecialchars((string) ($multimaps['ip_wl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                </div>
                <div class="field-span-6">
                    <label>
                        <span class="text-danger font-medium">IP Blacklist (Chặn tuyệt đối)</span>
                        <textarea name="ip_bl" rows="5" placeholder="5.6.7.8&#10;10.0.0.0/8"><?= htmlspecialchars((string) ($multimaps['ip_bl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                </div>
                <div class="field-span-6">
                    <label>
                        <span class="text-success font-medium">Sender Whitelist (Email/Domain tin cậy)</span>
                        <textarea name="sender_wl" rows="5" placeholder="nguoiquen@gmail.com&#10;@doitac.com"><?= htmlspecialchars((string) ($multimaps['sender_wl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                </div>
                <div class="field-span-6">
                    <label>
                        <span class="text-danger font-medium">Sender Blacklist (Email/Domain cấm)</span>
                        <textarea name="sender_bl" rows="5" placeholder="spammer@bad.com&#10;@spam-domain.xyz"><?= htmlspecialchars((string) ($multimaps['sender_bl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                </div>
                
                <div class="field-span-6">
                    <label>
                        <span class="text-success font-medium">Recipient Whitelist (Bỏ qua cho người nhận)</span>
                        <textarea name="rcpt_wl" rows="5" placeholder="sales@domain.com&#10;contact@domain.com"><?= htmlspecialchars((string) ($multimaps['rcpt_wl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                </div>
                <div class="field-span-6">
                    <label>
                        <span class="text-danger font-medium">Recipient Blacklist (Chặn tuyệt đối gửi đến)</span>
                        <textarea name="rcpt_bl" rows="5" placeholder="ketoan@domain.com&#10;@domain-bi-khoa.com"><?= htmlspecialchars((string) ($multimaps['rcpt_bl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                    </label>
                </div>
            </div>
        </div>

        <div class="form-actions"><button type="submit" class="btn btn-primary">Lưu toàn bộ cấu hình</button></div>
    </form>

    <div class="panel-header">
        <h3>Dữ liệu cấu hình thô (từ rspamadm)</h3>
        <p>Đây là output từ lệnh <code>rspamadm configdump actions</code>, chứa các ngưỡng điểm thực tế đang được sử dụng.</p>
    </div>
    <div class="log-console"><pre><?= htmlspecialchars((string) ($scores['raw'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></div>
</section>
