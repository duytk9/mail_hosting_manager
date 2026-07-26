<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Support\SafePath;

final class WebmailHealthService
{
    private readonly string $webmailRoot;
    private readonly string $authLogPath;

    public function __construct(
        private readonly bool $enabled,
        string $webmailRoot,
        string $authLogPath,
        private readonly string $displayName = 'Webmail'
    ) {
        $this->webmailRoot = SafePath::absolute($webmailRoot, 'webmail root');
        $this->authLogPath = SafePath::absolute($authLogPath, 'webmail auth log path');
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $maxLogLines = 80): array
    {
        $configPath = rtrim($this->webmailRoot, '/\\') . '/config/config.inc.php';
        $realRoot = realpath($this->webmailRoot) ?: $this->webmailRoot;
        $authLogRealPath = realpath($this->authLogPath) ?: $this->authLogPath;

        $version = '';
        if (is_file($realRoot . '/program/include/iniset.php')) {
            $content = file_get_contents($realRoot . '/program/include/iniset.php');
            if (preg_match("/define\('RCMAIL_VERSION',\s*'([^']+)'\)/", $content, $matches)) {
                $version = $matches[1];
            }
        }

        return [
            'enabled' => $this->enabled,
            'display_name' => $this->displayName,
            'webmail_root' => $this->webmailRoot,
            'webmail_root_realpath' => $realRoot,
            'config_path' => $configPath,
            'config_exists' => is_file($configPath),
            'version' => $version,
            'auth_log_path' => $this->authLogPath,
            'auth_log_realpath' => $authLogRealPath,
            'auth_log_exists' => is_file($this->authLogPath),
            'auth_log_size_bytes' => is_file($this->authLogPath) ? (int) filesize($this->authLogPath) : 0,
            'auth_log_updated_at' => is_file($this->authLogPath) ? (int) filemtime($this->authLogPath) : null,
            'auth_log_tail' => $this->tailFile($this->authLogPath, $maxLogLines),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tailFile(string $path, int $maxLines): array
    {
        if (!is_file($path) || $maxLines <= 0) {
            return [];
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }

        return array_values(array_slice($lines, -$maxLines));
    }
}
