<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Support\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewEscapingTest extends TestCase
{
    private string $viewRoot;

    protected function setUp(): void
    {
        $this->viewRoot = sys_get_temp_dir() . '/mailpanel-view-test-' . bin2hex(random_bytes(6));
        mkdir($this->viewRoot . '/nested', 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewRoot . '/nested/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->viewRoot . '/*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->viewRoot . '/nested');
        @rmdir($this->viewRoot);
    }

    public function test_escape_helper_neutralises_html(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            View::e('<script>alert(1)</script>')
        );
    }

    public function test_escape_helper_neutralises_attribute_breakouts(): void
    {
        $this->assertSame(
            '&quot; onmouseover=&quot;alert(1)',
            View::e('" onmouseover="alert(1)')
        );

        $this->assertSame(
            '&#039; onfocus=&#039;alert(1)',
            View::e("' onfocus='alert(1)")
        );
    }

    public function test_escape_helper_handles_non_string_values(): void
    {
        $this->assertSame('', View::e(null));
        $this->assertSame('', View::e(true));
        $this->assertSame('', View::e(['a']));
        $this->assertSame('42', View::e(42));
        $this->assertSame('1.5', View::e(1.5));
    }

    public function test_json_helper_cannot_close_a_script_element(): void
    {
        $encoded = View::json(['payload' => '</script><script>alert(1)</script>']);

        $this->assertStringNotContainsString('</script>', $encoded);
        $this->assertStringNotContainsString('<', $encoded);
    }

    public function test_templates_receive_an_escape_closure(): void
    {
        file_put_contents($this->viewRoot . '/echo.php', '<?php echo $e($value); ?>');

        $view = new View($this->viewRoot);
        $output = $view->render('echo.php', ['value' => '<b>x</b>']);

        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $output);
    }

    public function test_missing_template_throws(): void
    {
        $view = new View($this->viewRoot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View not found');

        $view->render('does-not-exist.php');
    }

    public function test_template_path_cannot_escape_the_view_directory(): void
    {
        $view = new View($this->viewRoot . '/nested');

        $this->expectException(RuntimeException::class);

        $view->render('../echo.php', ['value' => 'x']);
    }

    /**
     * A template that throws must not leave its partial output in the output
     * buffer stack, or the next response gets fragments of the failed page.
     */
    public function test_failed_render_does_not_leak_output_buffer(): void
    {
        file_put_contents(
            $this->viewRoot . '/boom.php',
            '<?php echo "partial"; throw new \RuntimeException("template failure"); ?>'
        );

        $view = new View($this->viewRoot);
        $depth = ob_get_level();

        try {
            $view->render('boom.php');
            $this->fail('Expected the template exception to propagate.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('template failure', $exception->getMessage());
        }

        $this->assertSame($depth, ob_get_level(), 'Output buffer was left open after a failed render.');
    }
}
