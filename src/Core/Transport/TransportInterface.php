<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core\Transport;

/**
 * Sends one HTTP POST. Implementations throw TransportException on network
 * failure; the client catches it, so a transport never needs to be defensive
 * about the host application.
 */
interface TransportInterface
{
    /**
     * @param array<string, string> $headers
     *
     * @throws TransportException
     */
    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): Response;
}
