<?php

declare(strict_types=1);

namespace MailPanel\Services;

use RuntimeException;

final class AgentClientService
{
    public function __construct(
        private readonly string $agentBinary = '/usr/local/bin/mailpanel-agent',
        private readonly string $systemUser = 'mailpanel-agent',
        private readonly string $webAgentBinary = '/usr/local/bin/mailpanel-web-agent',
        private readonly string $sudoBinary = 'sudo',
        private readonly int $timeoutSeconds = 60,
        private readonly int $maxOutputBytes = 1048576,
    ) {
    }

    public function executePlan(array $payload): array
    {
        return $this->run('execute-plan', $payload);
    }

    public function applyConfig(array $payload): array
    {
        return $this->run('apply-config', $payload);
    }

    public function manageSuperAdmin(array $payload): array
    {
        return $this->run('manage-super-admin', $payload);
    }

    public function manageAcmeTls(array $payload): array
    {
        return $this->run('manage-acme-tls', $payload);
    }

    public function monitorSystem(array $payload): array
    {
        return $this->run('monitor-system', $payload);
    }

    public function securitySystem(array $payload): array
    {
        return $this->run('security-system', $payload);
    }

    private function run(string $mode, array $payload): array
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode agent payload.');
        }

        $command = [$this->sudoBinary, $this->webAgentBinary, $mode];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the mailpanel agent process.');
        }

        fwrite($pipes[0], $encoded);
        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + max(1, $this->timeoutSeconds);

        try {
            while (true) {
                $status = proc_get_status($process);
                $running = (bool) ($status['running'] ?? false);

                if ($running && microtime(true) >= $deadline) {
                    proc_terminate($process);
                    foreach ([1, 2] as $pipeIndex) {
                        if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                            fclose($pipes[$pipeIndex]);
                        }
                    }
                    proc_close($process);

                    throw new RuntimeException('Agent execution timed out.');
                }

                $this->drainAvailablePipe($pipes[1], $stdout);
                $this->drainAvailablePipe($pipes[2], $stderr);

                if (!$running) {
                    break;
                }

                usleep(10000);
            }

            $this->drainRemainingPipe($pipes[1], $stdout);
            fclose($pipes[1]);
            $this->drainRemainingPipe($pipes[2], $stderr);
            fclose($pipes[2]);
        } catch (RuntimeException $exception) {
            if (is_resource($process)) {
                proc_terminate($process);
            }
            foreach ([1, 2] as $pipeIndex) {
                if (isset($pipes[$pipeIndex]) && is_resource($pipes[$pipeIndex])) {
                    fclose($pipes[$pipeIndex]);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }

            throw $exception;
        }

        $status = proc_close($process);
        if ($status !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : trim($stdout);
            throw new RuntimeException('Agent execution failed: ' . $this->redactSensitiveOutput($message));
        }

        $decoded = json_decode($stdout, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Agent returned invalid JSON.');
        }

        return $decoded;
    }

    public function renameDomain(array $payload): array
    {
        return $this->run('manage-domain', $payload);
    }

    public function manageMailStorage(array $payload): array
    {
        return $this->run('manage-mail-storage', $payload);
    }

    private function redactSensitiveOutput(string $message): string
    {
        $patterns = [
            '/((?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)\s*[=:]\s*)([^\s,"\']+)/i',
            '/("(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password)"\s*:\s*")([^"]+)(")/i',
        ];

        $redacted = preg_replace($patterns[0], '$1[redacted]', $message);
        $redacted = is_string($redacted) ? $redacted : $message;
        $redacted = preg_replace($patterns[1], '$1[redacted]$3', $redacted);

        return is_string($redacted) ? $redacted : 'Agent command failed.';
    }

    private function drainAvailablePipe(mixed $pipe, string &$buffer): void
    {
        if (!is_resource($pipe)) {
            return;
        }

        $read = [$pipe];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 0, 0);
        if ($ready === false || $ready < 1) {
            return;
        }

        $this->appendOutput($buffer, stream_get_contents($pipe) ?: '');
    }

    private function drainRemainingPipe(mixed $pipe, string &$buffer): void
    {
        if (!is_resource($pipe)) {
            return;
        }

        while (!feof($pipe)) {
            $chunk = fread($pipe, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $this->appendOutput($buffer, $chunk);
        }
    }

    private function appendOutput(string &$buffer, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        $limit = max(1024, $this->maxOutputBytes);
        if (strlen($buffer) + strlen($chunk) > $limit) {
            throw new RuntimeException('Agent output exceeded maximum size.');
        }

        $buffer .= $chunk;
    }
}
