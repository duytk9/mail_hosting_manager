<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Application;
use MailPanel\Core\Container;
use MailPanel\Core\Request;
use MailPanel\Core\Router;
use PHPUnit\Framework\TestCase;

final class CoreErrorResponseTest extends TestCase
{
    private array $previousServer = [];
    private string $previousErrorLog = '';
    private string $previousLogErrors = '';
    private ?string $errorLogPath = null;

    protected function setUp(): void
    {
        $this->previousServer = $_SERVER;
        $this->previousErrorLog = (string) ini_get('error_log');
        $this->previousLogErrors = (string) ini_get('log_errors');
        $this->errorLogPath = sys_get_temp_dir() . '/mailpanel-core-error-' . bin2hex(random_bytes(4)) . '.log';
        ini_set('error_log', $this->errorLogPath);
        ini_set('log_errors', '1');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->previousServer;
        ini_set('error_log', $this->previousErrorLog);
        ini_set('log_errors', $this->previousLogErrors);
        if ($this->errorLogPath !== null && is_file($this->errorLogPath)) {
            @unlink($this->errorLogPath);
        }

        parent::tearDown();
    }

    public function test_application_html_error_response_uses_utf8_vietnamese_text(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/boom';

        $router = new Router(new Container());
        $router->add('GET', '/boom', static function (Request $request): never {
            throw new \RuntimeException('Unexpected failure password=Secret123');
        });

        $response = (new Application(new Container(), $router))->handleCurrentRequest();

        ob_start();
        $response->send();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('L&#7895;i h&#7879; th&#7889;ng', $output);
        $this->assertStringContainsString('C&#243; l&#7895;i x&#7843;y ra', $output);
        $this->assertStringContainsString('H&#7879; th&#7889;ng &#273;ang g&#7863;p l&#7895;i n&#7897;i b&#7897;.', $output);
        $this->assertStringNotContainsString('Secret123', $output);
        $this->assertStringNotContainsString(hex2bin('4cc3a1c2bb'), $output);
        $this->assertStringNotContainsString('CÃ', $output);
        $this->assertStringNotContainsString('L?i', $output);
    }
}
