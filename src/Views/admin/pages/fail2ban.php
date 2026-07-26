<?php

declare(strict_types=1);

$title = 'Quản lý Fail2ban';
$summary = 'Xem danh sách các IP đang bị chặn và mở khóa IP trực tiếp từ admin.';
$statusData = $statusData ?? [];
$jails = $statusData['jails'] ?? [];
$error = $statusData['error'] ?? null;
?>
<section class="panel">
    <div class="panel-header">
        <h2>Trạng thái Fail2ban</h2>
        <p>Hiển thị các IP đang bị cấm do có dấu hiệu brute-force trên SSH, webmail hoặc dịch vụ mail.</p>
    </div>

    <?php if ($error): ?><div class="flash error"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if (empty($jails)): ?>
        <?= $view->render('admin/components/empty_state.php', ['title' => 'Hệ thống an toàn', 'description' => 'Chưa có IP nào bị cấm hoặc chưa có dữ liệu jail.']) ?>
    <?php else: ?>
        <div class="checklist">
            <?php foreach ($jails as $jail => $ips): ?>
                <section class="panel panel--compact">
                    <div class="filter-toolbar__header flex-between-start">
                        <div>
                            <h3>Jail: <?= htmlspecialchars((string) $jail, ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="text-muted">Theo dõi IP đang bị cấm và thực hiện unban có kiểm soát.</p>
                        </div>
                        <span class="badge <?= count($ips) > 0 ? 'failed' : 'ok' ?>"><?= count($ips) ?> IP bị cấm</span>
                    </div>
                    <?php if (count($ips) === 0): ?>
                        <div class="empty-state empty-state--compact">✅ An toàn. Jail này hiện chưa có IP nào bị khóa.</div>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table>
                                <thead><tr><th>Địa chỉ IP</th><th>Hành động</th></tr></thead>
                                <tbody>
                                    <?php foreach ($ips as $ip): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $ip, ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td>
                                                <form action="/admin/fail2ban/unban" method="post" data-confirm="Bạn có chắc chắn muốn gỡ cấm IP này?">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="jail" value="<?= htmlspecialchars((string) $jail, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="ip" value="<?= htmlspecialchars((string) $ip, ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">Gỡ cấm (Unban)</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="panel-header">
        <h3>Dữ liệu thô từ Fail2ban Client</h3>
        <p>Nội dung gốc trả về từ `fail2ban-client` để đối chiếu khi cần debug sâu.</p>
    </div>
    <div class="log-console"><pre><?= htmlspecialchars((string) ($statusData['raw'] ?? ''), ENT_QUOTES, 'UTF-8') ?></pre></div>
</section>
