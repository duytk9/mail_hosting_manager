<?php

declare(strict_types=1);

$title = 'Nhật ký hệ thống';
$summary = 'Giám sát trực tiếp log của các dịch vụ như Exim, Dovecot, Nginx, Rspamd và agent.';
$active_menu = 'logs';
$services = $services ?? ['exim'];
$current_service = $current_service ?? 'exim';
$lines = $lines ?? 100;
$log_content = $log_content ?? '';
?>
<?= $view->render('admin/components/filter_toolbar.php', [
    'title' => 'Bộ lọc nhật ký hệ thống',
    'action' => '/admin/logs',
    'summary' => 'Lọc theo dịch vụ, số dòng và từ khóa.',
    'resetHref' => '/admin/logs',
    'fields' => [
        [
            'label' => 'Dịch vụ',
            'name' => 'service',
            'type' => 'select',
            'value' => (string) $current_service,
            'options' => array_map(
                static fn (string $service): array => [
                    'value' => $service,
                    'label' => ucfirst($service),
                ],
                $services
            ),
        ],
        [
            'label' => 'Từ khóa',
            'name' => 'keyword',
            'type' => 'search',
            'value' => (string) ($keyword ?? ''),
            'placeholder' => 'Nhập từ khóa...',
        ],
        [
            'label' => 'Số dòng',
            'name' => 'lines',
            'type' => 'number',
            'value' => (string) $lines,
            'default' => 100,
            'min' => 1,
            'step' => 1,
        ],
    ],
]) ?>

<section class="panel">
    <div class="panel-header">
        <h2>Nhật ký hệ thống</h2>
        <p>Kết quả nhật ký theo bộ lọc hiện tại.</p>
    </div>

    <div class="log-console">
        <?php if (empty($log_content)): ?>
            <span class="text-muted">(Không có dữ liệu log cho <?= htmlspecialchars($current_service, ENT_QUOTES, 'UTF-8') ?>)</span>
        <?php else: ?>
            <pre><?= htmlspecialchars($log_content, ENT_QUOTES, 'UTF-8') ?></pre>
        <?php endif; ?>
    </div>
</section>
