<?php

declare(strict_types=1);

$report = is_array($report ?? null) ? $report : null;
$selectedDomain = is_array($selectedDomain ?? null) ? $selectedDomain : null;
$certificateProfiles = is_array($certificateProfiles ?? null) ? $certificateProfiles : null;
$acmeDefaultEmail = (string) ($acmeDefaultEmail ?? '');
$acmeDefaultProfile = (string) ($acmeDefaultProfile ?? 'mail_only');
$summary = $report['summary'] ?? ['total' => 0, 'ok' => 0, 'failed' => 0, 'skipped' => 0];
?>
<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Kiểm tra DNS / TLS',
    'action' => '/admin/dns-checks',
    'summary' => 'Chọn tên miền để kiểm tra bản ghi MX, SPF, DKIM, DMARC và TLS.',
    'resultCount' => count($domains),
    'resultLabel' => 'domain',
    'resetHref' => '/admin/dns-checks',
    'fields' => [
        [
            'label' => 'Tên miền',
            'name' => 'domain_id',
            'type' => 'select',
            'value' => (string) ($selectedDomain['id'] ?? ($domains[0]['id'] ?? '')),
            'options' => array_map(
                static fn (array $domain): array => [
                    'value' => (string) ($domain['id'] ?? ''),
                    'label' => (string) ($domain['domain'] ?? ''),
                ],
                $domains
            ),
        ],
    ],
]) ?>

<?php if ($domains === []): ?>
    <?= $view->render('admin/components/empty_state.php', [
        'title' => 'Chưa có domain để kiểm tra',
        'description' => 'Tạo domain trước, sau đó quay lại đây để kiểm tra DNS và TLS.',
    ]) ?>
<?php endif; ?>

