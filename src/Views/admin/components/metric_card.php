<?php

declare(strict_types=1);

$icon = trim((string) ($icon ?? ''));
$tone = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower((string) ($tone ?? 'cyan'))) ?: 'cyan';
?>
<article class="metric-card metric-card--<?= htmlspecialchars($tone, ENT_QUOTES, 'UTF-8') ?>">
    <div class="metric-card__top">
        <div>
            <div class="metric-card__label"><?= htmlspecialchars((string) ($label ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="metric-card__value"><?= htmlspecialchars((string) ($value ?? '0'), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <?php if ($icon !== ''): ?>
            <span class="metric-card__icon"><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
    </div>
    <?php if (!empty($hint)): ?>
        <div class="metric-card__hint"><?= htmlspecialchars((string) $hint, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
</article>
