<?php
$layoutIdentity = is_array($identity ?? null) ? $identity : [];
$layoutRole = (string) ($layoutIdentity['role'] ?? 'guest');
$layoutRoleClass = 'role-' . (preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($layoutRole)) ?: 'guest');
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'MailPanel Admin', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/admin.css?v=20260708-form-polish">
</head>
<body class="<?= htmlspecialchars($layoutRoleClass, ENT_QUOTES, 'UTF-8') ?>">
<?php
$identity = $layoutIdentity;
$role = $layoutRole;
$identityLogin = (string) (($identity['linux_username'] ?? '') !== '' ? $identity['linux_username'] : ($identity['email'] ?? ''));
$identityEmail = (string) ($identity['email'] ?? '');
$impersonation = is_array($identity['impersonated_by'] ?? null) ? $identity['impersonated_by'] : null;
$totpGraceRemaining = isset($identity['totp_grace_remaining']) ? max(0, (int) $identity['totp_grace_remaining']) : null;
$showTotpGraceWarning = empty($identity['totp_enabled'])
    && $totpGraceRemaining !== null
    && in_array($role, ['super_admin', 'tenant_admin', 'domain_admin', 'support_readonly'], true);
$roleLabel = match ($role) {
    'super_admin' => 'Admin level',
    'tenant_admin' => 'User level',
    'domain_admin' => 'Domain level',
    'support_readonly' => 'Read-only ops',
    'mailbox_user' => 'Mailbox user',
    default => 'Guest',
};
$roleHint = match ($role) {
    'super_admin' => 'Toàn hệ thống',
    'tenant_admin' => 'Theo user level',
    'domain_admin' => 'Theo domain',
    'support_readonly' => 'Chỉ đọc',
    'mailbox_user' => 'Hộp thư',
    default => 'Chưa xác thực',
};

$page = is_array($page ?? null) ? $page : [];
$quickActions = is_array($page['quick_actions'] ?? null) ? $page['quick_actions'] : [];
$canAccess = is_callable($canAccess ?? null) ? $canAccess : static fn (string|array $permissions, bool $matchAny = false): bool => true;

