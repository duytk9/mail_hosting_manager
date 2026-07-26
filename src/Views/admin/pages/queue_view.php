<?php

declare(strict_types=1);

$title = 'Chi tiết email trong hàng đợi';
$summary = 'Xem tiêu đề và nội dung của thư bị kẹt.';
$active_menu = 'queue';

$msgId = $msgId ?? '';
$content = $content ?? '';
?>

<section class="panel">
    <div class="filter-toolbar__header">
        <div class="panel-intro">
            <h2>Message ID: <?= htmlspecialchars($msgId, ENT_QUOTES, 'UTF-8') ?></h2>
            <p>Nội dung raw của email trong queue để đối chiếu header, body và lỗi giao nhận.</p>
        </div>
        <div class="filter-toolbar__meta">
            <a href="/admin/queue" class="btn btn-secondary">Quay lại hàng đợi</a>
        </div>
    </div>

    <div class="surface-muted">
        <pre class="mono queue-message-content"><?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?></pre>
    </div>
</section>
