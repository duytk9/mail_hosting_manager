<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers;

use MailPanel\Core\Request;
use MailPanel\Http\Controllers\Traits\AdminWebLayoutTrait;
use MailPanel\Core\Response;
use MailPanel\Repositories\Pdo\DomainRepository;
use MailPanel\Repositories\Pdo\MailboxRepository;
use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\SessionManager;
use MailPanel\Services\AgentClientService;
use MailPanel\Services\AuditLogService;
use MailPanel\Services\WebmailApplicationConfigService;
use MailPanel\Services\WebmailDomainConfigService;
use MailPanel\Services\WebmailHealthService;
use MailPanel\Services\WebmailPluginDeploymentService;
use MailPanel\Services\WebmailUserStorageService;
use MailPanel\Support\View;

final class SecuritySystemController
{
    use AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly View $view,
        private readonly AgentClientService $agentClient,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization,
        private readonly WebmailHealthService $webmailHealth,
        private readonly DomainRepository $domains,
        private readonly MailboxRepository $mailboxes,
        private readonly AuditLogService $auditLog,
        private readonly WebmailDomainConfigService $webmailDomains,
        private readonly WebmailUserStorageService $webmailUsers,
        private readonly WebmailApplicationConfigService $webmailApplication,
        private readonly WebmailPluginDeploymentService $webmailPlugin,
    ) {
    }

    public function webmail(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/webmail')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission(
            $request->method === 'POST' ? 'super_admins.update' : 'super_admins.view',
            '/admin/dashboard'
        )) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $csrfToken = (string) ($request->body['_csrf'] ?? '');
            if (!$this->sessions->verifyCsrf($csrfToken)) {
                $this->sessions->flash('error', 'Lỗi bảo mật (CSRF). Vui lòng thử lại.');

                return Response::redirect('/admin/webmail');
            }

            try {
                $domainRows = $this->domains->all();
                $mailboxRows = $this->mailboxes->all();
                $domainSync = $this->webmailDomains->syncManagedDomains($domainRows);
                $mailboxSync = $this->webmailUsers->bootstrapMailboxes($mailboxRows);
                $applicationSync = $this->webmailApplication->sync();
                $pluginSync = $this->webmailPlugin->sync();

                $this->auditLog->log([
                    'action' => 'webmail.synced',
                    'target_type' => 'webmail',
                    'new_values' => [
                        'domain_sync' => $domainSync,
                        'mailbox_sync' => $mailboxSync,
                        'application_sync' => $applicationSync,
                        'plugin_sync' => $pluginSync,
                    ],
                ]);
                $this->sessions->flash('success', 'Đã đồng bộ cấu hình Webmail/SnappyMail.');
            } catch (\Throwable $exception) {
                $this->sessions->flash('error', 'Lỗi đồng bộ Webmail: ' . $this->safeSystemError($exception));
            }

            return Response::redirect('/admin/webmail');
        }

        $snapshot = $this->webmailHealth->snapshot();
        $fail2ban = ['jails' => [], 'raw' => ''];

        try {
            $response = $this->agentClient->securitySystem(['action' => 'fail2ban-status']);
            $rawOutput = $this->sanitizeSystemOutput((string) ($response['result']['stdout'] ?? ''));
            $fail2ban = $this->parseFail2banStatus($rawOutput);
        } catch (\Throwable $exception) {
            $fail2ban = ['jails' => [], 'raw' => '', 'error' => $this->safeSystemError($exception)];
        }

        return Response::html($this->renderPage('admin/pages/webmail.php', [
            'snapshot' => $snapshot,
            'fail2ban' => $fail2ban,
        ], 'webmail_health', 'Webmail Health'));
    }

    public function fail2ban(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/fail2ban')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view', '/admin/dashboard')) {
            return $redirect;
        }

        try {
            $response = $this->agentClient->securitySystem(['action' => 'fail2ban-status']);
            $rawOutput = $this->sanitizeSystemOutput((string) ($response['result']['stdout'] ?? ''));
            $statusData = $this->parseFail2banStatus($rawOutput);
        } catch (\Exception $exception) {
            $statusData = ['error' => $this->safeSystemError($exception)];
        }

        return Response::html($this->renderPage('admin/pages/fail2ban.php', [
            'statusData' => $statusData,
        ], 'fail2ban', 'Quản lý Fail2ban'));
    }

    public function fail2banUnban(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/fail2ban')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view', '/admin/dashboard')) {
            return $redirect;
        }

        $csrfToken = (string) ($request->body['_csrf'] ?? '');
        if (!$this->sessions->verifyCsrf($csrfToken)) {
            $this->sessions->flash('error', 'Lỗi bảo mật (CSRF). Vui lòng thử lại.');

            return Response::redirect('/admin/fail2ban');
        }

        $jail = trim((string) ($request->body['jail'] ?? ''));
        $ip = trim((string) ($request->body['ip'] ?? ''));

        if (!$this->isValidFail2banJail($jail) || !$this->isValidIpAddress($ip)) {
            $this->sessions->flash('error', 'Tham số không hợp lệ.');

            return Response::redirect('/admin/fail2ban');
        }

        try {
            $this->agentClient->securitySystem([
                'action' => 'fail2ban-unban',
                'jail' => $jail,
                'ip' => $ip,
            ]);

            $this->sessions->flash('success', "Đã gỡ chặn IP $ip thành công.");

            return Response::redirect('/admin/fail2ban');
        } catch (\Exception $exception) {
            $this->sessions->flash('error', 'Lỗi: ' . $this->safeSystemError($exception));

            return Response::redirect('/admin/fail2ban');
        }
    }

    public function rspamd(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/rspamd')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view', '/admin/dashboard')) {
            return $redirect;
        }

        if ($request->method === 'POST') {
            $csrfToken = (string) ($request->body['_csrf'] ?? '');
            if (!$this->sessions->verifyCsrf($csrfToken)) {
                $this->sessions->flash('error', 'Lỗi bảo mật (CSRF). Vui lòng thử lại.');

                return Response::redirect('/admin/rspamd');
            }

            try {
                $reject = $this->normalizeRspamdScore($request->body['reject'] ?? 15, 'reject');
                $addHeader = $this->normalizeRspamdScore($request->body['add_header'] ?? 6, 'add_header');
                $greylist = $this->normalizeRspamdScore($request->body['greylist'] ?? 4, 'greylist');

                $ip_wl = $this->normalizeRspamdMapList((string) ($request->body['ip_wl'] ?? ''), 'ip');
                $ip_bl = $this->normalizeRspamdMapList((string) ($request->body['ip_bl'] ?? ''), 'ip');
                $sender_wl = $this->normalizeRspamdMapList((string) ($request->body['sender_wl'] ?? ''), 'sender');
                $sender_bl = $this->normalizeRspamdMapList((string) ($request->body['sender_bl'] ?? ''), 'sender');
                $rcpt_wl = $this->normalizeRspamdMapList((string) ($request->body['rcpt_wl'] ?? ''), 'recipient');
                $rcpt_bl = $this->normalizeRspamdMapList((string) ($request->body['rcpt_bl'] ?? ''), 'recipient');

                $this->agentClient->securitySystem([
                    'action' => 'rspamd-set-scores',
                    'reject' => $reject,
                    'add_header' => $addHeader,
                    'greylist' => $greylist,
                ]);
                $this->agentClient->securitySystem([
                    'action' => 'rspamd-set-multimap',
                    'ip_wl' => $ip_wl,
                    'ip_bl' => $ip_bl,
                    'sender_wl' => $sender_wl,
                    'sender_bl' => $sender_bl,
                    'rcpt_wl' => $rcpt_wl,
                    'rcpt_bl' => $rcpt_bl,
                ]);
                $this->sessions->flash('success', 'Đã lưu cấu hình điểm Spam và Multimap Rspamd thành công.');

                return Response::redirect('/admin/rspamd');
            } catch (\Exception $exception) {
                $this->sessions->flash('error', 'Lỗi lưu cấu hình: ' . $this->safeSystemError($exception));

                return Response::redirect('/admin/rspamd');
            }
        }

        try {
            $response = $this->agentClient->securitySystem(['action' => 'rspamd-get-scores']);
            $rawOutput = $this->sanitizeSystemOutput((string) ($response['result']['stdout'] ?? ''));
            $scores = $this->parseRspamdScores($rawOutput);
            
            $multiResponse = $this->agentClient->securitySystem(['action' => 'rspamd-get-multimap']);
            $multimaps = [
                'ip_wl' => $this->sanitizeSystemOutput((string) ($multiResponse['ip_wl'] ?? '')),
                'ip_bl' => $this->sanitizeSystemOutput((string) ($multiResponse['ip_bl'] ?? '')),
                'sender_wl' => $this->sanitizeSystemOutput((string) ($multiResponse['sender_wl'] ?? '')),
                'sender_bl' => $this->sanitizeSystemOutput((string) ($multiResponse['sender_bl'] ?? '')),
                'rcpt_wl' => $this->sanitizeSystemOutput((string) ($multiResponse['rcpt_wl'] ?? '')),
                'rcpt_bl' => $this->sanitizeSystemOutput((string) ($multiResponse['rcpt_bl'] ?? '')),
            ];
        } catch (\Exception $exception) {
            $scores = ['error' => $this->safeSystemError($exception)];
            $multimaps = ['ip_wl' => '', 'ip_bl' => '', 'sender_wl' => '', 'sender_bl' => '', 'rcpt_wl' => '', 'rcpt_bl' => ''];
        }

        return Response::html($this->renderPage('admin/pages/rspamd.php', [
            'scores' => $scores,
            'multimaps' => $multimaps,
        ], 'rspamd', 'Cấu hình Lọc Spam (Rspamd)'));
    }

    private function parseFail2banStatus(string $rawOutput): array
    {
        $rawOutput = $this->sanitizeSystemOutput($rawOutput);
        $jails = [];
        $currentJail = '';

        foreach (explode("\n", $rawOutput) as $line) {
            if (preg_match('/Status for the jail:\s*(.+)/i', $line, $matches)) {
                $candidate = trim($matches[1]);
                $currentJail = $this->isValidFail2banJail($candidate) ? $candidate : '';
                if ($currentJail !== '') {
                    $jails[$currentJail] = [];
                }
            }

            if ($currentJail !== '' && preg_match('/Banned IP list:\s*(.*)/i', $line, $matches)) {
                $ips = array_values(array_filter(
                    array_map('trim', explode(' ', $matches[1])),
                    fn (string $ip): bool => $this->isValidIpAddress($ip)
                ));
                $jails[$currentJail] = $ips;
            }
        }

        return ['jails' => $jails, 'raw' => $rawOutput];
    }

    private function parseRspamdScores(string $rawOutput): array
    {
        $rawOutput = $this->sanitizeSystemOutput($rawOutput);
        $scores = [
            'reject' => 15,
            'add_header' => 6,
            'greylist' => 4,
            'raw' => $rawOutput,
        ];

        if (preg_match('/reject\s*=\s*([0-9.]+);/i', $rawOutput, $matches)) {
            $scores['reject'] = (float) $matches[1];
        }

        if (preg_match('/add_header\s*=\s*([0-9.]+);/i', $rawOutput, $matches)) {
            $scores['add_header'] = (float) $matches[1];
        }

        if (preg_match('/greylist\s*=\s*([0-9.]+);/i', $rawOutput, $matches)) {
            $scores['greylist'] = (float) $matches[1];
        }

        return $scores;
    }

    private function normalizeRspamdScore(mixed $value, string $name): float
    {
        $raw = trim((string) $value);
        if ($raw === '' || !is_numeric($raw)) {
            throw new \InvalidArgumentException('Điểm Rspamd không hợp lệ.');
        }

        $score = (float) $raw;
        if (!is_finite($score) || $score < 0 || $score > 100) {
            throw new \InvalidArgumentException('Điểm Rspamd phải nằm trong khoảng 0 đến 100.');
        }

        return round($score, 2);
    }

    private function normalizeRspamdMapList(string $raw, string $type): string
    {
        if (strlen($raw) > 20000) {
            throw new \InvalidArgumentException('Danh sách Rspamd quá lớn.');
        }

        $entries = [];
        foreach (preg_split('/\R/', str_replace("\0", '', $raw)) ?: [] as $line) {
            $entry = strtolower(trim($line));
            if ($entry === '') {
                continue;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $entry) === 1) {
                throw new \InvalidArgumentException('Danh sách Rspamd chứa ký tự không hợp lệ.');
            }

            if (!$this->isValidRspamdMapEntry($entry, $type)) {
                throw new \InvalidArgumentException('Danh sách Rspamd chứa mục không hợp lệ.');
            }

            $entries[] = $entry;
        }

        $entries = array_values(array_unique($entries));
        if (count($entries) > 1000) {
            throw new \InvalidArgumentException('Danh sách Rspamd chỉ được tối đa 1000 mục.');
        }

        return implode("\n", $entries);
    }

    private function isValidRspamdMapEntry(string $entry, string $type): bool
    {
        if ($type === 'ip') {
            return $this->isValidIpOrCidr($entry);
        }

        return $this->isValidEmailOrDomainEntry($entry);
    }

    private function isValidFail2banJail(string $jail): bool
    {
        return preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $jail) === 1;
    }

    private function isValidIpAddress(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    private function isValidIpOrCidr(string $entry): bool
    {
        if ($this->isValidIpAddress($entry)) {
            return true;
        }

        if (substr_count($entry, '/') !== 1) {
            return false;
        }

        [$ip, $prefix] = explode('/', $entry, 2);
        if (!$this->isValidIpAddress($ip) || !ctype_digit($prefix)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $maxPrefix = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false ? 32 : 128;

        return $prefixLength >= 0 && $prefixLength <= $maxPrefix;
    }

    private function isValidEmailOrDomainEntry(string $entry): bool
    {
        if (strlen($entry) > 254) {
            return false;
        }

        if (str_starts_with($entry, '@')) {
            return $this->isValidDomain(substr($entry, 1));
        }

        return filter_var($entry, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function isValidDomain(string $domain): bool
    {
        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/',
            $domain
        ) === 1;
    }

    private function safeSystemError(\Throwable $exception): string
    {
        $message = trim($this->sanitizeSystemOutput($exception->getMessage()));

        return $message !== '' ? $message : 'System action failed.';
    }

    private function sanitizeSystemOutput(string $output): string
    {
        $output = str_replace("\0", '', $output);
        if (preg_match('//u', $output) !== 1) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $output);
            $output = is_string($converted) ? $converted : '';
        }

        $output = (string) preg_replace('/[^\P{C}\t\r\n]/u', '', $output);

        return $this->redactSensitiveOutput($output);
    }

    private function redactSensitiveOutput(string $output): string
    {
        $patterns = [
            '/((?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)\s*[=:]\s*)([^\s,"\']+)/i',
            '/("(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)"\s*:\s*")([^"]+)(")/i',
            '/(Authorization:\s*(?:Bearer|Basic)\s+)[A-Za-z0-9+\/._~=-]+/i',
        ];

        $redacted = preg_replace($patterns[0], '$1[redacted]', $output);
        $redacted = is_string($redacted) ? $redacted : $output;
        $redacted = preg_replace($patterns[1], '$1[redacted]$3', $redacted);
        $redacted = is_string($redacted) ? $redacted : $output;
        $redacted = preg_replace($patterns[2], '$1[redacted]', $redacted);

        return is_string($redacted) ? $redacted : '[redacted]';
    }

}
