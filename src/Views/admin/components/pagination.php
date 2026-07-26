<?php

declare(strict_types=1);

$pagination = is_array($pagination ?? null) ? $pagination : [];
$currentPage = (int) ($pagination['current_page'] ?? 1);
$totalPages = (int) ($pagination['total_pages'] ?? 1);
$totalItems = (int) ($pagination['total_items'] ?? 0);
$from = (int) ($pagination['from'] ?? 0);
$to = (int) ($pagination['to'] ?? 0);
$queryKey = (string) ($pagination['query_key'] ?? 'page');
$basePath = (string) ($pagination['base_path'] ?? '');
$params = is_array($pagination['params'] ?? null) ? $pagination['params'] : [];

if ($totalPages <= 1) {
    return;
}

$buildUrl = static function (int $page) use ($basePath, $params, $queryKey): string {
    $query = $params;

    if ($page > 1) {
        $query[$queryKey] = $page;
    } else {
        unset($query[$queryKey]);
    }

    $queryString = http_build_query($query);

    return $queryString === '' ? $basePath : $basePath . '?' . $queryString;
};

$startPage = max(1, $currentPage - 2);
$endPage = min($totalPages, $currentPage + 2);

if ($endPage - $startPage < 4) {
    $startPage = max(1, min($startPage, $totalPages - 4));
    $endPage = min($totalPages, max($endPage, 5));
}
?>
<nav class="pagination" aria-label="Điều hướng phân trang">
    <div class="pagination__summary">
        Hiển thị <?= $from ?>–<?= $to ?> / <?= $totalItems ?>
    </div>
    <div class="pagination__links">
        <?php if ($currentPage > 1): ?>
            <a class="pagination__link" href="<?= htmlspecialchars($buildUrl($currentPage - 1), ENT_QUOTES, 'UTF-8') ?>">← Trước</a>
        <?php endif; ?>

        <?php if ($startPage > 1): ?>
            <a class="pagination__link" href="<?= htmlspecialchars($buildUrl(1), ENT_QUOTES, 'UTF-8') ?>">1</a>
            <?php if ($startPage > 2): ?><span class="pagination__gap">…</span><?php endif; ?>
        <?php endif; ?>

        <?php for ($page = $startPage; $page <= $endPage; $page++): ?>
            <a class="pagination__link <?= $page === $currentPage ? 'is-active' : '' ?>"
               href="<?= htmlspecialchars($buildUrl($page), ENT_QUOTES, 'UTF-8') ?>">
                <?= $page ?>
            </a>
        <?php endfor; ?>

        <?php if ($endPage < $totalPages): ?>
            <?php if ($endPage < $totalPages - 1): ?><span class="pagination__gap">…</span><?php endif; ?>
            <a class="pagination__link" href="<?= htmlspecialchars($buildUrl($totalPages), ENT_QUOTES, 'UTF-8') ?>"><?= $totalPages ?></a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
            <a class="pagination__link" href="<?= htmlspecialchars($buildUrl($currentPage + 1), ENT_QUOTES, 'UTF-8') ?>">Sau →</a>
        <?php endif; ?>
    </div>
</nav>
