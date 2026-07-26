<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\RateLimiterService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RateLimiterServiceTest extends TestCase
{
    private ?string $storagePath = null;

    protected function tearDown(): void
    {
        if ($this->storagePath !== null && is_dir($this->storagePath)) {
            foreach (glob($this->storagePath . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_hit_allows_exactly_max_attempts_and_blocks_next_one(): void
    {
        $service = $this->service();

        $service->hit('api:127.0.0.1:/api/admin/dashboard', 2, 60);
        $state = $service->hit('api:127.0.0.1:/api/admin/dashboard', 2, 60);

        $this->assertSame(2, $state['attempts']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many requests.');

        $service->hit('api:127.0.0.1:/api/admin/dashboard', 2, 60);
    }

    public function test_assert_within_limit_blocks_after_max_failures(): void
    {
        $service = $this->service();

        $service->hit('admin-login:ops:127.0.0.1', 2, 60);
        $service->hit('admin-login:ops:127.0.0.1', 2, 60);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many requests.');

        $service->assertWithinLimit('admin-login:ops:127.0.0.1', 2, 60);
    }

    public function test_rejects_unsafe_storage_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('rate limit storage path');

        new RateLimiterService('../rate-limits');
    }

    public function test_invalid_limits_are_clamped_to_safe_minimums(): void
    {
        $service = $this->service();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Too many requests.');

        $service->hit('api:invalid-config', 0, 0);
        $service->hit('api:invalid-config', 0, 0);
    }

    public function test_storage_creation_and_symlink_guards_are_present(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/src/Services/RateLimiterService.php');

        $this->assertStringContainsString('Unable to create rate limit storage.', $source);
        $this->assertStringContainsString('Unsafe rate limit storage path.', $source);
        $this->assertStringContainsString('assertNoSymlinkPath($directory)', $source);
        $this->assertStringContainsString('private function assertNoSymlinkPath', $source);
        $this->assertStringContainsString('is_link($path)', $source);
    }

    private function service(): RateLimiterService
    {
        $this->storagePath ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mailpanel-rate-limit-' . bin2hex(random_bytes(4));

        return new RateLimiterService($this->storagePath);
    }
}
