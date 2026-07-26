<?php

declare(strict_types=1);

$value = (string) ($value ?? '');
$label = (string) ($label ?? $value);

// Vietnamese translation map for common status labels
$translations = [
    'active' => 'Hoạt động',
    'suspended' => 'Tạm khóa',
    'pending_dns' => 'Chờ DNS',
    'ready' => 'Sẵn sàng',
    'missing' => 'Thiếu',
    'enabled' => 'Đang bật',
    'disabled' => 'Đang tắt',
    'unknown' => 'Không rõ',
    'generated' => 'Bản nháp',
    'applied' => 'Đã áp dụng',
    'warning' => 'Cảnh báo',
    'error' => 'Lỗi',
    'failed' => 'Thất bại',
    'expiring_soon' => 'Sắp hết hạn',
    'grace' => 'Gia hạn tạm',
    'terminated' => 'Đã chấm dứt',
    'expired' => 'Đã hết hạn',
    'valid' => 'Hợp lệ',
    'validated' => 'Đã xác thực',
    'ok' => 'Đang chạy',
    'info' => 'Thông tin',
    'default' => 'Mặc định',
];

$loweredLabel = strtolower(trim($label));
if (isset($translations[$loweredLabel])) {
    $label = $translations[$loweredLabel];
}

$class = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($value)) ?: 'default';
?>
<span class="badge <?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
</span>
