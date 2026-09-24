<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core\Transport;

final class Response
{
    /** @var array<string, string> lower-cased header name => value */
    public readonly array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        array $headers = [],
    ) {
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Worth retrying: the server or a proxy failed, not the request.
     */
    public function isTransient(): bool
    {
        return in_array($this->status, [500, 502, 503, 504], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->body === '') {
            return [];
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
