<?php

declare(strict_types=1);

$settings = is_array($portalSettings ?? null) ? $portalSettings : [];
$passwordReset = is_array($settings['password_reset'] ?? null) ? $settings['password_reset'] : [];
$mail = is_array($passwordReset['mail'] ?? null) ? $passwordReset['mail'] : [];
$smtp = is_array($mail['smtp'] ?? null) ? $mail['smtp'] : [];
$securityUser = is_array($securityUser ?? null) ? $securityUser : [];
$portalDomain = (string) ($settings['portal_domain'] ?? '');
$baseUrl = (string) ($settings['base_url'] ?? ($portalDomain !== '' ? 'https://' . $portalDomain : ''));
$ttlSeconds = max(300, min(3600, (int) ($passwordReset['ttl_seconds'] ?? 3600)));
$transport = (string) ($mail['transport'] ?? 'mail') === 'smtp' ? 'smtp' : 'mail';
$smtpEncryption = (string) ($smtp['encryption'] ?? 'starttls');
?>

<section class="panel panel-spaced security-panel" id="portal-settings-form">
    <div class="panel-header">
        <h2>Cấu hình Portal</h2>
    </div>

    <form method="post" action="/admin/portal-settings" class="security-form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-grid form-grid--align-end">
            <label class="field-span-6">Portal Domain
                <input name="portal_domain" type="text" placeholder="portal.example.com" value="<?= htmlspecialchars($portalDomain, ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label class="field-span-6">Base URL đang dùng
                <input type="text" value="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>" readonly>
            </label>

            <label class="field-span-4">Email gửi link reset
                <input name="password_reset_from_email" type="email" placeholder="no-reply@example.com" value="<?= htmlspecialchars((string) ($mail['from_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label class="field-span-4">Tên người gửi
                <input name="password_reset_from_name" type="text" placeholder="MailPanel" value="<?= htmlspecialchars((string) ($mail['from_name'] ?? 'MailPanel'), ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <label class="field-span-4">Tiêu đề email reset
                <input name="password_reset_subject" type="text" placeholder="Đặt lại mật khẩu MailPanel" value="<?= htmlspecialchars((string) ($mail['subject'] ?? 'Đặt lại mật khẩu MailPanel'), ENT_QUOTES, 'UTF-8') ?>" required>
            </label>

            <label class="field-span-4">Thời hạn link reset
                <select name="password_reset_ttl_seconds">
                    <?php foreach ([900 => '15 phút', 1800 => '30 phút', 3600 => '60 phút'] as $value => $label): ?>
                        <option value="<?= (int) $value ?>" <?= $ttlSeconds === (int) $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field-span-4">Phương thức gửi
                <select name="password_reset_transport">
                    <option value="mail" <?= $transport === 'mail' ? 'selected' : '' ?>>PHP mail / Exim local</option>
                    <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>>SMTP bên ngoài</option>
                </select>
            </label>
            <label class="field-span-4">SMTP host
                <input name="password_reset_smtp_host" type="text" placeholder="smtp.example.com" value="<?= htmlspecialchars((string) ($smtp['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="field-span-2">SMTP port
                <input name="password_reset_smtp_port" type="number" min="1" max="65535" value="<?= (int) ($smtp['port'] ?? 587) ?>">
            </label>
            <label class="field-span-2">Mã hóa
                <select name="password_reset_smtp_encryption">
                    <?php foreach (['starttls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'tls' => 'TLS', 'none' => 'Không mã hóa'] as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $smtpEncryption === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field-span-4">SMTP username
                <input name="password_reset_smtp_username" type="text" autocomplete="off" value="<?= htmlspecialchars((string) ($smtp['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </label>
            <label class="field-span-4">SMTP password
                <input name="password_reset_smtp_password" type="password" autocomplete="new-password" placeholder="<?= !empty($smtp['password_configured']) ? 'Để trống để giữ mật khẩu hiện tại' : 'Nhập mật khẩu SMTP' ?>">
                <?php if (!empty($smtp['password_configured'])): ?>
                    <span class="field-hint">Đã lưu mật khẩu SMTP mã hóa. Để trống nếu không đổi.</span>
                <?php endif; ?>
            </label>
            <label class="field-span-2">Timeout
                <input name="password_reset_smtp_timeout_seconds" type="number" min="3" max="30" value="<?= (int) ($smtp['timeout_seconds'] ?? 15) ?>">
            </label>
            <label class="field-span-2 checkbox-row">
                <input name="password_reset_smtp_clear_password" type="checkbox" value="1">
                <span>Xóa mật khẩu SMTP</span>
            </label>

            <label class="field-span-4">Mật khẩu hiện tại
                <input name="current_password" type="password" required autocomplete="current-password">
            </label>
            <?php if (!empty($securityUser['totp_enabled'])): ?>
                <label class="field-span-4">Mã OTP
                    <input class="otp-input" name="otp" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" required>
                </label>
            <?php endif; ?>

            <div class="field-span-12 form-actions">
                <button class="btn btn-primary" type="submit" data-confirm="Lưu cấu hình portal? Nếu Portal Domain thay đổi, hệ thống sẽ thử cấp SSL và reload cấu hình.">Lưu cấu hình</button>
            </div>
        </div>
    </form>
</section>

<section class="panel panel-spaced">
    <div class="panel-header">
        <h2>Ghi chú vận hành</h2>
    </div>
    <div class="compact-note-grid">
        <div class="surface-muted">
            <strong>Portal Domain</strong>
            <p>Domain này dùng để đăng nhập quản trị và tạo link quên mật khẩu. Hãy trỏ DNS A record về máy chủ trước khi lưu.</p>
        </div>
        <div class="surface-muted">
            <strong>Email reset mật khẩu</strong>
            <p>Nếu dùng email ngoài hệ thống, chọn SMTP bên ngoài và nhập đủ host, port, mã hóa, username, password. Mật khẩu SMTP được mã hóa bằng APP_KEY và không hiển thị lại.</p>
        </div>
    </div>
</section>
