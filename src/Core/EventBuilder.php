<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Builds the /api/v1/logs payload (spec 03-REST-API §2) and serialises it.
 *
 * Context conventions (PSR-3 style):
 *  - $context['exception'] holding a Throwable becomes the payload's "exception" object;
 *  - $context['category'] holding a string becomes the payload's "category".
 * Both keys are removed from "context"; everything else is redacted and kept.
 * "category" is always sent: the configured default ("application") or
 * "exception" for Throwables when nothing more specific is given.
 * The message, exception message and trace are masked (Redactor::maskString())
 * before encoding, so they are masked before they are signed.
 */
final class EventBuilder
{
    public const SDK_NAME = 'innlogger-codeigniter4';
    public const SDK_VERSION = '1.0.0';

    public const MAX_MESSAGE_BYTES = 65536;
    public const MAX_SECTION_BYTES = 65536;
    public const MAX_CATEGORY_BYTES = 100;
    public const MAX_BODY_BYTES = 262144;

    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private const REQUEST_FIELDS = ['url', 'http_method', 'http_status', 'request_id', 'user_id'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Redactor $redactor,
    ) {
    }

    /**
     * @param array<array-key, mixed> $context   caller context (redacted here)
     * @param array<string, mixed>    $request   fields from a ContextProviderInterface
     * @param array<string, mixed>    $overrides top-level payload fields set by an integration
     *                                           (http_status, category, file, line)
     * @param array{file?: string, line?: int}|null $site where the log call was made
     *
     * @return array<string, mixed>
     */
    public function build(
        int $level,
        string $message,
        array $context = [],
        ?Throwable $exception = null,
        array $request = [],
        array $overrides = [],
        ?array $site = null,
        ?string $eventId = null,
    ): array {
        if ($exception === null && ($context['exception'] ?? null) instanceof Throwable) {
            $exception = $context['exception'];
        }

        if (($context['exception'] ?? null) instanceof Throwable) {
            unset($context['exception']);
        }

        $category = null;

        if (isset($context['category']) && is_string($context['category'])) {
            $category = $context['category'];
            unset($context['category']);
        }

        $category = isset($overrides['category']) && is_string($overrides['category']) ? $overrides['category'] : $category;

        // Always sent: explicit > context > "exception" for Throwables > the configured default.
        if ($category === null || trim($category) === '') {
            $category = $exception !== null ? 'exception' : $this->settings->category;
        }

        $mask = $this->redactor->maskString(...);
        $normalizedException = $exception !== null ? ExceptionNormalizer::normalize($exception, $mask) : null;

        $file = $overrides['file'] ?? $normalizedException['file'] ?? $site['file'] ?? null;
        $line = $overrides['line'] ?? $normalizedException['line'] ?? $site['line'] ?? null;

        $fields = [];

        foreach (self::REQUEST_FIELDS as $field) {
            $fields[$field] = $overrides[$field] ?? $request[$field] ?? null;
        }

        if (is_string($fields['url'])) {
            $fields['url'] = $this->redactor->redactUrl($fields['url']);
        }

        $metadata = array_filter([
            'sdk' => self::SDK_NAME,
            'sdk_version' => self::SDK_VERSION,
            'php_version' => PHP_VERSION,
            'framework' => defined('CodeIgniter\CodeIgniter::CI_VERSION') ? 'CodeIgniter ' . \CodeIgniter\CodeIgniter::CI_VERSION : null,
            'route' => $request['route'] ?? null,
            'app_version' => $this->settings->appVersion !== '' ? $this->settings->appVersion : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $payload = [
            'event_id' => $eventId ?? Uuid::v4(),
            'level' => $level,
            'level_name' => Severity::name($level),
            'message' => ExceptionNormalizer::truncate($mask($message), self::MAX_MESSAGE_BYTES, '...[truncated]'),
            'category' => ExceptionNormalizer::truncate(trim($category), self::MAX_CATEGORY_BYTES, ''),
            'environment' => $this->settings->environment,
            'application' => $this->settings->application !== '' ? $this->settings->application : null,
            'hostname' => $this->hostname(),
            'request_id' => $fields['request_id'] !== null ? (string) $fields['request_id'] : null,
            'user_id' => $fields['user_id'],
            'url' => $fields['url'],
            'http_method' => is_string($fields['http_method']) ? strtoupper($fields['http_method']) : null,
            'http_status' => $fields['http_status'] !== null ? (int) $fields['http_status'] : null,
            'file' => $file !== null ? (string) $file : null,
            'line' => $line !== null ? (int) $line : null,
            'exception' => $normalizedException,
            'context' => $this->section($context),
            'metadata' => $this->section($metadata),
            'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
        ];

        // Drop empty optional fields so the portal's validation never sees an unexpected null.
        foreach (['application', 'request_id', 'user_id', 'url', 'http_method', 'http_status', 'file', 'line', 'exception'] as $optional) {
            if ($payload[$optional] === null || $payload[$optional] === '') {
                unset($payload[$optional]);
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function heartbeat(): array
    {
        return array_filter([
            'environment' => $this->settings->environment,
            'hostname' => $this->hostname(),
            'application_version' => $this->settings->appVersion !== '' ? $this->settings->appVersion : null,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Encode a payload, shrinking it if it would exceed the portal's 256 KB body limit.
     */
    public function encode(array $payload): string
    {
        $json = $this->json($payload);

        if (strlen($json) <= self::MAX_BODY_BYTES) {
            return $json;
        }

        // Shrink in order of least value: context, then trace, then metadata.
        $payload['context'] = ['_truncated' => true, '_reason' => 'request body over 256 KB'];
        $json = $this->json($payload);

        if (strlen($json) > self::MAX_BODY_BYTES && isset($payload['exception']['trace'])) {
            $payload['exception']['trace'] = ExceptionNormalizer::truncate($payload['exception']['trace'], 16384);
            $json = $this->json($payload);
        }

        if (strlen($json) > self::MAX_BODY_BYTES) {
            $payload['metadata'] = ['_truncated' => true];
            $payload['message'] = ExceptionNormalizer::truncate((string) $payload['message'], 16384, '...[truncated]');
            $json = $this->json($payload);
        }

        return $json;
    }

    public function json(mixed $value): string
    {
        $json = json_encode($value, self::JSON_FLAGS);

        if ($json === false) {
            throw new \JsonException('Could not encode InnLogger payload: ' . json_last_error_msg());
        }

        return $json;
    }

    /**
     * Redact a context/metadata section, keep it a JSON object and cap it at 64 KB.
     */
    private function section(array $values): object|array
    {
        if ($values === []) {
            return (object) [];
        }

        if (array_is_list($values)) {
            $values = ['values' => $values];
        }

        $redacted = $this->redactor->redact($values);
        $size = strlen($this->json($redacted));

        if ($size > self::MAX_SECTION_BYTES) {
            return ['_truncated' => true, '_original_bytes' => $size];
        }

        return $redacted;
    }

    private function hostname(): string
    {
        if ($this->settings->hostname !== null && $this->settings->hostname !== '') {
            return $this->settings->hostname;
        }

        $hostname = gethostname();

        return is_string($hostname) && $hostname !== '' ? $hostname : 'unknown';
    }
}
