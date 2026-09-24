<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core\Transport;

/**
 * Plain ext-curl transport: short timeouts, TLS verification on, no redirects,
 * http(s) only. It does not go through CodeIgniter's CURLRequest so it can't
 * trigger the framework's logger or error handling.
 */
final class CurlTransport implements TransportInterface
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function post(string $url, array $headers, string $body, float $timeout, float $connectTimeout): Response
    {
        if (! function_exists('curl_init')) {
            throw new TransportException('ext-curl is not available');
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new TransportException('curl_init failed');
        }

        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $lines[] = 'Expect:';
        $lines[] = 'User-Agent: innlogger-codeigniter4';

        $responseHeaders = [];
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => max(100, (int) round($timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => max(100, (int) round(min($connectTimeout, $timeout) * 1000)),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2 && count($responseHeaders) < 50) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                } elseif (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = []; // a new response (e.g. after 100 Continue)
                }

                return strlen($line);
            },
        ];

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($handle, $options);

        $result = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        // PHP 8 frees the CurlHandle when it goes out of scope (curl_close() is a no-op/deprecated).
        unset($handle);

        if ($result === false || $errno !== 0) {
            throw new TransportException(sprintf('curl error %d: %s', $errno, $error));
        }

        return new Response($status, substr((string) $result, 0, self::MAX_RESPONSE_BYTES), $responseHeaders);
    }
}
