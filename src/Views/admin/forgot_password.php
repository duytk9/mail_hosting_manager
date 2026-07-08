<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Quên mật khẩu', ENT_QUOTES, 'UTF-8') ?></title>
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

            <form class="auth-card auth-card--login" method="post" action="/admin/forgot-password">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div>
                    <h2>Quên mật khẩu</h2>
                    <p>Nhập username hoặc email quản trị để nhận liên kết đặt lại mật khẩu.</p>
                </div>

                <?php if (!empty($message)): ?>
                    <div class="flash success"><?= htmlspecialchars((string) $message, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert auth-alert--compact"><?= htmlspecialchars((string) $error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <label class="field field-span-12">
                    Username hoặc email
                    <input id="login" name="login" type="text" required autocomplete="username" value="<?= htmlspecialchars((string) ($oldLogin ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Nhập username hoặc email">
                </label>

                <button type="submit" class="btn btn-primary auth-submit">Gửi liên kết đặt lại</button>
                <a class="action-link secondary auth-link" href="/admin/login">Quay lại đăng nhập</a>
            </form>
        </section>
    </div>
</div>
</body>
</html>
