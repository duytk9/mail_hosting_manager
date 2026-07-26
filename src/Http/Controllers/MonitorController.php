<?php

declare(strict_types=1);

namespace MailPanel\Http\Controllers;

use MailPanel\Core\Request;
use MailPanel\Http\Controllers\Traits\AdminWebLayoutTrait;
use MailPanel\Core\Response;
use MailPanel\Security\Actor;
use MailPanel\Security\AuthorizationService;
use MailPanel\Security\SessionManager;
use MailPanel\Services\AgentClientService;
use MailPanel\Support\UiMessage;
use MailPanel\Support\View;
use RuntimeException;

final class MonitorController
{
    use AdminWebLayoutTrait;

    protected function view(): View { return $this->view; }
    protected function sessions(): SessionManager { return $this->sessions; }
    protected function authorization(): AuthorizationService { return $this->authorization; }

    public function __construct(
        private readonly View $view,
        private readonly AgentClientService $agentClient,
        private readonly SessionManager $sessions,
        private readonly AuthorizationService $authorization
    ) {
    }

    public function queueList(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/queue')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view', '/admin/dashboard')) {
            return $redirect;
        }

        try {
            $response = $this->agentClient->monitorSystem(['action' => 'queue-list']);
            $rawOutput = $this->commandStdout($response);
            $items = $this->parseEximQueue($rawOutput);
        } catch (\Exception $e) {
            $items = [];
            $error = UiMessage::exception($e, 'Không thể tải mail queue.');
        }

        return Response::html($this->renderPage('admin/pages/queue.php', [
            'items' => $items,
            'error' => $error ?? null,
        ], 'queue', 'Mail Queue'));
    }

    public function queueAction(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/queue')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view', '/admin/dashboard')) {
            return $redirect;
        }

        $csrfToken = (string) ($request->body['_csrf'] ?? '');
        if (!$this->sessions->verifyCsrf($csrfToken)) {
            $this->sessions->flash('error', 'Lỗi bảo mật (CSRF). Vui lòng thử lại.');
            return Response::redirect('/admin/queue');
        }

        $actionType = (string) ($request->body['action_type'] ?? '');
        $msgId = trim((string) ($request->body['msg_id'] ?? ''));

        if (!in_array($actionType, ['deliver', 'delete', 'view'], true)) {
            $this->sessions->flash('error', 'Invalid action type.');

            return Response::redirect('/admin/queue');
        }

        if (!$this->isValidMessageId($msgId)) {
            $this->sessions->flash('error', 'Message ID không hợp lệ.');

            return Response::redirect('/admin/queue');
        }

        try {
            $response = $this->agentClient->monitorSystem([
                'action' => 'queue-action',
                'action_type' => $actionType,
                'msg_id' => $msgId
            ]);
            $stdout = $this->commandStdout($response);
            
            if ($actionType === 'view') {
                return Response::html($this->renderPage('admin/pages/queue_view.php', [
                    'msgId' => $msgId,
                    'content' => $stdout
                ], 'queue', 'Xem nội dung Email'));
            }
            
            $this->sessions->flash('success', "Thao tác '$actionType' trên email $msgId thành công.");

            return Response::redirect('/admin/queue');
        } catch (\Exception $e) {
            $this->sessions->flash('error', 'Lỗi: ' . $this->safeMonitorError($e));

            return Response::redirect('/admin/queue');
        }
    }

    public function logs(Request $request): Response
    {
        if ($redirect = $this->guardAdminSession('/admin/logs')) {
            return $redirect;
        }

        if ($redirect = $this->guardPermission('super_admins.view', '/admin/dashboard')) {
            return $redirect;
        }

        $service = (string) ($request->query['service'] ?? 'exim');
        $lines = (int) ($request->query['lines'] ?? 100);
        $keyword = $this->normalizeLogKeyword((string) ($request->query['keyword'] ?? ''));
        $lines = max(1, min(5000, $lines));
        
        $validServices = ['nginx', 'exim', 'dovecot', 'rspamd', 'fail2ban'];
        if (!in_array($service, $validServices)) {
            $service = 'exim';
        }

        try {
            $response = $this->agentClient->monitorSystem([
                'action' => 'read-log',
                'service' => $service,
                'lines' => $lines,
                'keyword' => $keyword
            ]);
            $logContent = $this->commandStdout($response);
        } catch (\Exception $e) {
            $logContent = 'Lỗi truy xuất log: ' . $this->safeMonitorError($e);
        }

        return Response::html($this->renderPage('admin/pages/logs.php', [
            'current_service' => $service,
            'log_content' => $logContent,
            'lines' => $lines,
            'keyword' => $keyword,
            'services' => $validServices
        ], 'logs', 'System Logs'));
    }

    private function commandStdout(array $response): string
    {
        $result = is_array($response['result'] ?? null) ? $response['result'] : [];
        $returnCode = (int) ($result['returncode'] ?? $result['exit_code'] ?? 0);
        $stdout = (string) ($result['stdout'] ?? '');
        $stderr = (string) ($result['stderr'] ?? '');

        if ($returnCode !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            throw new RuntimeException($message !== '' ? $this->sanitizeCommandOutput($message) : 'Agent command failed.');
        }

        return $this->sanitizeCommandOutput($stdout);
    }

    /**
     * @return array<int, array{id: string, time: string, size: string, sender: string, status: string, recipients: array<int, string>}>
     */
    private function parseEximQueue(string $rawOutput): array
    {
        $items = [];
        $currentIndex = null;

        foreach (preg_split('/\r\n|\r|\n/', $rawOutput) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match('/\b([A-Za-z0-9]{6,}-[A-Za-z0-9]{6,}-[A-Za-z0-9]{2})\b/', $line, $match, PREG_OFFSET_CAPTURE) === 1) {
                $id = $match[1][0];
                $idOffset = (int) $match[1][1];
                $prefix = trim(substr($line, 0, $idOffset));
                $suffix = trim(substr($line, $idOffset + strlen($id)));
                $prefixParts = preg_split('/\s+/', $prefix) ?: [];
                $time = (string) ($prefixParts[0] ?? '-');
                $size = (string) ($prefixParts[1] ?? '-');
                $sender = '-';
                $status = '';

                if (preg_match('/^<([^>]*)>\s*(.*)$/', $suffix, $senderMatch) === 1) {
                    $sender = $senderMatch[1] !== '' ? $senderMatch[1] : '<>';
                    $status = trim($senderMatch[2] ?? '');
                } elseif ($suffix !== '') {
                    $suffixParts = preg_split('/\s+/', $suffix, 2) ?: [];
                    $sender = (string) ($suffixParts[0] ?? '-');
                    $status = trim((string) ($suffixParts[1] ?? ''));
                }

                $items[] = [
                    'id' => $id,
                    'time' => $time,
                    'size' => $size,
                    'sender' => $sender,
                    'status' => $status,
                    'recipients' => [],
                ];
                $currentIndex = count($items) - 1;
                continue;
            }

            if ($currentIndex === null) {
                continue;
            }

            if (str_contains($trimmed, '***')) {
                $items[$currentIndex]['status'] = trim($items[$currentIndex]['status'] . ' ' . $trimmed);
                continue;
            }

            $items[$currentIndex]['recipients'][] = $trimmed;
        }

        return $items;
    }

    private function sanitizeCommandOutput(string $output): string
    {
        $output = str_replace("\0", '', $output);
        if (preg_match('//u', $output) !== 1) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $output);
            $output = is_string($converted) ? $converted : '';
        }

        $output = (string) preg_replace('/[^\P{C}\t\r\n]/u', '', $output);

        return $this->redactSensitiveOutput($output);
    }

    private function safeMonitorError(\Throwable $exception): string
    {
        $message = trim($this->sanitizeCommandOutput($exception->getMessage()));

        return $message !== '' ? $message : 'Agent command failed.';
    }

    private function isValidMessageId(string $messageId): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{5,31}$/', $messageId) === 1;
    }

    private function normalizeLogKeyword(string $keyword): string
    {
        $keyword = trim(str_replace("\0", '', $keyword));
        $keyword = (string) preg_replace('/[^\P{C}\t ]/u', '', $keyword);

        if (mb_strlen($keyword) > 160) {
            $keyword = mb_substr($keyword, 0, 160);
        }

        return $keyword;
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
