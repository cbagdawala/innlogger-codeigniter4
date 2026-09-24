<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

use BackedEnum;
use Closure;
use DateTimeInterface;
use JsonSerializable;
use LogicException;
use stdClass;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Makes arbitrary context JSON-safe and removes secrets from it (spec 09-Security §5).
 *
 * - Values under sensitive keys become "[REDACTED]" at any depth. Matching is
 *   case-insensitive and treats "-", "_" and " " alike, so "X-Api-Secret" matches "api_secret".
 * - Every string value (and the message, exception message and trace, via
 *   maskString()) is masked: Bearer/Basic/Digest credentials, literal
 *   "ils_..." InnLogger secrets, the configured API secret and custom regex rules.
 * - Objects are never dumped through their private state: JsonSerializable,
 *   toArray(), stdClass and Stringable use their public form; anything else
 *   becomes "[object Foo]".
 */
final class Redactor
{
    public const REPLACEMENT = '[REDACTED]';

    /** Always redacted. A superset of spec 09 §5, matching the Laravel SDK. */
    public const DEFAULT_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'refresh_token',
        'id_token',
        'authorization',
        'proxy_authorization',
        'cookie',
        'set_cookie',
        'card_number',
        'cvv',
        'cvc',
        'secret',
        'api_secret',
        'client_secret',
        'api_key',
        'private_key',
        'csrf_token',
        'xsrf_token',
        'x_xsrf_token',
        '_token',
        'x_innlogger_signature',
    ];

    /** Built-in masking rules, applied to every string. */
    public const BUILT_IN_PATTERNS = [
        '/\b(Bearer|Basic|Digest)\s+[A-Za-z0-9\-._~+\/]+=*/i' => '$1 ' . self::REPLACEMENT,
        '/\bils_[A-Za-z0-9]{8,}/' => self::REPLACEMENT,
    ];

    /** Literal secrets shorter than this are not masked (too likely to hit ordinary text). */
    public const MIN_LITERAL_LENGTH = 8;

    private const MAX_DEPTH = 10;

    /** @var array<string, true> */
    private array $keys = [];

    /** @var array<string, string> */
    private array $patterns;

    /** @var Closure(): list<string> literals kept in a closure so dumps can't show them */
    private readonly Closure $literals;

    /**
     * @param list<string>          $extraKeys
     * @param array<string, string> $maskPatterns   regex => replacement (added to BUILT_IN_PATTERNS)
     * @param list<string>          $literalSecrets exact strings that must never be sent (e.g. the API secret)
     */
    public function __construct(array $extraKeys = [], array $maskPatterns = [], #[\SensitiveParameter] array $literalSecrets = [])
    {
        foreach (array_merge(self::DEFAULT_KEYS, $extraKeys) as $key) {
            $normalized = self::normalizeKey((string) $key);

            if ($normalized !== '') {
                $this->keys[$normalized] = true;
            }
        }

        $this->patterns = self::BUILT_IN_PATTERNS + $maskPatterns;

        $literals = array_values(array_filter(
            array_map('strval', $literalSecrets),
            static fn (string $secret): bool => strlen($secret) >= self::MIN_LITERAL_LENGTH,
        ));
        $this->literals = static fn (): array => $literals;
    }

    public function isSensitive(string|int $key): bool
    {
        return is_string($key) && isset($this->keys[self::normalizeKey($key)]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->keys);
    }

    /**
     * Mask credentials and secrets inside a free-text value.
     */
    public function maskString(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (($this->literals)() as $literal) {
            if (str_contains($value, $literal)) {
                $value = str_replace($literal, self::REPLACEMENT, $value);
            }
        }

        foreach ($this->patterns as $pattern => $replacement) {
            $masked = @preg_replace($pattern, $replacement, $value);

            if (is_string($masked)) {
                $value = $masked;
            }
        }

        return $value;
    }

    /**
     * Redact and normalise a value for JSON encoding.
     */
    public function redact(mixed $value, int $depth = 0): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? $value : (string) $value;
        }

        if (is_string($value)) {
            return $this->maskString($value);
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[max depth]';
        }

        if (is_array($value)) {
            $out = [];

            foreach ($value as $key => $item) {
                $out[$key] = $this->isSensitive($key) ? self::REPLACEMENT : $this->redact($item, $depth + 1);
            }

            return $out;
        }

        if (is_object($value)) {
            return $this->object($value, $depth);
        }

        return '[' . get_debug_type($value) . ']';
    }

    /**
     * Redact sensitive query-string parameters in a URL or path, then mask it.
     */
    public function redactUrl(string $url): string
    {
        $fragment = '';
        $hashAt = strpos($url, '#');

        if ($hashAt !== false) {
            $fragment = substr($url, $hashAt);
            $url = substr($url, 0, $hashAt);
        }

        $queryAt = strpos($url, '?');

        if ($queryAt === false) {
            return $this->maskString($url . $fragment);
        }

        $pairs = explode('&', substr($url, $queryAt + 1));

        foreach ($pairs as $i => $pair) {
            $name = urldecode(explode('=', $pair, 2)[0]);
            preg_match_all('/[^\[\]]+/', $name, $segments);

            foreach ($segments[0] as $segment) {
                if ($this->isSensitive($segment)) {
                    $pairs[$i] = explode('=', $pair, 2)[0] . '=' . rawurlencode(self::REPLACEMENT);
                    break;
                }
            }
        }

        return $this->maskString(substr($url, 0, $queryAt + 1) . implode('&', $pairs) . $fragment);
    }

    public function __debugInfo(): array
    {
        return ['keys' => $this->keys(), 'patterns' => array_keys($this->patterns), 'literals' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('An InnLogger Redactor cannot be serialized.');
    }

    private function object(object $value, int $depth): mixed
    {
        try {
            if ($value instanceof Throwable) {
                return [
                    'class' => $value::class,
                    'message' => $this->maskString($value->getMessage()),
                    'file' => $value->getFile(),
                    'line' => $value->getLine(),
                ];
            }

            if ($value instanceof DateTimeInterface) {
                return $value->format(DateTimeInterface::ATOM);
            }

            if ($value instanceof BackedEnum) {
                return $this->redact($value->value, $depth + 1);
            }

            if ($value instanceof UnitEnum) {
                return $value->name;
            }

            if ($value instanceof JsonSerializable) {
                return $this->redact($value->jsonSerialize(), $depth + 1);
            }

            if (method_exists($value, 'toArray')) {
                $array = $value->toArray();

                if (is_array($array)) {
                    return $this->redact($array, $depth + 1);
                }
            }

            if ($value instanceof stdClass) {
                return $this->redact(get_object_vars($value), $depth + 1);
            }

            if ($value instanceof Stringable) {
                return $this->maskString((string) $value);
            }
        } catch (Throwable) {
            // A misbehaving object must not break logging.
        }

        return '[object ' . $value::class . ']';
    }

    private static function normalizeKey(string $key): string
    {
        return str_replace(['-', ' '], '_', strtolower(trim($key)));
    }
}