$menuGroups = [
    'Hệ thống' => [
        [
            'label' => 'Tổng quan',
            'href' => '/admin/dashboard',
            'key' => 'dashboard',
            'permission' => 'dashboard.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><rect x="3" y="3" width="7" height="9"></rect><rect x="14" y="3" width="7" height="5"></rect><rect x="14" y="12" width="7" height="9"></rect><rect x="3" y="16" width="7" height="5"></rect></svg>'
        ],
        [
            'label' => 'Tài khoản khách',
            'href' => '/admin/tenants',
            'key' => 'tenants',
            'permission' => 'tenants.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>'
        ],
        [
            'label' => 'Gói dịch vụ',
            'href' => '/admin/packages',
            'key' => 'packages',
            'permission' => 'packages.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"></line><polygon points="12 22.08 12 12 3 6.92 3 17.08 12 22.08"></polygon><polygon points="12 12 21 6.92 21 17.08 12 22.08"></polygon><polygon points="12 2 3 6.92 12 12 21 6.92 12 2"></polygon><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>'
        ],
        [
            'label' => 'Quản trị viên',
            'href' => '/admin/super-admins',
            'key' => 'super_admins',
            'permission' => 'super_admins.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="8" cy="7" r="4"></circle><polyline points="17 11 19 13 23 9"></polyline></svg>'
        ],
    ],
    'Tên miền & Mail' => [
        [
            'label' => 'Quản lý tên miền',
            'href' => '/admin/domains',
            'key' => 'domains',
            'permission' => 'domains.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg>'
        ],
        [
            'label' => 'Kiểm tra DNS / TLS',
            'href' => '/admin/dns-checks',
            'key' => 'dns_checks',
            'permission' => 'dns_checks.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>'
        ],
        [
            'label' => 'Hộp thư (Mailbox)',
            'href' => '/admin/mailboxes',
            'key' => 'mailboxes',
            'permission' => 'mailboxes.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>'
        ],
        [
            'label' => 'Định tuyến Mail',
            'href' => '/admin/routing',
            'key' => 'routing',
            'permission' => 'routing.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><circle cx="18" cy="18" r="3"></circle><circle cx="6" cy="6" r="3"></circle><circle cx="6" cy="18" r="3"></circle><path d="M18 15V9a4 4 0 0 0-4-4H9"></path><line x1="6" y1="9" x2="6" y2="15"></line></svg>'
        ],
        [
            'label' => 'Giao diện Webmail',
            'href' => '/webmail',
            'external' => true,
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>'
        ],
    ],
    'Vận hành & Bảo mật' => [
        [
            'label' => 'Lịch sử cấu hình',
            'href' => '/admin/config-versions',
            'key' => 'config_versions',
            'permission' => 'config_versions.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><circle cx="12" cy="12" r="4"></circle><line x1="1.05" y1="12" x2="7.95" y2="12"></line><line x1="16.05" y1="12" x2="22.95" y2="12"></line></svg>'
        ],
        [
            'label' => 'Hàng đợi Mail',
            'href' => '/admin/queue',
            'key' => 'queue',
            'permission' => 'super_admins.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>'
        ],
        [
            'label' => 'Nhật ký hệ thống',
            'href' => '/admin/logs',
            'key' => 'logs',
            'permission' => 'super_admins.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>'
        ],
        [
            'label' => 'Bảo mật tài khoản',
            'href' => '/admin/security',
            'key' => 'security',
            'permission' => 'security.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>'
        ],
        [
            'label' => 'Cấu hình Portal',
            'href' => '/admin/portal-settings',
            'key' => 'portal_settings',
            'permission' => 'system_settings.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><rect x="3" y="4" width="18" height="14" rx="2"></rect><path d="M8 21h8"></path><path d="M12 18v3"></path><path d="M7 8h10"></path><path d="M7 12h6"></path></svg>'
        ],
        [
            'label' => 'Trạng thái Webmail',
            'href' => '/admin/webmail',
            'key' => 'webmail_health',
            'permission' => 'super_admins.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>'
        ],
        [
            'label' => 'Fail2ban (Chặn IP)',
            'href' => '/admin/fail2ban',
            'key' => 'fail2ban',
            'permission' => 'super_admins.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><circle cx="12" cy="12" r="10"></circle><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"></line></svg>'
        ],
        [
            'label' => 'Lọc SPAM cá nhân',
            'href' => '/admin/spam-policies',
            'key' => 'spam_policies',
            'permission' => 'spam_policies.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><path d="M21.2 8.4c.5.38.8.97.8 1.6v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V10a2 2 0 0 1 .8-1.6l8-6a2 2 0 0 1 2.4 0l8 6z"></path><line x1="22" y1="10" x2="12" y2="17"></line><line x1="12" y1="17" x2="2" y2="10"></line></svg>'
        ],
        [
            'label' => 'Rspamd (Lọc SPAM)',
            'href' => '/admin/rspamd',
            'key' => 'rspamd',
            'permission' => 'super_admins.view',
            'icon' => '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="menu-item__icon"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>'
        ],
    ],
];
?>
<div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-backdrop data-sidebar-close></div>

    <aside class="sidebar" id="admin-sidebar" data-sidebar>
        <div class="sidebar__header">
            <div class="sidebar__mobile">
                <span class="brand__title">Điều hướng</span>
                <button type="button" class="icon-btn" data-sidebar-close aria-label="Đóng menu">×</button>
            </div>

            <div class="brand">
                <div class="brand-mark">MP</div>
                <div class="brand-copy">
                    <div class="brand__title">MailPanel</div>
                    <div class="brand__hint">Quản trị mail</div>
                </div>
            </div>

            <div class="sidebar-status">
                <span class="sidebar-status__dot"></span>
                Phiên đang hoạt động
            </div>
        </div>

        <div class="sidebar__nav">
            <?php foreach ($menuGroups as $groupTitle => $items): ?>
                <?php
                $visibleItems = array_values(array_filter($items, static function (array $item) use ($role, $canAccess): bool {
                    if (isset($item['roles']) && !in_array($role, $item['roles'], true)) {
                        return false;
                    }

                    if (isset($item['permission']) && !$canAccess((string) $item['permission'])) {
                        return false;
                    }

                    if (isset($item['permissions']) && !$canAccess(
                        (array) $item['permissions'],
                        (($item['permissions_mode'] ?? 'all') === 'any')
                    )) {
                        return false;
                    }

                    return true;
                }));
                ?>
                <?php if ($visibleItems === []): ?>
                    <?php continue; ?>
                <?php endif; ?>
                <section class="sidebar-group">
                    <h2 class="sidebar-group__title"><?= htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8') ?></h2>
                    <div class="menu">
                        <?php foreach ($visibleItems as $item): ?>
                            <?php if (!empty($item['coming_soon'])): ?>
                                <div class="menu-placeholder">
                                    <span><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="chip">Sắp có</span>
                                </div>
                            <?php else: ?>
                                <a
                                    class="menu-item <?= ($active ?? '') === ($item['key'] ?? '') ? 'active' : '' ?>"
                                    href="<?= htmlspecialchars((string) ($item['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
                                    <?= !empty($item['external']) ? 'target="_blank" rel="noreferrer"' : '' ?>
                                >
                                    <?= $item['icon'] ?? '' ?>
                                    <span><?= htmlspecialchars((string) $item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!empty($item['external'])): ?><span class="chip">Link</span><?php endif; ?>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>

        <div class="sidebar__footer">
            <form class="logout-form" method="post" action="/admin/logout">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="logout-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="logout-btn__icon"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>Đăng xuất</span>
                </button>
            </form>
        </div>
    </aside>

    <main class="main">
        <div class="main-shell">
            <div class="mobile-bar">
                <button type="button" class="icon-btn" data-sidebar-open aria-label="Mở menu">☰</button>
                <a class="mobile-brand" href="/admin/dashboard">
                    <span class="brand-mark">MP</span>
                    <span>MailPanel</span>
                </a>
                <form class="logout-form" method="post" action="/admin/logout">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="btn btn-secondary btn-sm">Đăng xuất</button>
                </form>
            </div>

            <section class="shell-header">
                <div class="topbar">
                    <div class="topbar-left">
                        <div class="topbar-meta">
                            <span class="topbar-tag">Quản trị</span>
                            <span class="topbar-tag muted"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="topbar-tag muted"><?= htmlspecialchars($roleHint, ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($impersonation !== null): ?>
                                <span class="topbar-tag">Impersonating</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($page['breadcrumbs'])): ?>
                            <div class="breadcrumbs">
                                <?php foreach (($page['breadcrumbs'] ?? []) as $index => $crumb): ?>
                                    <?php if ($index > 0): ?><span class="sep">/</span><?php endif; ?>
                                    <?php if (!empty($crumb['href'])): ?>
                                        <a href="<?= htmlspecialchars((string) $crumb['href'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) $crumb['label'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <span><?= htmlspecialchars((string) $crumb['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <h1 class="page-title"><?= htmlspecialchars((string) ($page['title'] ?? $title ?? 'MailPanel'), ENT_QUOTES, 'UTF-8') ?></h1>

                        <?php if (!empty($page['description'])): ?>
                            <p class="page-description"><?= htmlspecialchars((string) $page['description'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>

                    <div class="topbar-right topbar-right--actions">
                        
                        <?php if ($impersonation !== null): ?>
                            <div class="impersonation-pill">
                                <div class="impersonation-pill__identity">
                                    <svg class="impersonation-pill__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                    <span>Đang mượn quyền:</span> 
                                    <strong><?= htmlspecialchars((string) ($impersonation['login'] ?? 'Admin level'), ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                                <form method="post" action="/admin/impersonate/stop" class="impersonation-pill__form">
                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="impersonation-exit" title="Thoát quyền">
                                        Thoát
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($identity['force_password_change'])): ?>
                            <div class="force-password-pill">
                                Đổi mật khẩu để tiếp tục
                            </div>
                        <?php endif; ?>

                        <div class="identity-card">
                            <div class="identity-card__name"><?= htmlspecialchars((string) ($identity['name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="content">
                <?php if (!empty($flashSuccess)): ?><div class="flash success"><?= htmlspecialchars((string) $flashSuccess, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if (!empty($flashError)): ?><div class="flash error"><?= htmlspecialchars((string) $flashError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($showTotpGraceWarning): ?>
                    <div class="flash warning">
                        Tài khoản quản trị chưa bật 2FA. Bạn còn <?= (int) $totpGraceRemaining ?> lần đăng nhập không cần OTP trước khi tài khoản bị khóa bảo mật.
                        <a href="/admin/security#totp-setup">Bật 2FA ngay</a>.
                    </div>
                <?php endif; ?>
                <?php if ($quickActions !== []): ?>
                    <div class="page-action-seed" data-page-action-seed>
                        <?php foreach ($quickActions as $action): ?>
                            <a
                                class="action-link <?= htmlspecialchars((string) ($action['variant'] ?? 'secondary'), ENT_QUOTES, 'UTF-8') ?>"
                                href="<?= htmlspecialchars((string) ($action['href'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>"
                            >
                                <?= htmlspecialchars((string) ($action['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?= $content ?>
            </div>
        </div>
    </main>
</div>

<dialog class="confirm-dialog" data-confirm-dialog>
    <form method="dialog" class="confirm-sheet">
        <div class="confirm-sheet__header">
            <p class="confirm-sheet__eyebrow">Xác nhận</p>
            <h2 class="confirm-sheet__title" data-confirm-title>Tiếp tục thay đổi này?</h2>
            <p class="confirm-sheet__description" data-confirm-description>Thao tác sẽ chạy ngay sau khi xác nhận.</p>
        </div>
        <div class="confirm-actions">
            <button type="button" class="btn btn-secondary" value="cancel" data-confirm-cancel>Hủy</button>
            <button type="button" class="btn btn-danger" value="confirm" data-confirm-accept>Xác nhận</button>
        </div>
    </form>
</dialog>

<script src="/assets/admin.js?v=20260707-sensitivecompact"></script>
</body>
</html>
