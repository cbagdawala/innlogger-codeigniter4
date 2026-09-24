<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

use Closure;
use InnLogger\CodeIgniter4\Core\Transport\CurlTransport;
use InnLogger\CodeIgniter4\Core\Transport\Response;
use InnLogger\CodeIgniter4\Core\Transport\TransportException;
use InnLogger\CodeIgniter4\Core\Transport\TransportInterface;
use Stringable;
use Throwable;

/**
 * The InnLogger client. Framework-agnostic and fail-silent: no public method
 * ever throws, whatever the transport, the payload or the configuration does.
 */
class InnLoggerClient
{
    public const LOGS_PATH = '/api/v1/logs';
    public const HEARTBEAT_PATH = '/api/v1/heartbeat';

    /**
     * Set while any client is building or sending an event. A log call made
     * while it is set (for example by an error handler reacting to something
     * inside the transport) is dropped instead of recursing.
     */
    private static bool $busy = false;

    private readonly Redactor $redactor;
    private readonly EventBuilder $builder;
    private readonly TransportInterface $transport;
    private readonly ContextProviderInterface $contextProvider;

    /** @var Closure(string): void */
    private readonly Closure $diagnostic;

    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * Unix time until which nothing is sent, after the portal answered 429.
     * The CodeIgniter service is shared, so this holds for the whole process.
     */
    private int $pausedUntil = 0;

    /**
     * @param (callable(string): void)|null $diagnostic receives local diagnostics when Settings::$debug is on;
     *                                                  defaults to PHP's error_log() (never the framework logger)
     * @param (callable(): int)|null        $clock      current Unix time (for tests)
     */
    public function __construct(
        private readonly Settings $settings,
        ?TransportInterface $transport = null,
        ?ContextProviderInterface $contextProvider = null,
        ?callable $diagnostic = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn (): int => time();
        $this->redactor = new Redactor($settings->redactFields, $settings->maskPatterns, [$settings->apiSecret()]);
        $this->builder = new EventBuilder($settings, $this->redactor);
        $this->transport = $transport ?? new CurlTransport();
        $this->contextProvider = $contextProvider ?? new NullContextProvider();
        $this->diagnostic = $diagnostic !== null
            ? Closure::fromCallable($diagnostic)
            : static function (string $line): void {
                error_log($line);
            };
    }

