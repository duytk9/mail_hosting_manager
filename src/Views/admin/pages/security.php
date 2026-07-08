<?php

declare(strict_types=1);

$securityLogin = (string) ($securityLogin ?? (($securityUser['linux_username'] ?? '') !== '' ? $securityUser['linux_username'] : ($securityUser['email'] ?? '')));
$isImpersonating = (bool) ($isImpersonating ?? false);
$impersonatorLogin = (string) ($impersonatorLogin ?? '');
$isTenantAdminView = (($identity['role'] ?? null) === 'tenant_admin');
?>
<section class="panel panel-spaced security-panel" id="password-policy">
        <div class="panel-header">
            <h2>Đổi mật khẩu</h2>
            <p>
                Tài khoản <strong><?= htmlspecialchars($securityLogin, ENT_QUOTES, 'UTF-8') ?></strong>.
                <?= $isTenantAdminView ? 'Mật khẩu này dùng để đăng nhập trang quản trị tenant.' : 'Nếu có Linux user đi kèm, mật khẩu tại đây sẽ đồng bộ luôn cho tài khoản SSH tương ứng.' ?>
            </p>
        </div>

        <?php if ($isImpersonating): ?>
            <div class="notice">
                Phiên hiện tại đang impersonate từ <strong><?= htmlspecialchars($impersonatorLogin !== '' ? $impersonatorLogin : 'Admin level', ENT_QUOTES, 'UTF-8') ?></strong>.
                Hãy thoát impersonation trước khi đổi mật khẩu hoặc cấu hình 2FA.
            </div>
        <?php else: ?>
            <form method="post" action="/admin/security" class="security-form">
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

<section class="panel panel-spaced security-panel" id="totp-setup">
        <div class="panel-header">
            <h2>2FA TOTP</h2>
            <?php if (!$isTenantAdminView): ?>
                <p>Bật xác thực hai lớp cho admin hoặc owner account để giảm rủi ro khi lộ mật khẩu.</p>
            <?php endif; ?>
        </div>

        <div class="totp-status-strip">
            <div class="totp-status-card">
                <span>Trạng thái</span>
                <strong class="<?= !empty($securityUser['totp_enabled']) ? 'text-success' : 'text-warning' ?>">
                    <?= !empty($securityUser['totp_enabled']) ? 'Đã bật' : 'Chưa bật' ?>
                </strong>
            </div>
            <div class="totp-status-card">
                <span>Xác nhận lúc</span>
                <strong><?= htmlspecialchars((string) ($securityUser['totp_confirmed_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></strong>
            </div>
        </div>

        <?php if (!empty($totpSetup) && is_array($totpSetup)): ?>
            <div class="totp-enrollment surface-muted">
                <div class="totp-enrollment__qr-frame">
                    <?php if (!empty($totpSetup['qr_data_uri'])): ?>
                        <img class="totp-enrollment__qr" src="<?= htmlspecialchars((string) $totpSetup['qr_data_uri'], ENT_QUOTES, 'UTF-8') ?>" alt="QR TOTP để quét bằng ứng dụng xác thực">
                    <?php endif; ?>
                </div>
                <div class="totp-enrollment__copy">
                    <div class="totp-enrollment__meta">
                        <strong>Quét QR bằng ứng dụng xác thực</strong>
                        <span>Dùng Google Authenticator, 1Password, Bitwarden hoặc ứng dụng TOTP tương thích.</span>
                    </div>
                    <div class="totp-secret-grid">
                        <div class="totp-secret-row">
                            <span>Tên OTP</span>
                            <code><?= htmlspecialchars((string) ($totpSetup['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                        </div>
                        <div class="totp-secret-row">
                            <span>Secret nhập tay</span>
                            <code><?= htmlspecialchars((string) ($totpSetup['secret'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                        </div>
                    </div>
                    <details>
                        <summary>Xem URI otpauth</summary>
                        <code><?= htmlspecialchars((string) ($totpSetup['otpauth_uri'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                    </details>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isImpersonating): ?>
            <div class="notice">Cấu hình 2FA cũng bị khóa trong lúc đang impersonate.</div>
        <?php else: ?>
        <?php if (empty($securityUser['totp_enabled'])): ?>
            <div class="totp-action-grid">
                <form method="post" action="/admin/security" class="security-step-card">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_intent" value="totp_start">
                    <div class="security-step-card__header">
                        <strong>1. Tạo mã bí mật</strong>
                        <span>Nhập mật khẩu hiện tại để tạo QR và secret mới.</span>
                    </div>
                    <div class="form-grid form-grid--align-end">
                        <label class="field-span-12">Mật khẩu hiện tại
                            <input name="current_password" type="password" required autocomplete="current-password">
                        </label>
                        <div class="field-span-12">
                            <button class="btn btn-secondary btn-block" type="submit">Tạo mã bí mật mới</button>
                        </div>
                    </div>
                </form>

                <form method="post" action="/admin/security" class="security-step-card">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="_intent" value="totp_confirm">
                    <div class="security-step-card__header">
                        <strong>2. Xác nhận OTP</strong>
                        <span>Sau khi quét QR, nhập mã 6 số để bật 2FA.</span>
                    </div>
                    <div class="form-grid form-grid--align-end">
                        <label class="field-span-6">Mật khẩu hiện tại
                            <input name="current_password" type="password" required autocomplete="current-password">
                        </label>
                        <label class="field-span-6">Mã OTP từ ứng dụng
                            <input class="otp-input" name="otp" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" required>
                        </label>
                        <div class="field-span-12">
                            <button class="btn btn-primary btn-block" type="submit">Xác nhận bật 2FA</button>
                        </div>
                    </div>
                </form>
            </div>
        <?php else: ?>
            <form method="post" action="/admin/security" class="security-step-card security-step-card--danger">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="totp_disable">
                <div class="security-step-card__header">
                    <strong>Tắt 2FA</strong>
                    <span>Cần mật khẩu hiện tại và mã OTP để xác nhận.</span>
                </div>
                <div class="form-grid form-grid--align-end">
                    <label class="field-span-5">Mật khẩu hiện tại
                        <input name="current_password" type="password" required autocomplete="current-password">
                    </label>
                    <label class="field-span-4">Mã OTP xác nhận
                        <input class="otp-input" name="otp" placeholder="123456" inputmode="numeric" autocomplete="one-time-code" required>
                    </label>
                    <div class="field-span-3">
                        <button class="btn btn-secondary btn-block" type="submit">Tắt 2FA</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <?php endif; ?>
</section>
