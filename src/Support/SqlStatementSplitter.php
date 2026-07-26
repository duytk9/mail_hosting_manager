<?php

declare(strict_types=1);

namespace MailPanel\Support;

/**
 * Splits a .sql file into individual executable statements.
 *
 * PDO::exec() on a whole multi-statement file gives no error isolation: a failure
 * halfway through leaves the schema half-migrated with no indication of where.
 * Splitting lets the migration runner report the exact failing statement and
 * decide per-statement whether an error is benign (object already exists).
 *
 * Handles: line comments (-- and #), block comments, single/double quoted strings,
 * backtick identifiers, backslash escapes, and DELIMITER changes (for triggers).
 */
final class SqlStatementSplitter
{
    /**
     * @return array<int, string> Non-empty, trimmed statements without trailing delimiter.
     */
    public static function split(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);

        $statements = [];
        $buffer = '';
        $delimiter = ';';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $rest = substr($sql, $i);

            // DELIMITER directive (must be at start of a line)
            if (
                ($i === 0 || $sql[$i - 1] === "\n")
                && preg_match('/\ADELIMITER[ \t]+(\S+)[ \t]*(?:\n|\z)/i', $rest, $m) === 1
            ) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                    $buffer = '';
                }
                $delimiter = $m[1];
                $i += strlen($m[0]);
                continue;
            }

            // Line comments
            if ($char === '#' || ($char === '-' && substr($sql, $i, 3) === '-- ') || substr($sql, $i, 3) === "--\n") {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline + 1;
                $buffer .= "\n";
                continue;
            }

            // Block comment
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                continue;
            }

            // Quoted literals / identifiers - copy verbatim
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                $i++;
                while ($i < $length) {
                    $inner = $sql[$i];
                    if ($inner === '\\' && $quote !== '`' && $i + 1 < $length) {
                        $buffer .= $inner . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    // Doubled quote is an escaped quote, not a terminator
                    if ($inner === $quote && ($sql[$i + 1] ?? '') === $quote) {
                        $buffer .= $inner . $inner;
                        $i += 2;
                        continue;
                    }
                    $buffer .= $inner;
                    $i++;
                    if ($inner === $quote) {
                        break;
                    }
                }
                continue;
            }

            // Statement terminator
            if (substr($sql, $i, strlen($delimiter)) === $delimiter) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                $i += strlen($delimiter);
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }

        return array_values(array_filter(
            $statements,
            static fn (string $statement): bool => trim($statement) !== ''
        ));
    }
}
