<?php

declare(strict_types=1);

$title = 'Hàng đợi thư (Mail Queue)';
$summary = 'Quản lý các email đang bị kẹt hoặc chờ gửi trong Exim.';
$active_menu = 'queue';
$items = $items ?? [];
$error = $error ?? null;
$success = $success ?? null;
$csrfToken = $csrfToken ?? '';
$itemCount = count($items);
?>
<?php if ($error): ?><div class="flash error"><strong>Lỗi:</strong> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<?php if (isset($_GET['success'])): ?><div class="flash success"><strong>Thành công:</strong> <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

<section class="panel">
    <div class="filter-toolbar__header">
        <div class="panel-intro">
            <h2>Hàng đợi hiện tại</h2>
            <p>Có <?= $itemCount ?> email trong hàng đợi. Từ đây bạn có thể xem chi tiết, ép gửi lại hoặc xóa email bị kẹt.</p>
        </div>
        <div class="filter-toolbar__meta"><span class="chip filter-toolbar__chip"><?= $itemCount ?> email</span></div>
    </div>

    <?php if ($itemCount > 0): ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Message ID</th><th>Thời gian</th><th>Kích thước</th><th>Người gửi</th><th>Trạng thái / Nhận</th><th>Thao tác</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><?= htmlspecialchars($item['time'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['size'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($item['sender'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($item['status']): ?><span class="badge warning"><?= htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                <?php foreach ($item['recipients'] as $rcpt): ?><div class="table-note"><?= htmlspecialchars($rcpt, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
                            </td>
                            <td>
                                <details class="action-menu">
                                    <summary class="btn btn-secondary btn-sm">Thao tác</summary>
                                    <div class="action-menu__content">
                                        <form method="post" action="/admin/queue"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="msg_id" value="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action_type" value="view"><button type="submit" class="btn btn-secondary btn-sm">Xem chi tiết</button></form>
                                        <form method="post" action="/admin/queue" data-confirm="Ép gửi lại email này?"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="msg_id" value="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action_type" value="deliver"><button type="submit" class="btn btn-primary btn-sm">Gửi lại</button></form>
                                        <form method="post" action="/admin/queue" data-confirm="Bạn có chắc muốn xóa email này khỏi hàng đợi vĩnh viễn?"><input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="msg_id" value="<?= htmlspecialchars($item['id'], ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action_type" value="delete"><button type="submit" class="btn btn-danger btn-sm">Xóa</button></form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <?= $view->render('admin/components/empty_state.php', ['title' => 'Hàng đợi trống', 'description' => 'Không có email nào bị kẹt trong queue.']) ?>
    <?php endif; ?>
</section>
