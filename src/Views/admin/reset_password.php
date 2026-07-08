<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Đặt lại mật khẩu', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/admin.css?v=20260708-form-polish">
</head>
<body>
<div class="auth-page">
    <div class="auth-shell auth-shell--login">
        <section class="auth-card-wrap auth-card-wrap--login">
            <div class="brand-center">
                <div class="brand-mark">MP</div>
                <div class="brand-copy">
                    <strong>MailPanel</strong>
                    <span>System</span>
                </div>
            </div>

            <form class="auth-card auth-card--login" method="post" action="/admin/reset-password">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars((string) ($token ?? ''), ENT_QUOTES, 'UTF-8') ?>">

                <div>
                    <h2>Đặt lại mật khẩu</h2>
                    <p>Liên kết chỉ dùng được một lần và hết hạn sau 1 giờ.</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="flash success"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
                    <a class="btn btn-primary auth-submit" href="/admin/login">Đăng nhập</a>
                <?php elseif (empty($tokenUsable)): ?>
                    <div class="alert auth-alert--compact">Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn.</div>
                    <a class="action-link secondary auth-link" href="/admin/forgot-password">Yêu cầu liên kết mới</a>
                <?php else: ?>
                    <?php if (!empty($error)): ?>
                        <div class="alert auth-alert--compact"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <label class="field field-span-12">
                        Mật khẩu mới
                        <input id="new_password" name="new_password" type="password" required autocomplete="new-password" placeholder="StrongPass123!">
                    </label>

                    <label class="field field-span-12">
                        Nhập lại mật khẩu mới
                        <input id="confirm_password" name="confirm_password" type="password" required autocomplete="new-password" placeholder="Nhập lại mật khẩu">
                    </label>

                    <button type="submit" class="btn btn-primary auth-submit">Đặt lại mật khẩu</button>
                <?php endif; ?>

                <a class="action-link secondary auth-link" href="/admin/login">Quay lại đăng nhập</a>
            </form>
        </section>
    </div>
</div>
</body>
</html>
