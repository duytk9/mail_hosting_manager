<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? 'Dashboard', ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, Arial, sans-serif; background: #f8fafc; color: #0f172a; line-height: 1.5; }
        .layout { display: grid; grid-template-columns: 260px 1fr; min-height: 100vh; }
        .sidebar { background: #012456; color: #e2e8f0; padding: 28px 20px; border-right: none; }
        .brand { font-size: 20px; font-weight: 700; margin-bottom: 28px; color: #ffffff; }
        .menu { display: grid; gap: 4px; }
        .menu-item { padding: 10px 12px; border-radius: 6px; color: #cbd5e1; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.15s; }
        .menu-item:hover { background: rgba(255, 255, 255, 0.1); color: #ffffff; }
        .menu-item.active { background: rgba(255, 255, 255, 0.15); color: #ffffff; font-weight: 600; }
        .content { padding: 32px 40px; }
        .topbar { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; margin-bottom: 32px; }
        .welcome h1 { margin: 0; font-size: 24px; font-weight: 700; letter-spacing: -0.02em; }
        .welcome p { margin: 6px 0 0; color: #64748b; font-size: 14px; }
        .logout-form button { border: 1px solid #e2e8f0; background: #ffffff; color: #0f172a; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 500; transition: all 0.15s; box-shadow: 0 1px 2px 0 rgba(0,0,0,0.05); }
        .logout-form button:hover { background: #f8fafc; }
        .cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .card { background: #ffffff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .card h2, .panel h2 { margin: 0 0 8px; font-size: 13px; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
        .metric { font-size: 28px; font-weight: 600; letter-spacing: -0.02em; color: #0f172a; }
        .muted { color: #64748b; font-size: 12px; margin-top: 8px; }
        .panels { display: grid; grid-template-columns: 1.2fr .8fr; gap: 16px; }
        .panel { background: #ffffff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 3px 0 rgba(0,0,0,0.1), 0 1px 2px -1px rgba(0,0,0,0.1); border: 1px solid #e2e8f0; }
        .panel h2 { color: #0f172a; margin: 0 0 16px; font-size: 16px; font-weight: 600; text-transform: none; letter-spacing: normal; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; font-size: 13px; }
        th, td { text-align: left; padding: 12px 16px; border-bottom: 1px solid #e2e8f0; }
        th { color: #64748b; font-weight: 500; background: #f8fafc; font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; }
        tr:hover td { background: #f8fafc; }
        .badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; border: 1px solid transparent; }
        .badge.applied { background: #dcfce7; color: #16a34a; border-color: #bbf7d0; }
        .badge.generated, .badge.validated { background: #e0f2fe; color: #0284c7; border-color: #bae6fd; }
        .badge.failed, .badge.rolled_back { background: #fee2e2; color: #dc2626; border-color: #fecaca; }
        .quick-list { display: grid; gap: 12px; }
        .quick-item { padding: 16px; border-radius: 8px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .quick-item strong { color: #0f172a; font-size: 14px; }
        .quick-item .muted { margin-top: 4px; }

    
        @media (max-width: 960px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { 
                position: static; 
                height: auto; 
                border-right: none; 
                border-bottom: 1px solid rgba(255,255,255,0.1); 
                padding: 16px; 
                display: flex; 
                flex-direction: column;
                gap: 16px; 
            }
            .sidebar .brand { margin-bottom: 0; }
            .sidebar-status { display: none; }
            .sidebar .menu { display: flex; flex-wrap: wrap; flex-direction: row; gap: 8px; }
            .sidebar-group__title { display: none; }
            
            .panels { grid-template-columns: 1fr; }
            .grid-2 { grid-template-columns: 1fr; }
            .main { padding: 24px 20px; }
        }
        
        @media (max-width: 768px) {
            .topbar { flex-direction: column; align-items: flex-start; gap: 16px; }
            .topbar-right { width: 100%; display: flex; flex-direction: column; align-items: stretch; }
            .topbar-right .btn, .topbar-right .action-link { width: 100%; }
            .main { padding: 16px; }
            .filter-toolbar__header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .filter-toolbar__meta { width: 100%; justify-content: space-between; }
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <div class="brand">MailPanel</div>
        <div class="menu">
            <div class="menu-item active">Dashboard</div>
            <div class="menu-item">Tenants</div>
            <div class="menu-item">Domains</div>
            <div class="menu-item">Mailboxes</div>
            <div class="menu-item">Config Versions</div>
            <div class="menu-item">Services</div>
        </div>
    </aside>

    <main class="content">
        <div class="topbar">
            <div class="welcome">
                <h1>Xin chào, <?= htmlspecialchars($identity['name'] ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></h1>
                <p>Đăng nhập với quyền <?= htmlspecialchars($identity['role'] ?? 'super_admin', ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($identity['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <form class="logout-form" method="post" action="/admin/logout">
                <button type="submit">Đăng xuất</button>
            </form>
        </div>

        <section class="cards">
            <div class="card"><h2>Tenants</h2><div class="metric"><?= (int) ($stats['tenants'] ?? 0) ?></div><div class="muted">Tổng tenant đang quản lý</div></div>
            <div class="card"><h2>Domains</h2><div class="metric"><?= (int) ($stats['domains'] ?? 0) ?></div><div class="muted">Domain đã khai báo</div></div>
            <div class="card"><h2>Mailboxes</h2><div class="metric"><?= (int) ($stats['mailboxes'] ?? 0) ?></div><div class="muted">Hộp thư đang tồn tại</div></div>
            <div class="card"><h2>Aliases</h2><div class="metric"><?= (int) ($stats['aliases'] ?? 0) ?></div><div class="muted">Alias nội bộ</div></div>
            <div class="card"><h2>Forwards</h2><div class="metric"><?= (int) ($stats['forwards'] ?? 0) ?></div><div class="muted">Forward rule</div></div>
            <div class="card"><h2>Quota Used</h2><div class="metric"><?= (int) ($stats['quota_used_mb'] ?? 0) ?> MB</div><div class="muted">Dung lượng đã ghi nhận</div></div>
        </section>

        <section class="panels">
            <div class="panel">
                <h2>Config Versions gần đây</h2>
                <table>
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Service</th>
                        <th>Version</th>
                        <th>Trạng thái</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($configVersions as $item): ?>
                        <tr>
                            <td>#<?= (int) $item['id'] ?></td>
                            <td><?= htmlspecialchars((string) $item['service'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars((string) $item['version'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><span class="badge <?= htmlspecialchars((string) $item['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $item['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Gợi ý thao tác tiếp theo</h2>
                <div class="quick-list">
                    <div class="quick-item"><strong>Mail flow</strong><div class="muted">SMTP port `25` và IMAP/POP3 đã lắng nghe công khai.</div></div>
                    <div class="quick-item"><strong>Submission</strong><div class="muted">Nên bật tiếp `587` và `465` để client gửi mail chuẩn hơn.</div></div>
                    <div class="quick-item"><strong>Bảo mật</strong><div class="muted">Nên thêm HTTPS + reverse proxy hardening trước khi dùng production.</div></div>
                </div>
            </div>
        </section>
    </main>
</div>
</body>
</html>



