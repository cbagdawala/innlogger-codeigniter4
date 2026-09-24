<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Unit;

use Error;
use InnLogger\CodeIgniter4\Core\ContextProviderInterface;
use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\SendResult;
use InnLogger\CodeIgniter4\Core\Severity;
use InnLogger\CodeIgniter4\Core\Transport\Response;
use InnLogger\CodeIgniter4\Core\Transport\TransportException;
use InnLogger\CodeIgniter4\Core\Uuid;
use InnLogger\CodeIgniter4\Tests\Support\ClientFactoryTrait;
use InnLogger\CodeIgniter4\Tests\Support\FakeTransport;
use InnLogger\CodeIgniter4\Tests\Support\TestCredentials;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ClientTest extends TestCase
{
    use ClientFactoryTrait;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->diagnostics = [];
    }

    // --- endpoint, headers, signature ------------------------------------------------

    public function testPostsToTheLogsEndpointWithTheConfiguredTimeouts(): void
    {
        $result = $this->client(['timeout' => 1.5, 'connect_timeout' => 0.5])->error('Payment failed');

        $this->assertTrue($result->sent());
        $this->assertSame(1, $this->transport->count());
        $this->assertSame('https://logger.example.com/api/v1/logs', $this->transport->last()['url']);
        $this->assertSame(1.5, $this->transport->last()['timeout']);
        $this->assertSame(0.5, $this->transport->last()['connect_timeout']);
    }

    public function testTrailingSlashInUrlIsIgnored(): void
    {
        $this->client(['url' => 'https://logger.example.com/'])->error('x');

        $this->assertSame('https://logger.example.com/api/v1/logs', $this->transport->last()['url']);
    }

    public function testSendsAllContractHeadersWithAValidSignature(): void
    {
        $before = time();
        $this->client()->error('Payment failed', ['order_id' => 42]);
        $request = $this->transport->last();
        $headers = $request['headers'];

        $this->assertSame(TestCredentials::KEY, $headers['X-InnLogger-Key']);
        $this->assertMatchesRegularExpression('/^\d+$/', $headers['X-InnLogger-Timestamp']);
        $this->assertGreaterThanOrEqual($before, (int) $headers['X-InnLogger-Timestamp']);
        $this->assertLessThanOrEqual(time(), (int) $headers['X-InnLogger-Timestamp']);
        $this->assertNotSame('', $headers['X-InnLogger-Nonce']);
        $this->assertNotSame('', $headers['X-InnLogger-Request-Id']);
        $this->assertSame('application/json', $headers['Content-Type']);

        // Recompute the HMAC independently over the exact bytes that were sent.
        $expected = hash_hmac(
            'sha256',
            $headers['X-InnLogger-Timestamp'] . "\n" . $headers['X-InnLogger-Nonce'] . "\n" . $request['body'],
            TestCredentials::SECRET,
        );
        $this->assertSame($expected, $headers['X-InnLogger-Signature']);
        $this->assertTrue(hash_equals($expected, $headers['X-InnLogger-Signature']));
    }

    public function testTheSecretIsNeverSent(): void
    {
        $this->client()->error('x', ['note' => 'hello']);
        $request = $this->transport->last();

        $this->assertStringNotContainsString(TestCredentials::SECRET, $request['body']);
        $this->assertNotContains(TestCredentials::SECRET, $request['headers']);
    }

    public function testEachRequestGetsAFreshNonce(): void
    {
        $client = $this->client();
        $client->error('a');
        $client->error('b');

        $this->assertNotSame(
            $this->transport->requests[0]['headers']['X-InnLogger-Nonce'],
            $this->transport->requests[1]['headers']['X-InnLogger-Nonce'],
        );
    }

    // --- payload ---------------------------------------------------------------------

    public function testPayloadMatchesTheEventContract(): void
    {
        $context = new class () implements ContextProviderInterface {
            public function context(): array
            {
                return ['url' => '/api/payment?token=abc', 'http_method' => 'post', 'request_id' => 'req_123', 'user_id' => 123, 'route' => 'api/payment'];
            }
        };

        $result = $this->client([], $context)->error('Payment failed', ['category' => 'payment', 'order_id' => 9]);
        $payload = $this->transport->payload();

        $this->assertMatchesRegularExpression(Uuid::PATTERN, $payload['event_id']);
        $this->assertSame($result->eventId, $payload['event_id']);
        $this->assertSame(2, $payload['level']);
        $this->assertSame('ERROR', $payload['level_name']);
        $this->assertSame('Payment failed', $payload['message']);
        $this->assertSame('payment', $payload['category']);
        $this->assertSame('production', $payload['environment']);
        $this->assertSame('trusted-nanny', $payload['application']);
        $this->assertSame('server01', $payload['hostname']);
        $this->assertSame('req_123', $payload['request_id']);
        $this->assertSame(123, $payload['user_id']);
        $this->assertSame('/api/payment?token=%5BREDACTED%5D', $payload['url']);
        $this->assertSame('POST', $payload['http_method']);
        $this->assertSame(__FILE__, $payload['file'], 'file is the application call site');
        $this->assertIsInt($payload['line']);
        $this->assertSame(['order_id' => 9], $payload['context']);
        $this->assertSame('api/payment', $payload['metadata']['route']);
        $this->assertSame('1.4.2', $payload['metadata']['app_version']);
        $this->assertSame('innlogger-codeigniter4', $payload['metadata']['sdk']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['occurred_at']);
        $this->assertArrayNotHasKey('exception', $payload);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $result->logId);
    }

    public function testEmptyContextIsSentAsAJsonObject(): void
    {
        $this->client()->info('x');

        $this->assertStringContainsString('"context":{}', $this->transport->last()['body']);
    }

    public function testEveryCallGetsItsOwnEventId(): void
    {
        $client = $this->client();
        $a = $client->error('a');
        $b = $client->error('b');

        $this->assertMatchesRegularExpression(Uuid::PATTERN, (string) $a->eventId);
        $this->assertNotSame($a->eventId, $b->eventId);
    }

    public function testContextIsRedactedBeforeSerialization(): void
    {
        $this->client(['redact_fields' => ['ssn']])->error('Login failed', [
            'email' => 'a@example.com',
            'Password' => 'hunter2',
            'request' => ['headers' => ['Authorization' => 'Bearer abc'], 'body' => ['SSN' => '123-45-6789']],
        ]);
        $body = $this->transport->last()['body'];
        $payload = $this->transport->payload();

        $this->assertStringNotContainsString('hunter2', $body);
        $this->assertStringNotContainsString('Bearer abc', $body);
        $this->assertStringNotContainsString('123-45-6789', $body);
        $this->assertSame('[REDACTED]', $payload['context']['Password']);
        $this->assertSame('[REDACTED]', $payload['context']['request']['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $payload['context']['request']['body']['SSN']);
        $this->assertSame('a@example.com', $payload['context']['email']);
    }

    public function testSeverityMethodsSendTheirLevel(): void
    {
        $client = $this->client();
        $expected = ['critical' => 1, 'error' => 2, 'warning' => 3, 'notice' => 4, 'info' => 5, 'debug' => 6, 'trace' => 7];

        foreach ($expected as $method => $level) {
            $client->{$method}('m');
        }

        foreach (array_values($expected) as $i => $level) {
            $this->assertSame($level, $this->transport->payload($i)['level']);
            $this->assertSame(Severity::name($level), $this->transport->payload($i)['level_name']);
        }
    }

    public function testLogAcceptsLevelNames(): void
    {
        $this->client()->log('warning', 'w');

        $this->assertSame(3, $this->transport->payload()['level']);
    }

    public function testUnknownLevelIsSkipped(): void
    {
        $result = $this->client()->log('verbose', 'x');

        $this->assertFalse($result->sent());
        $this->assertSame(0, $this->transport->count());
    }

    // --- exceptions ------------------------------------------------------------------

    public function testExceptionIsNormalized(): void
    {
        $exception = new RuntimeException('Gateway timeout', 0, new \LogicException('root cause'));
        $result = $this->client()->exception($exception, ['order_id' => 1]);
        $payload = $this->transport->payload();

        $this->assertTrue($result->sent());
        $this->assertSame(2, $payload['level']);
        $this->assertSame('Gateway timeout', $payload['message']);
        $this->assertSame(RuntimeException::class, $payload['exception']['class']);
        $this->assertSame('Gateway timeout', $payload['exception']['message']);
        $this->assertSame($exception->getFile(), $payload['exception']['file']);
        $this->assertSame($exception->getLine(), $payload['exception']['line']);
        $this->assertSame($exception->getLine(), $payload['line']);
        $this->assertStringContainsString('Caused by: LogicException: root cause', $payload['exception']['trace']);
        $this->assertSame(['order_id' => 1], $payload['context']);
    }

    public function testExceptionLevelAndOverridesCanBeSet(): void
    {
        $this->client()->exception(new RuntimeException('down'), [], Severity::CRITICAL, ['http_status' => 503]);

        $this->assertSame(1, $this->transport->payload()['level']);
        $this->assertSame(503, $this->transport->payload()['http_status']);
    }

    public function testPsr3ExceptionContextKeyBecomesTheExceptionObject(): void
    {
        $this->client()->error('Checkout failed', ['exception' => new RuntimeException('card declined'), 'cart' => 3]);
        $payload = $this->transport->payload();

        $this->assertSame('Checkout failed', $payload['message']);
        $this->assertSame('card declined', $payload['exception']['message']);
        $this->assertSame(['cart' => 3], $payload['context']);
    }

    public function testHugeTraceIsCappedAt64Kb(): void
    {
        $this->client()->exception($this->deep(1500));

        $this->assertLessThanOrEqual(65536, strlen($this->transport->payload()['exception']['trace']));
    }

    // --- threshold / disabled ----------------------------------------------------------

    public function testThresholdFiltersLevels(): void
    {
        $client = $this->client(['log_level' => 2]);

        $this->assertTrue($client->critical('c')->sent());
        $this->assertTrue($client->error('e')->sent());
        $this->assertSame(SendResult::BELOW_THRESHOLD, $client->warning('w')->status);
        $this->assertSame(SendResult::BELOW_THRESHOLD, $client->trace('t')->status);
        $this->assertSame(2, $this->transport->count());
    }

    public function testThresholdZeroSendsNothing(): void
    {
        $client = $this->client(['log_level' => 0]);

        foreach (['critical', 'error', 'warning', 'notice', 'info', 'debug', 'trace'] as $method) {
            $this->assertSame(SendResult::BELOW_THRESHOLD, $client->{$method}('x')->status);
        }

        $this->assertSame(SendResult::BELOW_THRESHOLD, $client->exception(new RuntimeException('x'))->status);
        $this->assertSame(0, $this->transport->count());
    }

    public function testExceptionRespectsTheThreshold(): void
    {
        $client = $this->client(['log_level' => 1]);

        $this->assertFalse($client->exception(new RuntimeException('x'))->sent());
        $this->assertTrue($client->exception(new RuntimeException('x'), [], Severity::CRITICAL)->sent());
    }

    public function testDisabledSendsNothing(): void
    {
        $client = $this->client(['enabled' => 'false']);

        $this->assertSame(SendResult::DISABLED, $client->critical('x')->status);
        $this->assertSame(SendResult::DISABLED, $client->exception(new RuntimeException('x'))->status);
        $this->assertSame(SendResult::DISABLED, $client->heartbeat()->status);
        $this->assertSame(0, $this->transport->count());
    }

    // --- configuration safety ------------------------------------------------------------

    public function testPlainHttpIsRefusedUnlessExplicitlyAllowed(): void
    {
        $result = $this->client(['url' => 'http://logger.example.com'])->error('x');

        $this->assertSame(SendResult::INVALID_CONFIG, $result->status);
        $this->assertSame(0, $this->transport->count());

        $this->assertTrue($this->client(['url' => 'http://localhost:8080', 'allow_insecure' => true])->error('x')->sent());
    }

    public function testMissingCredentialsAreNotSent(): void
    {
        $this->assertSame(SendResult::INVALID_CONFIG, $this->client(['api_key' => ''])->error('x')->status);
        $this->assertSame(SendResult::INVALID_CONFIG, $this->client(['api_secret' => ''])->error('x')->status);
        $this->assertSame(SendResult::INVALID_CONFIG, $this->client(['url' => 'not a url'])->error('x')->status);
        $this->assertSame(0, $this->transport->count());
    }

    // --- failure behaviour ----------------------------------------------------------------

    public function testTransportFailureIsSwallowed(): void
    {
        $this->transport->push(new TransportException('curl error 28: Operation timed out'));

        $result = $this->client()->critical('x');

        $this->assertSame(SendResult::FAILED, $result->status);
        $this->assertStringContainsString('timed out', (string) $result->error);
        $this->assertMatchesRegularExpression(Uuid::PATTERN, (string) $result->eventId);
    }

    public function testAnyThrowableFromTheTransportIsSwallowed(): void
    {
        $this->transport->push(new Error('engine failure'), new RuntimeException('odd'));
        $client = $this->client();

        $this->assertSame(SendResult::FAILED, $client->error('a')->status);
        $this->assertSame(SendResult::FAILED, $client->exception(new RuntimeException('b'))->status);
        $this->assertSame(SendResult::DISABLED, $this->client(['enabled' => false])->heartbeat()->status);
    }

    public function testHttpErrorsAreReturnedNotThrown(): void
    {
        $this->transport->push(
            new Response(401, '{"success":false,"message":"Invalid credentials"}'),
            new Response(422, 'not json'),
            new Response(429, '{"success":false,"message":"Rate limit exceeded","retry_after":30}'),
        );
        $client = $this->client(['retries' => 2]);

        $unauthorized = $client->error('a');
        $invalid = $client->error('b');
        $limited = $client->error('c');

        $this->assertSame(401, $unauthorized->httpStatus);
        $this->assertSame('HTTP 401 (Invalid credentials)', $unauthorized->error);
        $this->assertSame(429, $limited->httpStatus);
        $this->assertSame(422, $invalid->httpStatus);
        $this->assertSame(3, $this->transport->count(), '4xx responses are never retried');
    }

    public function testRetriesTransientFailuresWithTheSameEventIdAndFreshNonce(): void
    {
        $this->transport->push(new TransportException('connection reset'), new Response(503), new Response(202, '{"data":{"log_id":"abc"}}'));

        $result = $this->client(['retries' => 2])->error('x');

        $this->assertTrue($result->sent());
        $this->assertSame(3, $result->attempts);
        $this->assertSame(3, $this->transport->count());

        $ids = array_map(static fn (array $r): string => json_decode($r['body'], true)['event_id'], $this->transport->requests);
        $nonces = array_map(static fn (array $r): string => $r['headers']['X-InnLogger-Nonce'], $this->transport->requests);

        $this->assertCount(1, array_unique($ids), 'retries reuse the event_id');
        $this->assertCount(3, array_unique($nonces), 'each attempt has its own nonce');
        $this->assertSame($result->eventId, $ids[0]);
    }

    public function testNoRetryWhenRetriesIsZero(): void
    {
        $this->transport->push(new TransportException('down'));

        $this->assertFalse($this->client()->error('x')->sent());
        $this->assertSame(1, $this->transport->count());
    }

    public function testRetriesAreBounded(): void
    {
        $this->transport->push(...array_fill(0, 10, new Response(500)));

        $result = $this->client(['retries' => 99])->error('x');

        $this->assertSame(4, $this->transport->count(), 'at most 3 retries');
        $this->assertSame(4, $result->attempts);
    }

    public function testDuplicateEventResponseIsASuccess(): void
    {
        $this->transport->push(new Response(200, '{"success":true,"message":"Log already received","data":{"log_id":"existing"}}'));

        $result = $this->client()->error('x');

        $this->assertTrue($result->sent());
        $this->assertTrue($result->duplicate());
        $this->assertSame('existing', $result->logId);
    }

    public function testUnserializableContextDoesNotThrow(): void
    {
        $resource = fopen('php://memory', 'rb');
        $recursive = [];
        $recursive['self'] = &$recursive;

        $result = $this->client()->error("bad \xB1\x31 utf8", ['nan' => NAN, 'res' => $resource, 'loop' => $recursive, 'bin' => "\xff\xfe"]);

        fclose($resource);
        $this->assertTrue($result->sent());
        $this->assertIsArray($this->transport->payload());
    }

    public function testOversizedContextIsReplacedRatherThanRejected(): void
    {
        $result = $this->client()->error('big', ['blob' => str_repeat('x', 70000)]);
        $payload = $this->transport->payload();

        $this->assertTrue($result->sent());
        $this->assertTrue($payload['context']['_truncated']);
        $this->assertLessThanOrEqual(262144, strlen($this->transport->last()['body']));
    }

    public function testBodyIsKeptUnder256Kb(): void
    {
        $this->client()->error(str_repeat('m', 300000));

        $this->assertLessThanOrEqual(262144, strlen($this->transport->last()['body']));
    }

    public function testRecursiveLoggingFromInsideTheTransportIsDropped(): void
    {
        $client = null;
        $inner = null;
        $this->transport->push(function () use (&$client, &$inner): Response {
            $inner = $client->error('logged while sending');

            return new Response(202, '{}');
        });
        $client = $this->client();

        $outer = $client->error('outer');

        $this->assertTrue($outer->sent());
        $this->assertSame(SendResult::REENTRANT, $inner->status);
        $this->assertSame(1, $this->transport->count());

        // The guard is released afterwards.
        $this->assertTrue($client->error('after')->sent());
    }

    public function testContextProviderFailureIsIgnored(): void
    {
        $context = new class () implements ContextProviderInterface {
            public function context(): array
            {
                throw new RuntimeException('no request');
            }
        };

        $this->assertTrue($this->client([], $context)->error('x')->sent());
    }

    // --- diagnostics -----------------------------------------------------------------------

    public function testDiagnosticsOnlyWhenDebugIsOnAndNeverContainTheSecret(): void
    {
        $this->transport->push(new TransportException('refused'), new TransportException('refused'));

        $this->client()->error('x');
        $this->assertSame([], $this->diagnostics);

        $this->client(['debug' => true])->error('x');
        $this->assertCount(1, $this->diagnostics);
        $this->assertStringContainsString('[InnLogger]', $this->diagnostics[0]);
        $this->assertStringContainsString('refused', $this->diagnostics[0]);
        $this->assertStringNotContainsString(TestCredentials::SECRET, $this->diagnostics[0]);
    }

    // --- heartbeat / test event ---------------------------------------------------------------

    public function testHeartbeat(): void
    {
        $this->transport->push(new Response(204));

        $result = $this->client(['log_level' => 0])->heartbeat();
        $request = $this->transport->last();

        $this->assertTrue($result->sent(), 'heartbeat ignores the threshold');
        $this->assertSame(204, $result->httpStatus);
        $this->assertSame('https://logger.example.com/api/v1/heartbeat', $request['url']);
        $this->assertSame(['environment' => 'production', 'hostname' => 'server01', 'application_version' => '1.4.2'], json_decode($request['body'], true));
        $this->assertSame(
            hash_hmac('sha256', $request['headers']['X-InnLogger-Timestamp'] . "\n" . $request['headers']['X-InnLogger-Nonce'] . "\n" . $request['body'], TestCredentials::SECRET),
            $request['headers']['X-InnLogger-Signature'],
        );
    }

    public function testTestEventBypassesThresholdAndEnabled(): void
    {
        $result = $this->client(['log_level' => 0, 'enabled' => false])->sendTestEvent('ping');

        $this->assertTrue($result->sent());
        $this->assertSame('ping', $this->transport->payload()['message']);
        $this->assertSame('innlogger-test', $this->transport->payload()['category']);
    }

    public function testBuildPayloadDoesNotSend(): void
    {
        $payload = $this->client()->buildPayload(Severity::NOTICE, 'n', ['a' => 1]);

        $this->assertSame(4, $payload['level']);
        $this->assertSame(0, $this->transport->count());
    }

    private function deep(int $depth): RuntimeException
    {
        return $depth === 0 ? new RuntimeException('deep') : $this->deep($depth - 1);
    }
}
