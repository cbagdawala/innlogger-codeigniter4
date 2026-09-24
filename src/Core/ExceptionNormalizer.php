<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

use Throwable;

/**
 * Turns a Throwable into the payload's "exception" object.
 *
 * The trace is PHP's string trace (getTraceAsString()) followed by any
 * "Caused by" previous exceptions. Note: that string trace DOES contain scalar
 * argument values (strings shortened to 15 characters) unless the host sets
 * zend.exception_ignore_args=On in php.ini, which is the recommended production
 * setting. To limit what leaks, the message and the full trace are passed
 * through the masking function (the client uses Redactor::maskString()) before
 * the trace is cut to 64 KB.
 */
final class ExceptionNormalizer
{
    public const MAX_TRACE_BYTES = 65536;

    private const TRUNCATION_MARKER = "\n...[trace truncated]";

    /**
     * @param (callable(string): string)|null $mask applied to the message and the trace
     *
     * @return array{class: string, message: string, file: string, line: int, trace: string}
     */
    public static function normalize(Throwable $exception, ?callable $mask = null): array
    {
        $mask ??= static fn (string $value): string => $value;
        $trace = $exception->getTraceAsString();
        $previous = $exception->getPrevious();
        $depth = 0;

        while ($previous !== null && $depth < 10 && strlen($trace) < self::MAX_TRACE_BYTES) {
            $trace .= sprintf(
                "\n\nCaused by: %s: %s in %s:%d\n%s",
                $previous::class,
                $previous->getMessage(),
                $previous->getFile(),
                $previous->getLine(),
                $previous->getTraceAsString(),
            );
            $previous = $previous->getPrevious();
            $depth++;
        }

        return [
            'class' => $exception::class,
            'message' => $mask($exception->getMessage()),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => self::truncate($mask($trace), self::MAX_TRACE_BYTES),
        ];
    }

    /**
     * Cut a string to at most $maxBytes bytes without splitting a UTF-8 character.
     */
    public static function truncate(string $value, int $maxBytes, string $marker = self::TRUNCATION_MARKER): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        $keep = max(0, $maxBytes - strlen($marker));
        $cut = function_exists('mb_strcut') ? mb_strcut($value, 0, $keep, 'UTF-8') : substr($value, 0, $keep);

        return $cut . $marker;
    }
}
