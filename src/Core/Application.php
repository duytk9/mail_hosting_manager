<?php

declare(strict_types=1);

namespace MailPanel\Core;

use Throwable;

final class Application
{
    public function __construct(
        private readonly Container $container,
        private readonly Router $router
    ) {
    }

    public function handleCurrentRequest(): Response
    {
        $request = Request::capture();

        try {
            return $this->router->dispatch($request);
        } catch (Throwable $exception) {
            error_log("[MailPanel] Unhandled exception: " . get_class($exception) . " - " . $exception->getMessage() . "\n" . $exception->getTraceAsString());

            if ($request->isApi()) {
                return Response::json([
                    'success' => false,
                    'data' => null,
                    'error' => ['message' => 'Internal server error.'],
                    'meta' => [],
                ], 500);
            }

            return Response::html('<!doctype html><html lang="vi"><meta charset="utf-8"><title>L&#7895;i h&#7879; th&#7889;ng</title><body><h1>C&#243; l&#7895;i x&#7843;y ra</h1><p>H&#7879; th&#7889;ng &#273;ang g&#7863;p l&#7895;i n&#7897;i b&#7897;. Vui l&#242;ng th&#7917; l&#7841;i sau ho&#7863;c li&#234;n h&#7879; qu&#7843;n tr&#7883; vi&#234;n.</p></body></html>', 500);
        }
    }

    public function resolve(string $service): mixed
    {
        return $this->container->get($service);
    }
}
