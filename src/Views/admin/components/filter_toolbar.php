<?php

declare(strict_types=1);

$title = (string) ($title ?? 'Bộ lọc');
$action = (string) ($action ?? '');
$method = strtoupper((string) ($method ?? 'GET'));
$fields = is_array($fields ?? null) ? $fields : [];
$summary = trim((string) ($summary ?? ''));
$resetHref = (string) ($resetHref ?? $action);
$resultCount = isset($resultCount) ? (int) $resultCount : null;
$resultLabel = (string) ($resultLabel ?? 'kết quả');
$badges = is_array($badges ?? null) ? $badges : [];
$debounceMs = max(0, (int) ($debounceMs ?? 250));
$hidden = is_array($hidden ?? null) ? $hidden : [];

$resolvedFields = [];
$primaryIndexes = [];
$hasExplicitPrimary = false;

foreach ($fields as $index => $field) {
    if (!is_array($field)) {
        continue;
    }

    $resolvedFields[$index] = [
        'type' => (string) ($field['type'] ?? 'text'),
        'name' => (string) ($field['name'] ?? ''),
        'label' => (string) ($field['label'] ?? ($field['name'] ?? '')),
        'value' => $field['value'] ?? '',
        'placeholder' => (string) ($field['placeholder'] ?? ''),
        'options' => is_array($field['options'] ?? null) ? $field['options'] : [],
        'primary' => isset($field['primary']) ? (bool) $field['primary'] : null,
        'default' => isset($field['default']) ? (string) $field['default'] : null,
        'min' => isset($field['min']) ? (string) $field['min'] : null,
        'max' => isset($field['max']) ? (string) $field['max'] : null,
        'step' => isset($field['step']) ? (string) $field['step'] : null,
    ];

    if (($resolvedFields[$index]['primary'] ?? null) === true) {
        $primaryIndexes[$index] = true;
        $hasExplicitPrimary = true;
    }
}

if (!$hasExplicitPrimary && $resolvedFields !== []) {
    foreach ($resolvedFields as $index => $field) {
        if ($field['type'] === 'search') {
            $primaryIndexes[$index] = true;
            break;
        }
    }

    foreach ($resolvedFields as $index => $field) {
        if ($field['type'] === 'select' && !isset($primaryIndexes[$index])) {
            $primaryIndexes[$index] = true;
            break;
        }
    }

    if ($primaryIndexes === []) {
        $firstIndex = array_key_first($resolvedFields);
        if ($firstIndex !== null) {
            $primaryIndexes[$firstIndex] = true;
        }
    }

    if (count($primaryIndexes) === 1 && count($resolvedFields) > 1) {
        foreach ($resolvedFields as $index => $field) {
            if (!isset($primaryIndexes[$index])) {
                $primaryIndexes[$index] = true;
                break;
            }
        }
    }
}

$hasActiveFilters = false;
$activeSecondaryCount = 0;

foreach ($resolvedFields as $index => $field) {
    $value = trim((string) $field['value']);
    $defaultValue = $field['default'] ?? '';

    if ($field['default'] === null && $field['type'] === 'select') {
        $defaultValue = (string) (($field['options'][0]['value'] ?? ''));
    }

    $isActive = $value !== '' && $value !== $defaultValue;
    if (!$isActive) {
        continue;
    }

    $hasActiveFilters = true;

    if (!isset($primaryIndexes[$index])) {
        $activeSecondaryCount++;
    }
}

$primaryFields = [];
$secondaryFields = [];

foreach ($resolvedFields as $index => $field) {
    if (isset($primaryIndexes[$index])) {
        $primaryFields[] = $field;
    } else {
        $secondaryFields[] = $field;
    }
}

$renderField = static function (array $field): void {
    $type = $field['type'];
    $name = $field['name'];
    $label = $field['label'];
    $value = $field['value'];
    $placeholder = $field['placeholder'];
    $options = $field['options'];
    $min = $field['min'];
    $max = $field['max'];
    $step = $field['step'];
    $fieldClass = 'filter-field';

    if ($type === 'search') {
        $fieldClass .= ' filter-field--search';
    }
    ?>
    <label class="<?= htmlspecialchars($fieldClass, ENT_QUOTES, 'UTF-8') ?>">
        <span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
        <?php if ($type === 'select'): ?>
            <select name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($options as $option): ?>
                    <?php $optionValue = (string) ($option['value'] ?? ''); ?>
                    <option value="<?= htmlspecialchars($optionValue, ENT_QUOTES, 'UTF-8') ?>" <?= (string) $value === $optionValue ? 'selected' : '' ?>>
                        <?= htmlspecialchars((string) ($option['label'] ?? $optionValue), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input
                type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') ?>"
                <?= $min !== null ? 'min="' . htmlspecialchars($min, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                <?= $max !== null ? 'max="' . htmlspecialchars($max, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
                <?= $step !== null ? 'step="' . htmlspecialchars($step, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
            >
        <?php endif; ?>
    </label>
    <?php
};
?>
<section
    class="page-action-bar"
    data-page-action-bar
    aria-label="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
    <?= $summary !== '' ? 'title="' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '"' : '' ?>
>
    <form
        class="page-action-bar__filters filters filters--compact js-admin-filters"
        method="<?= htmlspecialchars($method, ENT_QUOTES, 'UTF-8') ?>"
        action="<?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?>"
        data-live-submit="1"
        data-debounce="<?= $debounceMs ?>"
    >
        <?php foreach ($hidden as $name => $value): ?>
            <input type="hidden" name="<?= htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') ?>">
        <?php endforeach; ?>

        <div class="filters__primary">
            <?php foreach ($primaryFields as $field): ?>
                <?php $renderField($field); ?>
            <?php endforeach; ?>
        </div>

        <?php if ($secondaryFields !== []): ?>
            <details class="filters-panel" <?= $activeSecondaryCount > 0 ? 'open' : '' ?>>
                <summary class="btn btn-secondary btn-sm filters-panel__toggle">
                    Bộ lọc
                    <?php if ($activeSecondaryCount > 0): ?>
                        <span class="filters-panel__count"><?= $activeSecondaryCount ?></span>
                    <?php endif; ?>
                </summary>
                <div class="filters-panel__body">
                    <?php foreach ($secondaryFields as $field): ?>
                        <?php $renderField($field); ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>

        <?php if ($hasActiveFilters): ?>
            <a class="action-link secondary btn-sm filters__reset" href="<?= htmlspecialchars($resetHref, ENT_QUOTES, 'UTF-8') ?>">Xóa bộ lọc</a>
        <?php endif; ?>

        <button class="visually-hidden" type="submit">Áp dụng bộ lọc</button>
    </form>

    <div class="page-action-bar__meta filter-toolbar__meta">
        <?php if ($resultCount !== null): ?>
            <span class="chip filter-toolbar__chip"><?= $resultCount ?> <?= htmlspecialchars($resultLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
        <?php foreach ($badges as $badge): ?>
            <span class="chip filter-toolbar__chip">
                <?= htmlspecialchars((string) ($badge['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?>:
                <?= htmlspecialchars((string) ($badge['value'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </span>
        <?php endforeach; ?>
        <?php if ($activeSecondaryCount > 0): ?>
            <span class="chip filter-toolbar__chip is-active"><?= $activeSecondaryCount ?> bộ lọc</span>
        <?php endif; ?>
    </div>

    <div class="page-action-bar__actions" data-page-action-items></div>
</section>
