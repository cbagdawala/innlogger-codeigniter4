<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

use Closure;
use LogicException;

/**
 * Builds the InnLogger HMAC request headers (spec 03-REST-API §1, 09-Security §2).
 *
 * signature = lowercase hex HMAC-SHA256(timestamp . "\n" . nonce . "\n" . raw_body, api_secret)
 *
 * The secret lives in a closure so dumps, var_export() and serialize() can't reveal it.
 */
final class Signer
{
    /** @var Closure(): string */
    private readonly Closure $secret;

    public function __construct(
        private readonly string $apiKey,
        #[\SensitiveParameter]
        string $apiSecret,
    ) {
        $this->secret = static fn (): string => $apiSecret;
    }

    public static function sign(string $timestamp, string $nonce, string $rawBody, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $rawBody, $secret);
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @return array<string, string> header name => value
     */
    public function headers(string $rawBody, string $requestId, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= self::nonce();

        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-InnLogger-Key' => $this->apiKey,
            'X-InnLogger-Timestamp' => $timestamp,
            'X-InnLogger-Nonce' => $nonce,
            'X-InnLogger-Signature' => self::sign($timestamp, $nonce, $rawBody, ($this->secret)()),
            'X-InnLogger-Request-Id' => $requestId,
        ];
    }

    public function __debugInfo(): array
    {
        return ['apiKey' => $this->apiKey, 'apiSecret' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        throw new LogicException('An InnLogger Signer cannot be serialized.');
    }
}
