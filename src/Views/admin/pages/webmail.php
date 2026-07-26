<?php
/**
 * @var array<string, mixed> $snapshot
 * @var array<string, mixed> $fail2ban
 * @var \MailPanel\Support\View $view
 */

$boolBadge = static fn(bool $value, string $trueLabel = 'OK', string $falseLabel = 'Lỗi'): string =>
    sprintf('<span class="badge %s">%s</span>', $value ? 'ok' : 'failed', htmlspecialchars($value ? $trueLabel : $falseLabel, ENT_QUOTES, 'UTF-8'));

$fail2banJails = is_array($fail2ban['jails'] ?? null) ? $fail2ban['jails'] : [];
$authLogTail = is_array($snapshot['auth_log_tail'] ?? null) ? $snapshot['auth_log_tail'] : [];
?>

<div class="page-action-bar">
    <div class="page-action-bar__title">
        <h1>Webmail Health (Roundcube)</h1>
        <p>Kiểm tra trạng thái cấu hình và log của hệ thống webmail Roundcube.</p>
    </div>
</div>

<div class="dashboard-grid">
    <article class="stat-card"><span class="stat-card__label">Trạng thái</span><strong class="stat-card__value"><?= !empty($snapshot['enabled']) ? 'Enabled' : 'Disabled' ?></strong><span class="stat-card__hint"><?= htmlspecialchars((string) ($snapshot['display_name'] ?? 'Webmail'), ENT_QUOTES, 'UTF-8') ?></span></article>
    <article class="stat-card"><span class="stat-card__label">Phiên bản</span><strong class="stat-card__value"><?= htmlspecialchars((string) ($snapshot['version'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></strong><span class="stat-card__hint">Roundcube</span></article>
    <article class="stat-card"><span class="stat-card__label">Config File</span><strong class="stat-card__value"><?= !empty($snapshot['config_exists']) ? 'OK' : 'Missing' ?></strong><span class="stat-card__hint">config.inc.php</span></article>
</div>

<section class="panel panel-spaced">
    <div class="panel-header">
        <h2>Runtime Checks</h2>
        <p>Kiểm tra các cờ cấu hình quan trọng của Roundcube và plugin bridge.</p>
    </div>
    <div class="table-wrap">
        <table><tbody>
            <tr><th>Đã kích hoạt Webmail</th><td><?= $boolBadge(!empty($snapshot['enabled']), 'Enabled', 'Disabled') ?></td></tr>
            <tr><th>Tập tin cấu hình</th><td><code><?= htmlspecialchars((string) ($snapshot['config_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
            <tr><th>Auth log</th><td><code><?= htmlspecialchars((string) ($snapshot['auth_log_path'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
            <tr><th>Resolved root</th><td><code><?= htmlspecialchars((string) ($snapshot['webmail_root_realpath'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td></tr>
        </tbody></table>
    </div>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Fail2ban</h2>
        <p>Quan sát nhanh các jail liên quan tới webmail và tình trạng IP đang bị chặn.</p>
    </div>
    <?php if (!empty($fail2ban['error'])): ?><div class="flash error"><?= htmlspecialchars((string) $fail2ban['error'], ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($fail2banJails === []): ?>
        <?= $view->render('admin/components/empty_state.php', ['title' => 'Chưa có dữ liệu jail', 'description' => 'Fail2ban chưa trả về danh sách jail hoặc chưa có IP bị chặn.']) ?>
    <?php else: ?>
        <div class="checklist">
            <?php foreach ($fail2banJails as $jail => $ips): ?>
                <div class="checklist__item checklist__item--between">
                    <div><div class="checklist__label"><?= htmlspecialchars((string) $jail, ENT_QUOTES, 'UTF-8') ?></div><div class="checklist__text">Theo dõi số IP đang bị cấm ở jail này.</div></div>
                    <span class="badge <?= count($ips) > 0 ? 'failed' : 'ok' ?>"><?= count($ips) ?> banned</span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel panel-spaced">
    <div class="panel-header">
        <h2>Câu chuyện tiếp đổi mật khẩu</h2>
        <p>Roundcube đổi mật khẩu qua plugin password sử dụng driver mailpanel, gọi API của MailPanel.</p>
    </div>
    <div class="table-wrap">
        <table><tbody>
            <tr><th>Điểm cuối (Endpoint)</th><td><code>/api/webmail/password-change</code></td></tr>
            <tr><th>Method</th><td><code>POST</code></td></tr>
            <tr><th>Các trường bắt buộc</th><td><code>email</code>, <code>current_password</code>, <code>new_password</code></td></tr>
            <tr><th>Behavior</th><td>Áp dụng password policy, password history và audit log của panel.</td></tr>
            <tr><th>Roundcube Driver</th><td><code>mailpanel.php</code></td></tr>
        </tbody></table>
    </div>
    <div class="surface-muted"><pre><?= htmlspecialchars("{\n  \"email\": \"user@example.com\",\n  \"current_password\": \"********\",\n  \"new_password\": \"********\"\n}", ENT_QUOTES, 'UTF-8') ?></pre></div>
</section>

<section class="panel">
    <div class="panel-header">
        <h2>Recent Auth Log</h2>
        <p>Đuôi log auth gần nhất để rà nhanh các lần đăng nhập lỗi hoặc sai cấu hình client.</p>
    </div>
    <?php if ($authLogTail === []): ?>
        <?= $view->render('admin/components/empty_state.php', ['title' => 'Chưa có log auth', 'description' => 'Roundcube chưa ghi dòng auth thất bại nào gần đây.']) ?>
    <?php else: ?>
        <div class="log-console"><pre><?= htmlspecialchars(implode("\n", $authLogTail), ENT_QUOTES, 'UTF-8') ?></pre></div>
    <?php endif; ?>
</section>

