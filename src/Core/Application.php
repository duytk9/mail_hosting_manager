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

            $debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

            if ($request->isApi()) {
                $payload = [
                    'success' => false,
                    'data' => null,
                    'error' => ['message' => $debug ? $exception->getMessage() : 'Internal server error.'],
                    'meta' => [],
                ];

                if ($debug) {
                    $payload['error']['exception'] = get_class($exception);
                    $payload['error']['file'] = $exception->getFile() . ':' . $exception->getLine();
                    $payload['error']['trace'] = array_slice(explode("\n", $exception->getTraceAsString()), 0, 20);
                }

                return Response::json($payload, 500);
            }

            if ($debug) {
                $safeClass = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
                $safeMessage = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
                $safeFile = htmlspecialchars($exception->getFile() . ':' . $exception->getLine(), ENT_QUOTES, 'UTF-8');
                $safeTrace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8');

                return Response::html(
                    '<!doctype html><html lang="vi"><meta charset="utf-8"><title>Debug: L&#7895;i h&#7879; th&#7889;ng</title>'
                    . '<body style="font-family:monospace;max-width:900px;margin:2em auto;padding:0 1em">'
                    . '<h1 style="color:#c00">&#9888; ' . $safeClass . '</h1>'
                    . '<p><strong>Message:</strong> ' . $safeMessage . '</p>'
                    . '<p><strong>File:</strong> ' . $safeFile . '</p>'
                    . '<h2>Stack Trace</h2>'
                    . '<pre style="background:#f5f5f5;padding:1em;overflow:auto;border:1px solid #ddd;font-size:13px">' . $safeTrace . '</pre>'
                    . '<hr><p style="color:#999">APP_DEBUG=true &mdash; T&#7855;t ch&#7871; &#273;&#7897; debug b&#7857;ng APP_DEBUG=false trong .env tr&#432;&#7899;c khi &#273;&#432;a l&ecirc;n production.</p>'
                    . '</body></html>',
                    500
                );
            }

            return Response::html('<!doctype html><html lang="vi"><meta charset="utf-8"><title>L&#7895;i h&#7879; th&#7889;ng</title><body><h1>C&#243; l&#7895;i x&#7843;y ra</h1><p>H&#7879; th&#7889;ng &#273;ang g&#7863;p l&#7895;i n&#7897;i b&#7897;. Vui l&#242;ng th&#7917; l&#7841;i sau ho&#7863;c li&#234;n h&#7879; qu&#7843;n tr&#7883; vi&#234;n.</p></body></html>', 500);
        }
    }

    public function resolve(string $service): mixed
    {
        return $this->container->get($service);
    }
}