    public function critical(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::CRITICAL, $message, $context);
    }

    public function error(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::ERROR, $message, $context);
    }

    public function warning(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::WARNING, $message, $context);
    }

    public function notice(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::NOTICE, $message, $context);
    }

    public function info(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::INFO, $message, $context);
    }

    public function debug(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::DEBUG, $message, $context);
    }

    public function trace(string|Stringable $message, array $context = []): SendResult
    {
        return $this->log(Severity::TRACE, $message, $context);
    }

    /**
     * @param int|string $level 1-7 or a level name ("error", PSR-3 names)
     */
    public function log(int|string $level, string|Stringable $message, array $context = [], array $overrides = []): SendResult
    {
        try {
            $resolved = Severity::fromMixed($level);

            if ($resolved === null || ! Severity::isEventLevel($resolved)) {
                return SendResult::skipped(SendResult::BELOW_THRESHOLD, 'unknown level');
            }

            return $this->capture($resolved, (string) $message, $context, null, $overrides, false);
        } catch (Throwable $e) {
            return $this->fail('log call failed: ' . $e::class);
        }
    }

    /**
     * Report a Throwable. Level defaults to ERROR (2).
     *
     * @param array<string, mixed> $overrides top-level payload fields, e.g. ['http_status' => 500]
     */
    public function exception(Throwable $exception, array $context = [], int $level = Severity::ERROR, array $overrides = []): SendResult
    {
        try {
            $message = $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class;

            return $this->capture($level, $message, $context, $exception, $overrides, false);
        } catch (Throwable $e) {
            return $this->fail('exception call failed: ' . $e::class);
        }
    }

    /**
     * POST /api/v1/heartbeat. Ignores the threshold (a heartbeat is not a log),
     * but respects enabled=false.
     */
    public function heartbeat(): SendResult
    {
        try {
            if (! $this->settings->enabled) {
                return SendResult::skipped(SendResult::DISABLED);
            }

            return $this->dispatch(self::HEARTBEAT_PATH, $this->builder->heartbeat(), null);
        } catch (Throwable $e) {
            return $this->fail('heartbeat failed: ' . $e::class);
        }
    }

    /**
     * Send one INFO test event regardless of enabled/threshold (used by `spark innlogger:test`).
     */
    public function sendTestEvent(string $message = 'InnLogger test event'): SendResult
    {
        try {
            return $this->capture(Severity::INFO, $message, ['category' => 'innlogger-test', 'test' => true], null, [], true);
        } catch (Throwable $e) {
            return $this->fail('test event failed: ' . $e::class);
        }
    }

    public function shouldSend(int $level): bool
    {
        return $this->settings->enabled && Severity::shouldSend($level, $this->settings->threshold);
    }

    public function settings(): Settings
    {
        return $this->settings;
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    /**
     * Build the payload a call would send, without sending it (for inspection/tests).
     *
     * @return array<string, mixed>
     */
    public function buildPayload(int $level, string $message, array $context = [], ?Throwable $exception = null, array $overrides = []): array
    {
        return $this->builder->build($level, $message, $context, $exception, $this->requestContext(), $overrides, $exception === null ? self::callSite() : null);
    }

    private function capture(int $level, string $message, array $context, ?Throwable $exception, array $overrides, bool $force): SendResult
    {
        if (! $force) {
            if (! $this->settings->enabled) {
                return SendResult::skipped(SendResult::DISABLED);
            }

            if (! Severity::shouldSend($level, $this->settings->threshold)) {
                return SendResult::skipped(SendResult::BELOW_THRESHOLD);
            }
        }

        if (self::$busy) {
            return SendResult::skipped(SendResult::REENTRANT);
        }

        if ($this->isPaused()) {
            return SendResult::skipped(SendResult::RATE_LIMITED);
        }

        self::$busy = true;

        try {
            $payload = $this->buildPayload($level, $message, $context, $exception, $overrides);

            return $this->dispatch(self::LOGS_PATH, $payload, (string) $payload['event_id']);
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Sign and send, with the optional bounded retry. Retries reuse the same
     * body (so the same event_id) with a fresh timestamp and nonce.
     */
    private function dispatch(string $path, array $payload, ?string $eventId): SendResult
    {
        $invalid = $this->settings->invalidReason();

        if ($invalid !== null) {
            $this->diagnose('not sent, invalid configuration: ' . $invalid);

            return new SendResult(SendResult::INVALID_CONFIG, $eventId, error: $invalid);
        }

        if ($this->isPaused()) {
            return new SendResult(SendResult::RATE_LIMITED, $eventId, error: 'rate limited until ' . gmdate('H:i:s', $this->pausedUntil) . ' UTC');
        }

        $wasBusy = self::$busy;
        self::$busy = true;

        try {
            $body = $this->builder->encode($payload);
            $url = $this->settings->url . $path;
            $signer = new Signer($this->settings->apiKey, $this->settings->apiSecret());
            // One request ID per logical send, shared by its retries; independent of event_id.
            $requestId = Uuid::v4();
            $maxAttempts = 1 + $this->settings->retries;
            $lastError = null;
            $response = null;

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                if ($attempt > 1 && $this->settings->retryDelayMs > 0) {
                    usleep($this->settings->retryDelayMs * 1000);
                }

                try {
                    $response = $this->transport->post(
                        $url,
                        $signer->headers($body, $requestId),
                        $body,
                        $this->settings->timeout,
                        $this->settings->connectTimeout,
                    );
                } catch (Throwable $e) {
                    $response = null;
                    $lastError = $e instanceof TransportException ? $e->getMessage() : 'transport error: ' . $e::class;

                    continue;
                }

                if ($response->isSuccessful()) {
                    return new SendResult(SendResult::SENT, $eventId, $response->status, $this->logId($response), $attempt);
                }

                $lastError = 'HTTP ' . $response->status . $this->serverMessage($response);

                if ($response->status === 429) {
                    $seconds = $this->retryAfter($response);
                    $this->pausedUntil = ($this->clock)() + $seconds;
                    $lastError .= ', sending paused for ' . $seconds . ' s';

                    break;
                }

                if (! $response->isTransient()) {
                    break;
                }
            }

            $this->diagnose(sprintf('%s not delivered%s: %s', $path, $eventId !== null ? ' (event ' . $eventId . ')' : '', $lastError ?? 'unknown error'));

            return new SendResult(
                SendResult::FAILED,
                $eventId,
                $response?->status,
                attempts: min($attempt, $maxAttempts),
                error: $lastError,
            );
        } finally {
            self::$busy = $wasBusy;
        }
    }

    public function __debugInfo(): array
    {
        return [
            'settings' => $this->settings->describe(),
            'transport' => $this->transport::class,
            'contextProvider' => $this->contextProvider::class,
            'pausedUntil' => $this->pausedUntil,
        ];
    }

    /**
     * A client can't be serialized with its secret; only the safe description is kept,
     * and unserializing it is refused.
     */
    public function __serialize(): array
    {
        return ['settings' => $this->settings->describe()];
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('An InnLogger client cannot be unserialized; resolve it from service(\'innlogger\') instead.');
    }

    public function isPaused(): bool
    {
        return $this->pausedUntil > ($this->clock)();
    }

    /**
     * Seconds to pause after a 429: body "retry_after", else the Retry-After
     * header (seconds or HTTP date), else 60; always within 1-3600.
     */
    private function retryAfter(Response $response): int
    {
        $value = $response->json()['retry_after'] ?? $response->header('retry-after');
        $seconds = 60;

        if (is_numeric($value)) {
            $seconds = (int) ceil((float) $value);
        } elseif (is_string($value) && ($time = strtotime($value)) !== false) {
            $seconds = $time - ($this->clock)();
        }

        return max(1, min(3600, $seconds));
    }

    private function logId(Response $response): ?string
    {
        $data = $response->json()['data'] ?? null;

        return is_array($data) && isset($data['log_id']) && is_scalar($data['log_id']) ? (string) $data['log_id'] : null;
    }

    private function serverMessage(Response $response): string
    {
        $message = $response->json()['message'] ?? null;

        return is_string($message) && $message !== '' ? ' (' . substr($message, 0, 200) . ')' : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function requestContext(): array
    {
        try {
            return $this->contextProvider->context();
        } catch (Throwable) {
            return [];
        }
    }

    private function fail(string $error): SendResult
    {
        $this->diagnose($error);

        return new SendResult(SendResult::FAILED, error: $error);
    }

    private function diagnose(string $message): void
    {
        if (! $this->settings->debug) {
            return;
        }

        try {
            ($this->diagnostic)('[InnLogger] ' . $message);
        } catch (Throwable) {
            // Diagnostics are best effort.
        }
    }

    /**
     * The file/line of the application code that made the log call, skipping
     * this SDK and CodeIgniter's logger (log_message()).
     *
     * @return array{file: string, line: int}|null
     */
    private static function callSite(): ?array
    {
        $site = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            $class = $frame['class'] ?? '';
            $function = $frame['function'];
            $internal = str_starts_with($class, 'InnLogger\\CodeIgniter4\\Core\\')
                || str_starts_with($class, 'InnLogger\\CodeIgniter4\\Log\\')
                || str_starts_with($class, 'InnLogger\\CodeIgniter4\\Exceptions\\')
                || str_starts_with($class, 'CodeIgniter\\Log\\')
                || ($class === '' && $function === 'log_message');

            if (! $internal) {
                break;
            }

            if (isset($frame['file'], $frame['line'])) {
                $site = ['file' => $frame['file'], 'line' => $frame['line']];
            }
        }

        return $site;
    }
}
