<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Integration;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Log\Logger;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Exceptions as ExceptionsConfig;
use Config\Logger as LoggerConfig;
use InnLogger\CodeIgniter4\Config\InnLogger as InnLoggerConfig;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Core\Transport\Response;
use InnLogger\CodeIgniter4\Core\Transport\TransportException;
use InnLogger\CodeIgniter4\Exceptions\ReportingExceptionHandler;
use InnLogger\CodeIgniter4\Log\InnLoggerHandler;
use InnLogger\CodeIgniter4\Tests\Support\FakeTransport;
use RuntimeException;
use Throwable;

final class HandlersTest extends CIUnitTestCase
{
    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new FakeTransport();
    }

    protected function tearDown(): void
    {
        \Config\Services::reset(true);
        parent::tearDown();
    }

    private function client(int $threshold = 7): InnLoggerClient
    {
        return new InnLoggerClient(
            Settings::fromArray(['url' => 'https://logger.example.com', 'api_key' => 'ilv_k', 'api_secret' => 'ils_s', 'log_level' => $threshold]),
            $this->transport,
        );
    }

    // --- log handler ---------------------------------------------------------------

    public function testLogHandlerMapsCodeIgniterLevels(): void
    {
        $handler = new InnLoggerHandler(['handles' => ['emergency', 'critical', 'error', 'warning', 'info']], $this->client());

        foreach (['emergency' => 1, 'critical' => 1, 'error' => 2, 'warning' => 3, 'info' => 5] as $level => $expected) {
            $this->assertTrue($handler->handle($level, "{$level} message"), 'handler lets the chain continue');
        }

        $levels = array_map(static fn (array $r): int => json_decode($r['body'], true)['level'], $this->transport->requests);
        $this->assertSame([1, 1, 2, 3, 5], $levels);
        $this->assertSame('log', $this->transport->payload()['category']);
    }

    public function testLogHandlerAppliesTheInnLoggerThreshold(): void
    {
        $handler = new InnLoggerHandler(['handles' => ['error', 'debug']], $this->client(2));

        $handler->handle('debug', 'noise');
        $handler->handle('error', 'real');

        $this->assertSame(1, $this->transport->count());
        $this->assertSame('real', $this->transport->payload()['message']);
    }

    public function testLogHandlerNeverThrows(): void
    {
        $this->transport->push(new TransportException('down'));
        $handler = new InnLoggerHandler(['handles' => ['error']], $this->client());

        $this->assertTrue($handler->handle('error', 'x'));
        $this->assertTrue($handler->handle('not-a-level', 'x'));
    }

    public function testLogMessageThroughCodeIgniterLoggerReachesInnLogger(): void
    {
        $client = $this->client();
        $config = new LoggerConfig();
        $config->threshold = 9;
        $config->handlers = [InnLoggerHandler::class => ['handles' => ['critical', 'error']]];
        service('logger'); // make sure the framework logger exists before we swap it
        $logger = new Logger($config);
        \Config\Services::injectMock('innlogger', $client);
        \Config\Services::injectMock('logger', $logger);

        $logger->log('error', 'Order {id} failed', ['id' => 42]); // log_message() swaps in TestLogger when ENVIRONMENT=testing

        $this->assertSame(1, $this->transport->count());
        $payload = $this->transport->payload();
        $this->assertSame('Order 42 failed', $payload['message']);
        $this->assertSame(2, $payload['level']);
        $this->assertSame(__FILE__, $payload['file'], 'call site skips the CodeIgniter logger and the handler');
    }

    public function testLoggingFromInsideTheTransportDoesNotRecurse(): void
    {
        $client = $this->client();
        $config = new LoggerConfig();
        $config->threshold = 9;
        $config->handlers = [InnLoggerHandler::class => ['handles' => ['critical', 'error']]];
        $logger = new Logger($config);
        \Config\Services::injectMock('innlogger', $client);
        \Config\Services::injectMock('logger', $logger);

        $this->transport->push(static function () use ($logger): Response {
            $logger->log('error', 'transport complained'); // e.g. an error handler firing mid-request

            return new Response(202, '{}');
        });

        $logger->log('error', 'outer');

        $this->assertSame(1, $this->transport->count());
        $this->assertSame('outer', $this->transport->payload()['message']);
    }

    // --- exception handler --------------------------------------------------------------

    public function testExceptionHandlerReportsThenDelegates(): void
    {
        $inner = $this->innerHandler();
        $handler = new ReportingExceptionHandler(new ExceptionsConfig(), $inner, $this->client(), new InnLoggerConfig());
        $exception = new RuntimeException('Gateway timeout');

        $handler->handle($exception, service('request'), service('response'), 500, 1);

        $this->assertSame([$exception], $inner->handled);
        $payload = $this->transport->payload();
        $this->assertSame(1, $payload['level'], '5xx is CRITICAL');
        $this->assertSame(500, $payload['http_status']);
        $this->assertSame('exception', $payload['category']);
        $this->assertSame(RuntimeException::class, $payload['exception']['class']);
    }

    public function testExceptionHandlerSkipsIgnoredStatusCodes(): void
    {
        $inner = $this->innerHandler();
        $handler = new ReportingExceptionHandler(new ExceptionsConfig(), $inner, $this->client(), new InnLoggerConfig());

        $handler->handle(PageNotFoundException::forPageNotFound(), service('request'), service('response'), 404, 4);

        $this->assertSame(0, $this->transport->count());
        $this->assertCount(1, $inner->handled, 'CodeIgniter still renders the 404');
    }

    public function testExceptionHandlerRespectsAutoExceptionFalse(): void
    {
        $config = new InnLoggerConfig();
        $config->autoException = false;
        $inner = $this->innerHandler();

        (new ReportingExceptionHandler(new ExceptionsConfig(), $inner, $this->client(), $config))
            ->handle(new RuntimeException('x'), service('request'), service('response'), 500, 1);

        $this->assertSame(0, $this->transport->count());
        $this->assertCount(1, $inner->handled);
    }

    public function testExceptionHandlerStillDelegatesWhenInnLoggerFails(): void
    {
        $this->transport->push(new TransportException('down'));
        $inner = $this->innerHandler();
        $handler = new ReportingExceptionHandler(new ExceptionsConfig(), $inner, $this->client(), new InnLoggerConfig());

        $handler->handle(new RuntimeException('x'), service('request'), service('response'), 500, 1);

        $this->assertCount(1, $inner->handled);
    }

    public function testNon5xxIsReportedAsError(): void
    {
        $handler = new ReportingExceptionHandler(new ExceptionsConfig(), $this->innerHandler(), $this->client(), new InnLoggerConfig());

        $handler->report(new RuntimeException('bad input'), 400);

        $this->assertSame(2, $this->transport->payload()['level']);
        $this->assertSame(400, $this->transport->payload()['http_status']);
    }

    private function innerHandler(): object
    {
        return new class () implements ExceptionHandlerInterface {
            /** @var list<Throwable> */
            public array $handled = [];

            public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
            {
                $this->handled[] = $exception;
            }
        };
    }
}
