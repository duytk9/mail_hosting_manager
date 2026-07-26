<?php

declare(strict_types=1);
?>
<div class="empty-state">
    <h3><?= htmlspecialchars((string) ($title ?? 'Chưa có dữ liệu'), ENT_QUOTES, 'UTF-8') ?></h3>
    <p><?= htmlspecialchars((string) ($description ?? 'Hãy tạo dữ liệu đầu tiên để bắt đầu.'), ENT_QUOTES, 'UTF-8') ?></p>
</div>
