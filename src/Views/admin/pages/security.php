<?php

declare(strict_types=1);

$securityLogin = (string) ($securityLogin ?? (($securityUser['linux_username'] ?? '') !== '' ? $securityUser['linux_username'] : ($securityUser['email'] ?? '')));
$isImpersonating = (bool) ($isImpersonating ?? false);
$impersonatorLogin = (string) ($impersonatorLogin ?? '');
?>
<section class="panel panel-spaced" id="password-policy">
        <div class="panel-header">
            <h2>Đổi mật khẩu</h2>
            <p>Bạn đang thao tác trên tài khoản <strong><?= htmlspecialchars($securityLogin, ENT_QUOTES, 'UTF-8') ?></strong>. Nếu có Linux user đi kèm, mật khẩu tại đây sẽ đồng bộ luôn cho tài khoản SSH tương ứng.</p>
        </div>

        <?php if ($isImpersonating): ?>
            <div class="notice">
                Phiên hiện tại đang impersonate từ <strong><?= htmlspecialchars($impersonatorLogin !== '' ? $impersonatorLogin : 'Admin level', ENT_QUOTES, 'UTF-8') ?></strong>.
                Hãy thoát impersonation trước khi đổi mật khẩu hoặc cấu hình 2FA.
            </div>
        <?php else: ?>
            <form method="post" action="/admin/security">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="change_password">
                <div class="form-grid form-grid--align-end">
                    <label class="field-span-5">Mật khẩu hiện tại
                        <input name="current_password" type="password" required autocomplete="current-password">
                    </label>
                    <label class="field-span-5">Mật khẩu mới
                        <input name="new_password" type="password" required autocomplete="new-password" placeholder="StrongPass123!">
                    </label>
                    <div class="field-span-2">
                        <button class="btn btn-primary btn-block" type="submit">Đổi mật khẩu</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
</section>

<section class="panel panel-spaced" id="totp-setup">
        <div class="panel-header">
            <h2>2FA TOTP</h2>
            <p>Bật xác thực hai lớp cho admin hoặc owner account để giảm rủi ro khi lộ mật khẩu.</p>
        </div>

        <div class="table-wrap">
            <table>
                <tbody>
                <tr><th>Trạng thái</th><td><?= !empty($securityUser['totp_enabled']) ? 'Đã bật' : 'Chưa bật' ?></td></tr>
                <tr><th>Xác nhận lúc</th><td><?= htmlspecialchars((string) ($securityUser['totp_confirmed_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td></tr>
                </tbody>
            </table>
        </div>

        <?php if (!empty($totpSetup) && is_array($totpSetup)): ?>
            <div class="surface-muted">
                Secret TOTP: <code><?= htmlspecialchars((string) ($totpSetup['secret'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code><br>
                URI: <code><?= htmlspecialchars((string) ($totpSetup['otpauth_uri'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
            </div>
        <?php endif; ?>

        <?php if ($isImpersonating): ?>
            <div class="notice">Cấu hình 2FA cũng bị khóa trong lúc đang impersonate.</div>
        <?php else: ?>
        <?php if (empty($securityUser['totp_enabled'])): ?>
            <div class="form-actions form-actions--compact">
                <form method="post" action="/admin/security">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_intent" value="totp_start">
                    <div class="form-grid form-grid--align-end">
                        <label class="field-span-8">Mật khẩu hiện tại
                            <input name="current_password" type="password" required autocomplete="current-password">
                        </label>
                        <div class="field-span-4">
                            <button class="btn btn-secondary btn-block" type="submit">Tạo mã bí mật mới</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <form method="post" action="/admin/security" class="mt-16">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="totp_confirm">
                <div class="form-grid form-grid--align-end">
                    <label class="field-span-4">Mật khẩu hiện tại
                        <input name="current_password" type="password" required autocomplete="current-password">
                    </label>
                    <label class="field-span-4">Mã OTP từ ứng dụng
                        <input name="otp" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" required>
                    </label>
                    <div class="field-span-4">
                        <button class="btn btn-primary btn-block" type="submit">Xác nhận bật 2FA</button>
                    </div>
                </div>
            </form>
        <?php else: ?>
            <form method="post" action="/admin/security" class="mt-16">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="totp_disable">
                <div class="form-grid form-grid--align-end">
                    <label class="field-span-4">Mật khẩu hiện tại
                        <input name="current_password" type="password" required autocomplete="current-password">
                    </label>
                    <label class="field-span-4">Mã OTP xác nhận
                        <input name="otp" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" required>
                    </label>
                    <div class="field-span-4">
                        <button class="btn btn-secondary btn-block" type="submit">Tắt 2FA</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <?php endif; ?>
</section>

<section class="panel panel-spaced" id="portal-domain-config">
    <div class="panel-header">
        <h2>Cấu hình Portal Domain</h2>
        <p>Thay đổi domain đăng nhập quản trị của hệ thống. 
           <strong style="color:var(--text-danger)">Lưu ý: Bạn PHẢI trỏ A record của domain này về IP của máy chủ trước khi cập nhật.</strong> 
           Nếu DNS chưa sẵn sàng, quá trình cấp phát chứng chỉ SSL sẽ thất bại.</p>
    </div>

    <form method="post" action="/admin/security">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_intent" value="update_portal_domain">
        <div class="form-grid form-grid--align-end">
            <label class="field-span-8">Portal Domain
                <input name="portal_domain" type="text" placeholder="portal.example.com" value="<?= htmlspecialchars($portalConfig['portal_domain'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
            </label>
            <div class="field-span-4">
                <button class="btn btn-primary btn-block" type="submit" onclick="return confirm('Bạn đã chắc chắn trỏ DNS cho domain này về máy chủ chưa?')">Cập nhật Portal Domain</button>
            </div>
        </div>
    </form>
</section>
