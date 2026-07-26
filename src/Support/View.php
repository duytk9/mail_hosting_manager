<?php

declare(strict_types=1);

namespace MailPanel\Support;

use RuntimeException;

final class View
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * Render a template.
     *
     * Templates are plain PHP and are NOT auto-escaped. Every value interpolated
     * into markup must go through View::e() (available inside templates as the
     * `$e` closure) or htmlspecialchars(). Escaping only where a value "looks
     * untrusted" is how XSS gets in — escape on output, always.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $path = $this->resolvePath($template);

        // Available to every template as $e('...'), so escaping is always shorter
        // to write than not escaping.
        $data['e'] = static fn (mixed $value): string => self::e($value);

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $path;
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        return (string) ob_get_clean();
    }

    /**
     * Escape a value for interpolation into HTML text or a quoted attribute.
     */
    public static function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        if (is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a value for use inside a JSON blob embedded in a <script> block.
     * Prevents a value containing "</script>" from closing the element early.
     */
    public static function json(mixed $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return is_string($encoded) ? $encoded : 'null';
    }

    /**
     * Templates are referenced by hardcoded relative paths, never by user input.
     * The containment check makes that assumption explicit rather than implicit.
     */
    private function resolvePath(string $template): string
    {
        $template = ltrim($template, '/');

        if ($template === '' || str_contains($template, "\0")) {
            throw new RuntimeException('Invalid view name.');
        }

        $path = $this->basePath . '/' . $template;
        $real = realpath($path);
        $base = realpath($this->basePath);

        if ($real === false || $base === false || !is_file($real)) {
            throw new RuntimeException('View not found: ' . $template);
        }

        if ($real !== $base && !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('View path escapes the view directory: ' . $template);
        }

        return $real;
    }
}
