<?php

declare(strict_types=1);

namespace MailPanel\Support;

use MailPanel\Core\Response;
use InvalidArgumentException;
use Throwable;

final class ApiResponse
{
    public static function success(mixed $data = null, array $meta = [], int $status = 200): Response
    {
        return Response::json([
            'success' => true,
            'data' => $data,
            'error' => null,
            'meta' => $meta,
        ], $status);
    }

    public static function error(string $message, int $status = 422, array $meta = []): Response
    {
        return Response::json([
            'success' => false,
            'data' => null,
            'error' => ['message' => self::safeText($message)],
            'meta' => $meta,
        ], $status);
    }

    /**
     * @param array<int, class-string<Throwable>> $publicExceptions
     */
    public static function exception(
        Throwable $exception,
        int $status = 422,
        string $fallbackMessage = 'Request failed.',
        array $publicExceptions = [InvalidArgumentException::class]
    ): Response {
        $message = $fallbackMessage;

        foreach ($publicExceptions as $publicException) {
            if (get_class($exception) === $publicException) {
                $message = $exception->getMessage();
                break;
            }
        }

        return self::error($message, $status);
    }

    public static function safeText(string $message): string
    {
        $patterns = [
            '/(Authorization:\s*(?:Bearer|Basic)\s+)[A-Za-z0-9+\/._~=-]+/i',
            '/((?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password|otp|login[_-]?key)\s*[=:]\s*)([^\s,"\']+)/i',
            '/("(?:password|passwd|token|secret|private[_-]?key|api[_-]?key|db[_-]?password|authorization|otp|login[_-]?key)"\s*:\s*")([^"]+)(")/i',
            '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
        ];

        $redacted = preg_replace($patterns[0], '$1[REDACTED]', $message);
        $redacted = is_string($redacted) ? $redacted : $message;
        $redacted = preg_replace($patterns[1], '$1[REDACTED]', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
        $redacted = preg_replace($patterns[2], '$1[REDACTED]$3', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
        $redacted = preg_replace($patterns[3], '[REDACTED_PRIVATE_KEY]', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';
        $redacted = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $redacted);
        $redacted = is_string($redacted) ? $redacted : '[REDACTED]';

        return substr($redacted, 0, 1000);
    }
}
