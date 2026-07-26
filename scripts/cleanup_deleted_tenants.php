<?php

declare(strict_types=1);

use MailPanel\Bootstrap\Environment;
use MailPanel\Core\Config;
use MailPanel\Core\Database;
use MailPanel\Repositories\Pdo\TenantPurgeRepository;

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
Environment::load($basePath);

$config = new Config($basePath, [
    'database' => require $basePath . '/config/database.php',
]);

$options = [
    'all_deleted' => false,
    'all_soft_deleted' => false,
    'dry_run' => false,
    'tenant_ids' => [],
    'slugs' => [],
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--all-deleted') {
        $options['all_deleted'] = true;
        continue;
    }

    if ($argument === '--all-soft-deleted') {
        $options['all_soft_deleted'] = true;
        continue;
    }

    if ($argument === '--dry-run') {
        $options['dry_run'] = true;
        continue;
    }

    if (str_starts_with($argument, '--tenant-id=')) {
        $tenantId = (int) substr($argument, strlen('--tenant-id='));
        if ($tenantId > 0) {
            $options['tenant_ids'][] = $tenantId;
        }
        continue;
    }

    if (str_starts_with($argument, '--slug=')) {
        $slug = trim(substr($argument, strlen('--slug=')));
        if ($slug !== '') {
            $options['slugs'][] = $slug;
        }
        continue;
    }

    fwrite(STDERR, "Unknown argument: {$argument}\n");
    exit(2);
}

try {
    $database = new Database($config->get('database'));
    $purge = new TenantPurgeRepository($database);
    $tenantIds = $options['tenant_ids'];

    if ($options['all_deleted'] || $options['all_soft_deleted']) {
        echo json_encode($purge->purgeSoftDeletedRows((bool) $options['dry_run']), JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    foreach ($options['slugs'] as $slug) {
        $tenant = $purge->findBySlugIncludingDeleted($slug);
        if ($tenant === null) {
            echo "No tenant found for slug {$slug}\n";
            continue;
        }

        $tenantIds[] = (int) $tenant['id'];
    }

    $tenantIds = array_values(array_unique(array_filter($tenantIds, static fn (int $tenantId): bool => $tenantId > 0)));

    if ($tenantIds === [] && !$options['all_deleted'] && !$options['all_soft_deleted']) {
        fwrite(STDERR, "Usage: php scripts/cleanup_deleted_tenants.php --all-deleted [--dry-run]\n");
        fwrite(STDERR, "   or: php scripts/cleanup_deleted_tenants.php --all-soft-deleted [--dry-run]\n");
        fwrite(STDERR, "   or: php scripts/cleanup_deleted_tenants.php --tenant-id=123 [--dry-run]\n");
        fwrite(STDERR, "   or: php scripts/cleanup_deleted_tenants.php --slug=tenant-slug [--dry-run]\n");
        exit(1);
    }

    foreach ($tenantIds as $tenantId) {
        $result = $purge->purgeTenant($tenantId, (bool) $options['dry_run']);
        echo json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cleanup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
