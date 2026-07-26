<?php

declare(strict_types=1);

namespace MailPanel\Support;

use InvalidArgumentException;
use Throwable;

final class UiMessage
{
    public static function exception(Throwable $exception, string $fallback = 'Có lỗi xảy ra. Vui lòng thử lại hoặc kiểm tra log hệ thống.'): string
    {
        $message = get_class($exception) === InvalidArgumentException::class
            ? $exception->getMessage()
            : $fallback;

        return ApiResponse::safeText($message);
    }
}
