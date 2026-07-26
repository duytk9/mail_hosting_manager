<?php

declare(strict_types=1);

namespace MailPanel\Services;

use MailPanel\Support\SafePath;

final class WebmailApplicationConfigService
{
    public const MANAGED_PLUGIN_ID = WebmailPluginDeploymentService::PLUGIN_ID;

    private readonly string $webmailRoot;
    private readonly string $authLogPath;

    public function __construct(
        private readonly bool $enabled,
        string $webmailRoot,
        string $authLogPath,
        private readonly string $displayName = 'MailPanel Webmail',
        private readonly string $loadingDescription = 'Business email workspace',
    ) {
        $this->webmailRoot = SafePath::absolute($webmailRoot, 'webmail root');
        $this->authLogPath = SafePath::absolute($authLogPath, 'webmail auth log path');
    }

    /**
     * @return array{config_path:string,log_dir:string,auth_log:string,updated:bool}
     */
    public function sync(): array
    {
        $configPath = $this->applicationConfigPath();
        $logDir = $this->authLogDirectory();
        $authLog = $this->authLogFileName();

        if (!$this->enabled || !is_file($configPath)) {
            return [
                'config_path' => $configPath,
                'log_dir' => $logDir,
                'auth_log' => $authLog,
                'updated' => false,
            ];
        }

        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }

        $authLogPath = $logDir . '/' . $authLog;
        if (!is_file($authLogPath)) {
            touch($authLogPath);
        }

        $config = parse_ini_file($configPath, true, INI_SCANNER_RAW);
        if (!is_array($config)) {
            $config = [];
        }

        foreach (['webmail', 'interface', 'contacts', 'security', 'login', 'defaults', 'logs', 'ssl', 'plugins'] as $section) {
            if (!isset($config[$section]) || !is_array($config[$section])) {
                $config[$section] = [];
            }
        }

        $config['webmail']['title'] = $this->displayName;
        $config['webmail']['loading_description'] = $this->loadingDescription;
        $config['webmail']['allow_themes'] = true;
        $config['webmail']['allow_user_background'] = false;
        $config['webmail']['popup_identity'] = false;
        $config['webmail']['messages_per_page'] = 50;
        $config['webmail']['min_refresh_interval'] = 2;
        $config['webmail']['attachment_size_limit'] = 25;

        $config['interface']['show_attachment_thumbnail'] = true;

        $config['contacts']['enable'] = true;
        $config['contacts']['allow_sync'] = false;

        $config['security']['custom_server_signature'] = $this->displayName;
        $config['security']['allow_admin_panel'] = false;
        $config['security']['force_https'] = true;
        $config['security']['cookie_samesite'] = 'Strict';

        $config['login']['fault_delay'] = 5;
        $config['login']['cookie_samesite'] = 'Strict';
        $config['login']['sign_me_auto'] = 'DefaultOn';

        $config['defaults']['allow_spellcheck'] = true;
        $config['defaults']['mail_use_threads'] = true;
        $config['defaults']['contacts_autosave'] = true;

        $config['logs']['enable'] = true;
        $config['logs']['path'] = $logDir;
        $config['logs']['auth_logging'] = true;
        $config['logs']['auth_logging_filename'] = $authLog;
        $config['logs']['auth_logging_format'] = 'Auth failed: ip={request:ip} user={imap:login}';

        $config['ssl']['verify_certificate'] = true;
        $config['ssl']['allow_self_signed'] = false;

        $plugins = array_values(array_filter(array_map(
            static fn (string $item): string => trim($item),
            explode(',', (string) ($config['plugins']['enabled_list'] ?? ''))
        ), static fn (string $item): bool => $item !== ''));
        if (!in_array(self::MANAGED_PLUGIN_ID, $plugins, true)) {
            $plugins[] = self::MANAGED_PLUGIN_ID;
        }

        $config['plugins']['enable'] = true;
        $config['plugins']['enabled_list'] = implode(',', array_values(array_unique($plugins)));

        $this->writeAtomic($configPath, $this->buildIni($config));

        return [
            'config_path' => $configPath,
            'log_dir' => $logDir,
            'auth_log' => $authLog,
            'updated' => true,
        ];
    }

    private function applicationConfigPath(): string
    {
        return rtrim($this->webmailRoot, '/\\') . '/data/_data_/_default_/configs/application.ini';
    }

    private function authLogDirectory(): string
    {
        return dirname($this->authLogPath);
    }

    private function authLogFileName(): string
    {
        return basename($this->authLogPath);
    }

    /**
     * @param array<string, array<string, mixed>> $config
     */
    private function buildIni(array $config): string
    {
        $buffer = '';

        foreach ($config as $section => $values) {
            $buffer .= sprintf("[%s]\n", $section);

            foreach ($values as $key => $value) {
                $buffer .= sprintf("%s = %s\n", $key, $this->formatValue($value));
            }

            $buffer .= "\n";
        }

        return $buffer;
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'On' : 'Off';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;
        if (preg_match('/[\x00-\x1F\x7F]/', $string) === 1) {
            throw new \InvalidArgumentException('Invalid control characters in webmail application config value.');
        }

        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $string);

        return '"' . $escaped . '"';
    }

    private function writeAtomic(string $path, string $contents): void
    {
        $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(8));

        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write webmail application config.');
        }

        @chmod($temporaryPath, 0640);

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('Unable to publish webmail application config.');
        }
    }
}
