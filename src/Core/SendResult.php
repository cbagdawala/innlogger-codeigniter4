<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

/**
 * What happened to one log/heartbeat call. Never thrown, only returned.
 */
final class SendResult
{
    public const SENT = 'sent';
    public const DISABLED = 'disabled';
    public const BELOW_THRESHOLD = 'below_threshold';
    public const INVALID_CONFIG = 'invalid_config';
    public const REENTRANT = 'reentrant';
    /** Skipped because the portal answered 429 and its Retry-After has not elapsed. */
    public const RATE_LIMITED = 'rate_limited';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $status,
        public readonly ?string $eventId = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $logId = null,
        public readonly int $attempts = 0,
        public readonly ?string $error = null,
    ) {
    }

    public static function skipped(string $status, ?string $error = null): self
    {
        return new self($status, error: $error);
    }

    public function sent(): bool
    {
        return $this->status === self::SENT;
    }

    /**
     * The portal already had this event_id (HTTP 200 instead of 202).
     */
    public function duplicate(): bool
    {
        return $this->sent() && $this->httpStatus === 200;
    }
}
