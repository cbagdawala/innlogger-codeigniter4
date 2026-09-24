<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Integration;

use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\SendResult;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Core\Transport\CurlTransport;
use InnLogger\CodeIgniter4\Core\Transport\TransportException;
use PHPUnit\Framework\TestCase;

/**
 * Real HTTP through ext-curl against PHP's built-in server (no network needed).
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $port = random_int(18000, 18999);
        $router = dirname(__DIR__) . '/Support/echo-server.php';
        $process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            // Several workers so a sleeping request doesn't block the next test.
            ['PHP_CLI_SERVER_WORKERS' => '4'] + getenv(),
        );

        if (! is_resource($process)) {
            return;
        }

        self::$process = $process;

        for ($i = 0; $i < 50; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);

            if ($socket !== false) {
                fclose($socket);
                self::$base = 'http://127.0.0.1:' . $port;

                return;
            }

            usleep(50000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$process)) {
            proc_terminate(self::$process);
            proc_close(self::$process);
        }
    }

    protected function setUp(): void
    {
        if (self::$base === '') {
            $this->markTestSkipped('PHP built-in server could not be started.');
        }
    }

    public function testPostsBodyAndHeadersVerbatim(): void
    {
        $response = (new CurlTransport())->post(
            self::$base . '/api/v1/logs?status=202',
            ['Content-Type' => 'application/json', 'X-InnLogger-Key' => 'ilv_k', 'X-InnLogger-Signature' => 'abc'],
            '{"message":"héllo"}',
            2.0,
            1.0,
        );

        $echo = $response->json();
        $this->assertSame(202, $response->status);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('{"message":"héllo"}', $echo['body']);
        $this->assertSame('ilv_k', $echo['headers']['HTTP_X_INNLOGGER_KEY']);
        $this->assertSame('application/json', $echo['headers']['CONTENT_TYPE']);
    }

    public function testResponseHeadersAreCaptured(): void
    {
        $response = (new CurlTransport())->post(self::$base . '/api/v1/logs?status=429', [], '{}', 2.0, 1.0);

        $this->assertSame(429, $response->status);
        $this->assertSame('7', $response->header('Retry-After'));
        $this->assertSame('application/json', $response->header('content-type'));
    }

    public function testTimeoutThrowsTransportExceptionQuickly(): void
    {
        $start = microtime(true);

        try {
            (new CurlTransport())->post(self::$base . '/sleep', [], '{}', 0.5, 0.5);
            $this->fail('Expected a TransportException');
        } catch (TransportException $e) {
            $this->assertStringContainsString('curl error 28', $e->getMessage());
        }

        $this->assertLessThan(2.0, microtime(true) - $start);
    }

    public function testConnectionRefusedThrowsTransportException(): void
    {
        $this->expectException(TransportException::class);

        (new CurlTransport())->post('http://127.0.0.1:1/api/v1/logs', [], '{}', 1.0, 0.5);
    }

    public function testClientEndToEndIsSignedAndTimeoutIsSwallowed(): void
    {
        $settings = Settings::fromArray([
            'url' => self::$base,
            'api_key' => 'ilv_k',
            'api_secret' => 'ils_s',
            'log_level' => 2,
            'allow_insecure' => true,
            'timeout' => 0.5,
            'retries' => 0,
        ]);
        $client = new InnLoggerClient($settings);

        $this->assertTrue($client->error('real request')->sent());
        $this->assertTrue($client->heartbeat()->sent());

        $slow = new InnLoggerClient(Settings::fromArray([
            'url' => self::$base . '/sleep',
            'api_key' => 'ilv_k',
            'api_secret' => 'ils_s',
            'log_level' => 2,
            'allow_insecure' => true,
            'timeout' => 0.5,
            'retries' => 0,
        ]));
        $start = microtime(true);
        $result = $slow->critical('will time out');

        $this->assertSame(SendResult::FAILED, $result->status);
        $this->assertLessThan(2.0, microtime(true) - $start);
    }
}
