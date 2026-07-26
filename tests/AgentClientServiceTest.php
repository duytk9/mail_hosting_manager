<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Services\AgentClientService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AgentClientServiceTest extends TestCase
{
    public function test_agent_command_uses_argv_and_decodes_json(): void
    {
        $script = $this->writeAgentScript(<<<'PHP'
<?php
$mode = $argv[1] ?? '';
$payload = json_decode(stream_get_contents(STDIN), true);
echo json_encode(['mode' => $mode, 'payload' => $payload], JSON_UNESCAPED_SLASHES);
PHP);

        try {
            $client = new AgentClientService('', '', $script, PHP_BINARY, 5);
            $response = $client->monitorSystem(['action' => 'queue-list']);

            $this->assertSame('monitor-system', $response['mode']);
            $this->assertSame('queue-list', $response['payload']['action']);
        } finally {
            @unlink($script);
        }
    }

    public function test_agent_command_times_out(): void
    {
        $script = $this->writeAgentScript(<<<'PHP'
<?php
sleep(2);
echo json_encode(['ok' => true]);
PHP);

        try {
            $client = new AgentClientService('', '', $script, PHP_BINARY, 1);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Agent execution timed out.');

            $client->monitorSystem(['action' => 'queue-list']);
        } finally {
            @unlink($script);
        }
    }

    public function test_agent_failure_redacts_secrets(): void
    {
        $script = $this->writeAgentScript(<<<'PHP'
<?php
fwrite(STDERR, 'failed password=Secret123 token=abc123 {"private_key":"xyz"}');
exit(1);
PHP);

        try {
            $client = new AgentClientService('', '', $script, PHP_BINARY, 5);

            try {
                $client->monitorSystem(['password' => 'Secret123']);
                $this->fail('Expected agent failure.');
            } catch (RuntimeException $exception) {
                $message = $exception->getMessage();
                $this->assertStringContainsString('password=[redacted]', $message);
                $this->assertStringContainsString('token=[redacted]', $message);
                $this->assertStringContainsString('"private_key":"[redacted]"', $message);
                $this->assertStringNotContainsString('Secret123', $message);
                $this->assertStringNotContainsString('abc123', $message);
            }
        } finally {
            @unlink($script);
        }
    }

    public function test_agent_output_is_capped_to_prevent_memory_pressure(): void
    {
        $script = $this->writeAgentScript(<<<'PHP'
<?php
echo str_repeat('A', 2048);
PHP);

        try {
            $client = new AgentClientService('', '', $script, PHP_BINARY, 5, 1024);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Agent output exceeded maximum size.');

            $client->monitorSystem(['action' => 'queue-list']);
        } finally {
            @unlink($script);
        }
    }

    private function writeAgentScript(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mailpanel-agent-test-');
        $this->assertIsString($path);
        file_put_contents($path, $content);

        return $path;
    }
}