<?php if ($report !== null): ?>
    <section class="metrics-grid">
        <?= $view->render('admin/components/metric_card.php', ['label' => 'Lượt kiểm tra', 'value' => (int) $summary['total'], 'hint' => 'Tổng số check']) ?>
        <?= $view->render('admin/components/metric_card.php', ['label' => 'OK', 'value' => (int) $summary['ok'], 'hint' => 'Đúng kỳ vọng']) ?>
        <?= $view->render('admin/components/metric_card.php', ['label' => 'Failed', 'value' => (int) $summary['failed'], 'hint' => 'Cần sửa']) ?>
        <?= $view->render('admin/components/metric_card.php', ['label' => 'Skipped', 'value' => (int) $summary['skipped'], 'hint' => 'Không áp dụng']) ?>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>Kết quả cho <?= htmlspecialchars((string) ($selectedDomain['domain'] ?? $report['domain']), ENT_QUOTES, 'UTF-8') ?></h2>
            <p>Mail host: <code><?= htmlspecialchars((string) ($report['mail_host'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code> · DKIM selector: <code><?= htmlspecialchars((string) ($report['dkim_selector'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Kiểm tra</th><th>Tên máy chủ (Hostname)</th><th>Trạng thái</th><th>Yêu cầu kỳ vọng</th><th>Thực tế ghi nhận</th></tr>
                </thead>
                <tbody>
                <?php foreach (($report['checks'] ?? []) as $check): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($check['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars((string) ($check['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= $view->render('admin/components/status_badge.php', ['value' => $check['status'] ?? 'unknown']) ?></td>
                        <td><?= htmlspecialchars((string) ($check['expected'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= nl2br(htmlspecialchars((string) ($check['observed'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-note">Checked at <?= htmlspecialchars((string) ($report['checked_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?> UTC.</div>
    </section>
<?php endif; ?>

<?php if ($selectedDomain !== null && is_array($certificateProfiles)): ?>
    <section class="panel" id="dns-check-ssl">
        <div class="panel-header">
            <h2>ACME Mail TLS</h2>
            <p>Cấp SSL trực tiếp cho hostname mail, webmail và submission của domain này từ giao diện quản trị.</p>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Phạm vi</th><th>Tên máy chủ (Hostname)</th><th>DNS</th><th>Chứng chỉ hiện tại</th></tr>
                </thead>
                <tbody>
                <?php foreach (($certificateProfiles['profiles'] ?? []) as $profile): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars((string) ($profile['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
                            <div class="table-note"><?= htmlspecialchars((string) ($profile['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td>
                            <?php foreach (($profile['hosts'] ?? []) as $host): ?>
                                <div><code><?= htmlspecialchars((string) ($host['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></div>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?= $view->render('admin/components/status_badge.php', [
                                'value' => !empty($profile['dns_ready']) ? 'ok' : 'failed',
                                'label' => !empty($profile['dns_ready']) ? 'ready' : 'missing', 'label' => !empty($profile['dns_ready']) ? 'Sẵn sàng' : 'Thiếu', 'label' => !empty($profile['dns_ready']) ? 'Sẵn sàng' : 'Thiếu', 'label' => !empty($profile['dns_ready']) ? 'Sẵn sàng' : 'Thiếu', 'label' => !empty($profile['dns_ready']) ? 'Sẵn sàng' : 'Thiếu',
                            ]) ?>
                            <div class="table-note">
                                <?php foreach (($profile['hosts'] ?? []) as $host): ?>
                                    <div>
                                        <code><?= htmlspecialchars((string) ($host['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>:
                                        <?= nl2br(htmlspecialchars((string) ($host['dns_observed'] ?? ''), ENT_QUOTES, 'UTF-8')) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <?= $view->render('admin/components/status_badge.php', [
                                'value' => !empty($profile['certificate_ready']) ? 'ok' : 'failed',
                                'label' => !empty($profile['certificate_ready']) ? 'ready' : 'missing', 'label' => !empty($profile['certificate_ready']) ? 'Sẵn sàng' : 'Thiếu', 'label' => !empty($profile['certificate_ready']) ? 'Sẵn sàng' : 'Thiếu', 'label' => !empty($profile['certificate_ready']) ? 'Sẵn sàng' : 'Thiếu', 'label' => !empty($profile['certificate_ready']) ? 'Sẵn sàng' : 'Thiếu',
                            ]) ?>
                            <div class="table-note">
                                <?php foreach (($profile['hosts'] ?? []) as $host): ?>
                                    <div>
                                        <code><?= htmlspecialchars((string) ($host['hostname'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>:
                                        <?= $view->render('admin/components/status_badge.php', [
                                            'value' => (string) ($host['certificate_status'] ?? 'missing'),
                                            'label' => (string) ($host['certificate_label'] ?? ($host['certificate_status'] ?? 'missing')),
                                        ]) ?>
                                        <?= htmlspecialchars((string) ($host['certificate_observed'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canAccess('dns_checks.update')): ?>
            <form method="post" action="/admin/dns-checks">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars((string) $csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_intent" value="issue_acme_tls">
                <input type="hidden" name="domain_id" value="<?= (int) ($selectedDomain['id'] ?? 0) ?>">
                <div class="form-grid">
                    <label class="field-span-6">Email ACME
                        <input type="email" name="acme_email" value="<?= htmlspecialchars($acmeDefaultEmail, ENT_QUOTES, 'UTF-8') ?>" placeholder="admin@example.com">
                    </label>
                    <label class="field-span-6">Scope SSL
                        <select name="acme_profile" required>
                            <?php foreach (($certificateProfiles['profiles'] ?? []) as $profile): ?>
                                <option value="<?= htmlspecialchars((string) ($profile['key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" <?= $acmeDefaultProfile === (string) ($profile['key'] ?? '') || ($acmeDefaultProfile === '' && !empty($profile['recommended'])) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) ($profile['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit" name="acme_action" value="issue">Cấp / đồng bộ chứng chỉ SSL</button>
                    <button class="btn btn-secondary" type="submit" name="acme_action" value="renew" data-confirm-click="Gia hạn chứng chỉ ngay bây giờ? Tránh bấm lặp quá nhiều để không chạm giới hạn của CA.">Gia hạn ngay</button>
                </div>

                <div class="table-note">
                    Khuyến nghị: dùng <strong>Mail only</strong> nếu hiện tại bạn mới trỏ mỗi <code>mail.<?= htmlspecialchars((string) ($selectedDomain['domain'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>.
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>
