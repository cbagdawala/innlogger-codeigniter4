<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

use Closure;

/**
 * Framework-agnostic, immutable client settings.
 *
 * The CodeIgniter 4 layer builds this from Config\InnLogger; anything else
 * (tests, a plain PHP script) can build it with fromArray().
 *
 * The API secret is kept inside a closure and read through apiSecret(), so
 * print_r(), var_dump(), var_export(), json_encode() and serialize() of a
 * Settings object (or anything holding one) never contain it.
 */
final class Settings
{
    public const MAX_TIMEOUT = 30.0;
    public const MAX_RETRIES = 3;
    public const DEFAULT_RETRIES = 1;
    public const DEFAULT_CATEGORY = 'application';

    public readonly bool $enabled;
    public readonly string $url;
    public readonly string $apiKey;
    public readonly int $threshold;
    public readonly float $timeout;
    public readonly float $connectTimeout;
    public readonly string $environment;
    public readonly string $application;
    public readonly string $appVersion;
    public readonly string $category;
    public readonly int $retries;
    public readonly int $retryDelayMs;
    public readonly bool $allowInsecure;
    public readonly bool $debug;
    /** @var list<string> extra keys to redact on top of Redactor::DEFAULT_KEYS */
    public readonly array $redactFields;
    /** @var array<string, string> regex => replacement, applied to every string value */
    public readonly array $maskPatterns;
    public readonly ?string $hostname;

    /** @var Closure(): string */
    private readonly Closure $secret;

