<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

/**
 * The InnLogger severity scale (spec 00-README "Core severity scale").
 *
 * Lower numbers are more severe. A threshold sends every event whose level is
 * equal to or more severe than the threshold; threshold 0 sends nothing.
 */
final class Severity
{
    public const OFF = 0;
    public const CRITICAL = 1;
    public const ERROR = 2;
    public const WARNING = 3;
    public const NOTICE = 4;
    public const INFO = 5;
    public const DEBUG = 6;
    public const TRACE = 7;

    private const NAMES = [
        self::OFF => 'OFF',
        self::CRITICAL => 'CRITICAL',
        self::ERROR => 'ERROR',
        self::WARNING => 'WARNING',
        self::NOTICE => 'NOTICE',
        self::INFO => 'INFO',
        self::DEBUG => 'DEBUG',
        self::TRACE => 'TRACE',
    ];

    /**
     * PSR-3 / CodeIgniter 4 logger level names mapped onto the InnLogger scale.
     */
    private const ALIASES = [
        'emergency' => self::CRITICAL,
        'alert' => self::CRITICAL,
        'critical' => self::CRITICAL,
        'error' => self::ERROR,
        'warning' => self::WARNING,
        'notice' => self::NOTICE,
        'info' => self::INFO,
        'debug' => self::DEBUG,
        'trace' => self::TRACE,
        'off' => self::OFF,
    ];

    private function __construct()
    {
    }

    public static function name(int $level): string
    {
        return self::NAMES[$level] ?? 'UNKNOWN';
    }

    /**
     * True for a level an event may carry (1-7).
     */
    public static function isEventLevel(int $level): bool
    {
        return $level >= self::CRITICAL && $level <= self::TRACE;
    }

    /**
     * Resolve an int, numeric string or level name ("error", "ERROR", PSR-3 names) to a level.
     */
    public static function fromMixed(int|string $level): ?int
    {
        if (is_int($level)) {
            return array_key_exists($level, self::NAMES) ? $level : null;
        }

        $level = strtolower(trim($level));

        if ($level !== '' && ctype_digit($level)) {
            return self::fromMixed((int) $level);
        }

        return self::ALIASES[$level] ?? null;
    }

    /**
     * Normalise a configured threshold into 0-7 (out-of-range values are clamped).
     */
    public static function normalizeThreshold(int|string|null $threshold): int
    {
        if ($threshold === null || $threshold === '') {
            return self::OFF;
        }

        $resolved = self::fromMixed($threshold);

        if ($resolved !== null) {
            return $resolved;
        }

        if (is_numeric($threshold)) {
            return max(self::OFF, min(self::TRACE, (int) $threshold));
        }

        return self::OFF;
    }

    /**
     * The transmission rule: send when threshold > 0 and level <= threshold.
     */
    public static function shouldSend(int $level, int $threshold): bool
    {
        return $threshold > self::OFF && self::isEventLevel($level) && $level <= $threshold;
    }
}
