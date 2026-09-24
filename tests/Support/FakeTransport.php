<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Support;

use Closure;
use InnLogger\CodeIgniter4\Core\Transport\Response;
use InnLogger\CodeIgniter4\Core\Transport\TransportInterface;
use Throwable;

/**
 * Records every request and replays queued responses/throwables (default: 202 with a log_id).
 */
final class FakeTransport implements TransportInterface
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: float, connect_timeout: float}> */
    public array $requests = [];

    /** @var list<Response|Throwable|Closure> */
    private array $queue = [];

    public function push(Response|Throwable|Closure ...$outcomes): self
    {
        foreach ($outcomes as $outcome) {
            $this->queue[] = $outcome;
        }

        return $this;
    }

    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): Response
    {
        $this->requests[] = [
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ];

        $outcome = array_shift($this->queue) ?? new Response(202, '{"success":true,"message":"Log accepted","data":{"log_id":"11111111-2222-4333-8444-555555555555"}}');

        if ($outcome instanceof Closure) {
            $outcome = $outcome($url, $headers, $body);
        }

        if ($outcome instanceof Throwable) {
            throw $outcome;
        }

        return $outcome;
    }

    public function count(): int
    {
        return count($this->requests);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $index = 0): array
    {
        return json_decode($this->requests[$index]['body'], true, 512, JSON_THROW_ON_ERROR);
    }

    public function last(): array
    {
        return $this->requests[array_key_last($this->requests)];
    }
}