    /**
     * @param list<string>                      $redactFields
     * @param array<array-key, string>|list<string> $maskPatterns regex => replacement, or a list of regexes
     */
    public function __construct(
        bool $enabled = true,
        string $url = '',
        string $apiKey = '',
        #[\SensitiveParameter]
        string $apiSecret = '',
        int $threshold = Severity::ERROR,
        float $timeout = 2.0,
        float $connectTimeout = 1.0,
        string $environment = 'production',
        string $application = '',
        string $appVersion = '',
        int $retries = self::DEFAULT_RETRIES,
        int $retryDelayMs = 100,
        bool $allowInsecure = false,
        bool $debug = false,
        array $redactFields = [],
        ?string $hostname = null,
        string $category = self::DEFAULT_CATEGORY,
        array $maskPatterns = [],
    ) {
        $this->enabled = $enabled;
        $this->url = rtrim(trim($url), '/');
        $this->apiKey = trim($apiKey);
        $secret = trim($apiSecret);
        $this->secret = static fn (): string => $secret;
        $this->threshold = Severity::normalizeThreshold($threshold);
        $this->timeout = self::clampTimeout($timeout, 2.0);
        $this->connectTimeout = self::clampTimeout($connectTimeout, 1.0);
        $this->environment = $environment !== '' ? $environment : 'production';
        $this->application = $application;
        $this->appVersion = $appVersion;
        $category = trim($category);
        $this->category = $category !== '' ? $category : self::DEFAULT_CATEGORY;
        $this->retries = max(0, min(self::MAX_RETRIES, $retries));
        $this->retryDelayMs = max(0, min(1000, $retryDelayMs));
        $this->allowInsecure = $allowInsecure;
        $this->debug = $debug;
        $this->redactFields = array_values(array_filter(
            array_map(static fn ($key): string => trim((string) $key), $redactFields),
            static fn (string $key): bool => $key !== '',
        ));
        $this->maskPatterns = self::validPatterns($maskPatterns);
        $this->hostname = $hostname;
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(#[\SensitiveParameter] array $values): self
    {
        $redact = $values['redact_fields'] ?? [];

        if (is_string($redact)) {
            $redact = explode(',', $redact);
        }

        $patterns = $values['mask_patterns'] ?? [];

        if (is_string($patterns)) {
            $patterns = self::parsePatterns($patterns);
        }

        return new self(
            enabled: self::bool($values['enabled'] ?? true),
            url: (string) ($values['url'] ?? ''),
            apiKey: (string) ($values['api_key'] ?? ''),
            apiSecret: (string) ($values['api_secret'] ?? ''),
            threshold: Severity::normalizeThreshold($values['log_level'] ?? Severity::ERROR),
            timeout: (float) ($values['timeout'] ?? 2.0),
            connectTimeout: (float) ($values['connect_timeout'] ?? 1.0),
            environment: (string) ($values['environment'] ?? ''),
            application: (string) ($values['application'] ?? ''),
            appVersion: (string) ($values['app_version'] ?? ''),
            retries: (int) ($values['retries'] ?? self::DEFAULT_RETRIES),
            retryDelayMs: (int) ($values['retry_delay_ms'] ?? 100),
            allowInsecure: self::bool($values['allow_insecure'] ?? false),
            debug: self::bool($values['debug'] ?? false),
            redactFields: (array) $redact,
            hostname: isset($values['hostname']) ? (string) $values['hostname'] : null,
            category: (string) ($values['category'] ?? self::DEFAULT_CATEGORY),
            maskPatterns: (array) $patterns,
        );
    }

    public function apiSecret(): string
    {
        return ($this->secret)();
    }

    public function hasApiSecret(): bool
    {
        return $this->apiSecret() !== '';
    }

    /**
     * Why the settings can't be used to send, or null when they can.
     */
    public function invalidReason(): ?string
    {
        if ($this->url === '') {
            return 'INNLOGGER_URL is not set';
        }

        $scheme = strtolower((string) parse_url($this->url, PHP_URL_SCHEME));
        $host = (string) parse_url($this->url, PHP_URL_HOST);

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return 'INNLOGGER_URL is not a valid http(s) URL';
        }

        if ($scheme !== 'https' && ! $this->allowInsecure) {
            return 'INNLOGGER_URL must use https (set INNLOGGER_ALLOW_INSECURE=true for local development only)';
        }

        if ($this->apiKey === '') {
            return 'INNLOGGER_API_KEY is not set';
        }

        if (! $this->hasApiSecret()) {
            return 'INNLOGGER_API_SECRET is not set';
        }

        return null;
    }

    public function isValid(): bool
    {
        return $this->invalidReason() === null;
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value, " \t\n\r\0\x0B'\"")), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }

    /**
     * Parse INNLOGGER_MASK_PATTERNS: a JSON list of regexes or a JSON object of regex => replacement.
     *
     * @return array<array-key, string>
     */
    public static function parsePatterns(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return array_filter($decoded, 'is_string');
        }

        // A single regex.
        return [$value];
    }

    /**
     * A copy safe to print: the secret is never included.
     *
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return [
            'enabled' => $this->enabled,
            'url' => $this->url,
            'api_key' => $this->apiKey === '' ? '(not set)' : substr($this->apiKey, 0, 8) . '…',
            'api_secret' => $this->hasApiSecret() ? '(set)' : '(not set)',
            'log_level' => $this->threshold . ' (' . Severity::name($this->threshold) . ')',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'environment' => $this->environment,
            'application' => $this->application,
            'app_version' => $this->appVersion,
            'category' => $this->category,
            'retries' => $this->retries,
            'mask_patterns' => count($this->maskPatterns),
            'allow_insecure' => $this->allowInsecure,
            'debug' => $this->debug,
        ];
    }

    public function __debugInfo(): array
    {
        return $this->describe();
    }

    /**
     * Serialising keeps everything except the secret; an unserialised copy has no secret.
     */
    public function __serialize(): array
    {
        return [
            'enabled' => $this->enabled,
            'url' => $this->url,
            'api_key' => $this->apiKey,
            'log_level' => $this->threshold,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'environment' => $this->environment,
            'application' => $this->application,
            'app_version' => $this->appVersion,
            'category' => $this->category,
            'retries' => $this->retries,
            'retry_delay_ms' => $this->retryDelayMs,
            'allow_insecure' => $this->allowInsecure,
            'debug' => $this->debug,
            'redact_fields' => $this->redactFields,
            'mask_patterns' => $this->maskPatterns,
            'hostname' => $this->hostname,
        ];
    }

    public function __unserialize(array $data): void
    {
        $copy = self::fromArray(['api_secret' => ''] + $data);

        foreach (get_object_vars($copy) as $property => $value) {
            $this->{$property} = $value;
        }
    }

    private static function clampTimeout(float $value, float $default): float
    {
        if (! is_finite($value) || $value <= 0) {
            return $default;
        }

        return max(0.1, min(self::MAX_TIMEOUT, $value));
    }

    /**
     * Keep only compilable regexes, as regex => replacement.
     *
     * @param array<array-key, mixed> $patterns
     *
     * @return array<string, string>
     */
    private static function validPatterns(array $patterns): array
    {
        $valid = [];

        foreach ($patterns as $key => $value) {
            [$regex, $replacement] = is_string($key) ? [$key, (string) $value] : [(string) $value, Redactor::REPLACEMENT];

            if ($regex !== '' && @preg_match($regex, '') !== false) {
                $valid[$regex] = $replacement;
            }
        }

        return $valid;
    }
}
