<?php

declare(strict_types=1);

namespace MailPanel\Tests\Support;

/**
 * Source-scanning helper for the admin web layer.
 *
 * The admin console used to live in one AdminWebController.php. Several security
 * regression tests read that file and asserted on its contents. When the class was
 * split into per-resource controllers plus AdminWebLayoutTrait, those tests kept
 * pointing at a path that no longer exists: file_get_contents() returned false,
 * which cast to "" and made every assertStringNotContainsString() pass vacuously.
 *
 * These helpers concatenate the real sources so the assertions keep their meaning
 * and cannot silently go green again if files move.
 */
final class ControllerSource
{
    /**
     * Every admin web controller plus the shared layout trait, concatenated.
     */
    public static function adminWeb(): string
    {
        return self::concat(array_merge(
            self::glob('/../../src/Http/Controllers/Admin*.php'),
            self::glob('/../../src/Http/Controllers/Traits/*.php'),
        ));
    }

    /**
     * Every controller in the HTTP layer, concatenated.
     */
    public static function allControllers(): string
    {
        return self::concat(array_merge(
            self::glob('/../../src/Http/Controllers/*.php'),
            self::glob('/../../src/Http/Controllers/Traits/*.php'),
        ));
    }

    /**
     * @return array<int, string> Absolute paths, sorted for deterministic output.
     */
    public static function controllerPaths(): array
    {
        return array_merge(
            self::glob('/../../src/Http/Controllers/*.php'),
            self::glob('/../../src/Http/Controllers/Traits/*.php'),
        );
    }

    public static function file(string $relativePath): string
    {
        $path = __DIR__ . '/../../' . ltrim($relativePath, '/');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new \RuntimeException("Expected source file is missing: {$relativePath}");
        }

        return $contents;
    }

    /**
     * @return array<int, string>
     */
    private static function glob(string $pattern): array
    {
        $paths = glob(__DIR__ . $pattern) ?: [];
        sort($paths);

        return $paths;
    }

    /**
     * @param array<int, string> $paths
     */
    private static function concat(array $paths): string
    {
        if ($paths === []) {
            throw new \RuntimeException('No controller sources found; the layout changed.');
        }

        $parts = [];
        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException("Unable to read {$path}");
            }
            $parts[] = $contents;
        }

        return implode("\n", $parts);
    }
}
