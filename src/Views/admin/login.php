<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Đăng nhập', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/admin.css?v=20260702-ui32">
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

            <form class="auth-card auth-card--login" method="post" action="/admin/login">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                
                <div>
                    <h2>Đăng nhập</h2>
                    <p>Hệ thống quản trị Mail Server</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert auth-alert--compact"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <label class="field field-span-12">
                    Tên đăng nhập
                    <input id="login" name="login" type="text" required autocomplete="username" value="<?= htmlspecialchars($oldLogin ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Nhập username...">
                </label>

                <label class="field field-span-12">
                    Mật khẩu
                    <input id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••••••">
                </label>

                <label class="field field-span-12">
                    Mã xác thực (2FA)
                    <input id="otp" name="otp" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="Bỏ trống nếu chưa bật">
                </label>

                <button type="submit" class="btn btn-primary auth-submit">Đăng nhập</button>
            </form>
        </section>
    </div>
</div>
</body>
</html>
