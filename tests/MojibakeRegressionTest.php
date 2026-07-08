<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class MojibakeRegressionTest extends TestCase
{
    public function test_source_files_do_not_contain_common_utf8_mojibake_sequences(): void
    {
        $root = dirname(__DIR__);
        $scanRoots = ['agent', 'config', 'database', 'deploy', 'docs', 'public', 'routes', 'scripts', 'src', 'tests'];
        $extensions = ['css' => true, 'js' => true, 'json' => true, 'md' => true, 'php' => true, 'sh' => true, 'sql' => true, 'txt' => true];
        $patterns = [
            '/[\x{00C3}\x{00C4}\x{00C5}\x{00C6}][\x{0080}-\x{00BF}\x{2018}\x{2019}]/u',
            '/\x{00E1}[\x{00BA}\x{00BB}]/u',
            '/\x{00E2}[\x{0080}-\x{00BF}\x{02DC}\x{20AC}]/u',
        ];
        $badFiles = [];

        foreach ($scanRoots as $scanRoot) {
            $path = $root . DIRECTORY_SEPARATOR . $scanRoot;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof SplFileInfo || !$file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if (!isset($extensions[$extension]) && $file->getBasename() !== '.env.example') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $contents) === 1) {
                        $badFiles[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $badFiles, 'Mojibake-like UTF-8 corruption found in source files.');
    }

    public function test_portal_settings_keeps_vietnamese_labels_as_utf8(): void
    {
        $view = (string) file_get_contents(dirname(__DIR__) . '/src/Views/admin/pages/portal_settings.php');

        foreach ([
            'Cấu hình Portal',
            'Phương thức gửi',
            'Mã hóa',
            'Không mã hóa',
            'Đặt lại mật khẩu MailPanel',
            'Đã lưu mật khẩu SMTP mã hóa',
            'Mật khẩu hiện tại',
        ] as $expected) {
            $this->assertStringContainsString($expected, $view);
        }

        foreach (['C?u h?nh', 'Ph??ng th?c', 'M? h?a', '??t l?i', 'm?t kh?u'] as $broken) {
            $this->assertStringNotContainsString($broken, $view);
        }
    }
}
