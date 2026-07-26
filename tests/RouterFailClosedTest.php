<?php

declare(strict_types=1);

namespace MailPanel\Tests;

use MailPanel\Core\Container;
use MailPanel\Core\Response;
use MailPanel\Core\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * routes/web.php once declared no route meta at all, so the router authorised
 * nothing and every admin page relied on its controller remembering to call
 * guardAuthenticatedPage(). Adding a route and forgetting the guard published it.
 *
 * Router::add() now refuses a route that does not state its access level.
 */
final class RouterFailClosedTest extends TestCase
{
    public function test_route_without_access_level_is_rejected(): void
    {
        $router = new Router(new Container());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exactly one of');

        $router->add('GET', '/admin/new-page', fn () => Response::json([]));
    }

    public function test_route_claiming_both_access_levels_is_rejected(): void
    {
        $router = new Router(new Container());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exactly one of');

        $router->add('GET', '/admin/confused', fn () => Response::json([]), [
            'auth' => true,
            'public' => true,
        ]);
    }

    public function test_explicitly_public_route_is_accepted(): void
    {
        $router = new Router(new Container());
        $router->add('GET', '/admin/login', fn () => Response::json([]), ['public' => true]);

        $this->assertTrue(true, 'Registering an explicitly public route must not throw.');
    }

    public function test_authenticated_route_is_accepted(): void
    {
        $router = new Router(new Container());
        $router->add('GET', '/admin/dashboard', fn () => Response::json([]), ['auth' => true]);

        $this->assertTrue(true, 'Registering an authenticated route must not throw.');
    }

    /**
     * Guards against someone quietly marking an admin page public to dodge a
     * failing auth check.
     */
    public function test_only_login_routes_are_public_in_web_routes(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../routes/web.php');

        preg_match_all(
            "/\\\$router->add\\('(GET|POST|PUT|PATCH|DELETE)', '([^']+)'.*?\\[([^\\]]*)\\]\\);/s",
            $source,
            $matches,
            PREG_SET_ORDER
        );

        $this->assertNotEmpty($matches, 'No routes parsed from routes/web.php');

        $publicPaths = [];
        foreach ($matches as $match) {
            if (str_contains($match[3], "'public' => true")) {
                $publicPaths[] = $match[2];
            }
        }

        $this->assertSame(['/admin/login', '/admin/login'], $publicPaths);
    }

    /**
     * Every admin route must be registered. A page reachable only because it was
     * never added would bypass the router entirely.
     */
    public function test_every_web_route_declares_an_access_level(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../routes/web.php');

        preg_match_all("/\\\$router->add\\([^;]*;/s", $source, $matches);
        $this->assertNotEmpty($matches[0]);

        foreach ($matches[0] as $call) {
            $hasAuth = str_contains($call, "'auth' => true");
            $hasPublic = str_contains($call, "'public' => true");

            $this->assertTrue(
                $hasAuth xor $hasPublic,
                'Route registration declares no single access level: ' . trim($call)
            );
        }
    }
}
