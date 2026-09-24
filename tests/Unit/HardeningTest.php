<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Unit;

use InnLogger\CodeIgniter4\Core\InnLoggerClient;
use InnLogger\CodeIgniter4\Core\Redactor;
use InnLogger\CodeIgniter4\Core\SendResult;
use InnLogger\CodeIgniter4\Core\Settings;
use InnLogger\CodeIgniter4\Core\Signer;
use InnLogger\CodeIgniter4\Core\Transport\Response;
use InnLogger\CodeIgniter4\Core\Transport\TransportException;
use InnLogger\CodeIgniter4\Core\Uuid;
use InnLogger\CodeIgniter4\Tests\Support\FakeTransport;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

/**
 * Review-gate fixes: secret hygiene, value masking, default keys, category,
 * request ID, 429 back-off, retries default and timeout caps.
 */
final class HardeningTest extends TestCase
{
    private const SECRET = 'ils_HardeningSecret0123456789abcdefghijklmnopqrstu';
    private const PLAIN_SECRET = 'plain-secret-value-42';

    private FakeTransport $transport;
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
    }

    private function settings(array $overrides = []): Settings
    {
        return Settings::fromArray($overrides + [
            'url' => 'https://logger.example.com',
            'api_key' => 'ilv_key',
            'api_secret' => self::SECRET,
            'log_level' => 7,
            'retries' => 0,
            'retry_delay_ms' => 0,
            'hostname' => 'server01',
        ]);
    }

    private function client(array $overrides = []): InnLoggerClient
    {
        return new InnLoggerClient($this->settings($overrides), $this->transport, null, null, fn (): int => $this->now);
    }

    /**
     * @return list<string>
     */
    private function renderings(mixed $value): array
    {
        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();

        $out = [print_r($value, true), $dump, var_export($value, true), (string) json_encode($value)];

        try {
            $out[] = serialize($value);
        } catch (Throwable $e) {
            $out[] = $e->getMessage();
        }

        return $out;
    }

    // 1. secrets never leak through dumps or serialization -------------------------------

    public function testSettingsNeverRevealTheSecret(): void
    {
        $settings = $this->settings();

        foreach ($this->renderings($settings) as $i => $rendered) {
            $this->assertStringNotContainsString(self::SECRET, $rendered, "rendering #{$i}");
        }

        $this->assertSame(self::SECRET, $settings->apiSecret());
        $this->assertSame('(set)', $settings->describe()['api_secret']);
    }

    public function testUnserializedSettingsHaveNoSecret(): void
    {
        $copy = unserialize(serialize($this->settings()));

        $this->assertInstanceOf(Settings::class, $copy);
        $this->assertSame('https://logger.example.com', $copy->url);
        $this->assertSame('ilv_key', $copy->apiKey);
        $this->assertSame('', $copy->apiSecret());
        $this->assertFalse($copy->isValid());
    }

    public function testClientSignerAndRedactorNeverRevealTheSecret(): void
    {
        $client = $this->client();
        $client->error('warm up');

        foreach ([$client, new Signer('ilv_key', self::SECRET), new Redactor([], [], [self::PLAIN_SECRET])] as $object) {
            foreach ($this->renderings($object) as $i => $rendered) {
                $this->assertStringNotContainsString(self::SECRET, $rendered, $object::class . " rendering #{$i}");
                $this->assertStringNotContainsString(self::PLAIN_SECRET, $rendered, $object::class . " rendering #{$i}");
            }
        }
    }

    public function testClientCannotBeUnserialized(): void
    {
        $this->expectException(\LogicException::class);

        unserialize(serialize($this->client()));
    }

    // 2. value masking ---------------------------------------------------------------------

    public function testMessageIsMaskedBeforeSigning(): void
    {
        $this->client()->error('Upstream said: Authorization: Bearer eyJhbGciOi.payload.sig and key ' . self::SECRET);
        $request = $this->transport->last();
        $message = $this->transport->payload()['message'];

        $this->assertStringNotContainsString('eyJhbGciOi', $request['body']);
        $this->assertStringNotContainsString(self::SECRET, $request['body']);
        $this->assertStringContainsString('Bearer [REDACTED]', $message);
        $this->assertSame(
            hash_hmac('sha256', $request['headers']['X-InnLogger-Timestamp'] . "\n" . $request['headers']['X-InnLogger-Nonce'] . "\n" . $request['body'], self::SECRET),
            $request['headers']['X-InnLogger-Signature'],
            'the signature covers the masked body',
        );
    }

    public function testBasicDigestAndInnLoggerSecretsAreMasked(): void
    {
        $redactor = new Redactor();

        $this->assertSame('Basic [REDACTED]', $redactor->maskString('Basic dXNlcjpwYXNz'));
        $this->assertSame('digest [REDACTED]', $redactor->maskString('digest abc123=='));
        $this->assertSame('key=[REDACTED]', $redactor->maskString('key=ils_abcdefgh1234'));
        $this->assertSame('ils_short', $redactor->maskString('ils_short'));
    }

    public function testConfiguredPlainSecretIsMaskedEverywhere(): void
    {
        $client = $this->client(['api_secret' => self::PLAIN_SECRET]);

        $client->exception(
            new RuntimeException('login failed with ' . self::PLAIN_SECRET, 0, new RuntimeException('cause ' . self::PLAIN_SECRET)),
            ['note' => 'x' . self::PLAIN_SECRET . 'y', 'nested' => ['deep' => self::PLAIN_SECRET]],
        );

        $body = $this->transport->last()['body'];
        $this->assertStringNotContainsString(self::PLAIN_SECRET, $body);
        $this->assertStringContainsString('login failed with [REDACTED]', $this->transport->payload()['exception']['message']);
    }

    public function testShortLiteralSecretsAreNotMasked(): void
    {
        $redactor = new Redactor([], [], ['abc1234']);

        $this->assertSame('abc1234', $redactor->maskString('abc1234'));
        $this->assertSame('[REDACTED]', (new Redactor([], [], ['abcd1234']))->maskString('abcd1234'));
    }

    public function testTraceArgumentsAreMasked(): void
    {
        $previous = ini_get('zend.exception_ignore_args');
        ini_set('zend.exception_ignore_args', '0');

        try {
            $exception = $this->failWith(self::SECRET, 'Bearer tok_abcdef');
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $this->assertStringContainsString("'ils_Hardening", $exception->getTraceAsString(), 'fixture: PHP puts scalar args in the trace');

        $this->client()->exception($exception);
        $trace = $this->transport->payload()['exception']['trace'];

        $this->assertStringNotContainsString('ils_Hardening', $trace);
        $this->assertStringContainsString('failWith', $trace);
    }

    public function testCustomMaskPatternsListAndMap(): void
    {
        $client = $this->client(['mask_patterns' => [
            '/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]',
            '/acct-\d+/',
            '/(unclosed',
        ]]);

        $client->error('ssn 123-45-6789 acct-998877', ['text' => 'acct-1']);
        $payload = $this->transport->payload();

        $this->assertSame('ssn [SSN] [REDACTED]', $payload['message']);
        $this->assertSame('[REDACTED]', $payload['context']['text']);
        $this->assertCount(2, $client->settings()->maskPatterns, 'the invalid regex is dropped');
    }

    public function testMaskPatternsFromJsonString(): void
    {
        $this->assertSame(['/a/' => '[A]'], Settings::parsePatterns('{"/a/":"[A]"}'));
        $this->assertSame(['/a/', '/b/'], Settings::parsePatterns('["/a/","/b/"]'));
        $this->assertSame(['/x+/'], Settings::parsePatterns('/x+/'));
        $this->assertSame([], Settings::parsePatterns(''));
        $this->assertSame(['/a/' => '[REDACTED]'], Settings::fromArray(['mask_patterns' => '["/a/"]'])->maskPatterns);
    }

    // 3. default keys superset ---------------------------------------------------------------

    public function testDefaultKeysAreASupersetOfTheSpecList(): void
    {
        $spec = ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token', 'authorization', 'cookie', 'card_number', 'cvv', 'secret', 'api_secret'];
        $extra = ['current_password', 'new_password', 'id_token', 'proxy_authorization', 'set_cookie', 'cvc', 'client_secret', 'api_key', 'private_key', 'csrf_token', 'xsrf_token', 'x_xsrf_token', '_token', 'x_innlogger_signature'];

        $out = (new Redactor())->redact(array_fill_keys(array_merge($spec, $extra, ['X-XSRF-TOKEN', 'Set-Cookie', 'X-InnLogger-Signature']), 'v'));

        foreach ($out as $key => $value) {
            $this->assertSame('[REDACTED]', $value, (string) $key);
        }
    }

    // 4. category ------------------------------------------------------------------------------

    public function testCategoryIsAlwaysSent(): void
    {
        $client = $this->client();
        $client->info('plain');
        $client->exception(new RuntimeException('boom'));
        $client->error('with psr3 exception', ['exception' => new RuntimeException('x')]);
        $client->error('explicit', ['category' => 'payment']);
        $this->client(['category' => 'billing'])->warning('configured');

        $this->assertSame('application', $this->transport->payload(0)['category']);
        $this->assertSame('exception', $this->transport->payload(1)['category']);
        $this->assertSame('exception', $this->transport->payload(2)['category']);
        $this->assertSame('payment', $this->transport->payload(3)['category']);
        $this->assertSame('billing', $this->transport->payload(4)['category']);
        $this->assertSame('application', Settings::fromArray(['category' => ''])->category);
    }

    // 5. request ID --------------------------------------------------------------------------

    public function testRequestIdIsAFreshUuidPerSendStableAcrossRetries(): void
    {
        $this->transport->push(new TransportException('reset'), new Response(503), new Response(202, '{}'), new Response(202, '{}'));
        $client = $this->client(['retries' => 2]);

        $first = $client->error('a');
        $second = $client->error('b');

        $ids = array_map(static fn (array $r): string => $r['headers']['X-InnLogger-Request-Id'], $this->transport->requests);

        $this->assertCount(4, $ids);
        $this->assertMatchesRegularExpression(Uuid::PATTERN, $ids[0]);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame($ids[0], $ids[2]);
        $this->assertNotSame($ids[0], $ids[3], 'a new logical send gets a new request ID');
        $this->assertNotSame($first->eventId, $ids[0], 'not aliased to event_id');
        $this->assertNotSame($second->eventId, $ids[3]);
    }

    // 6. 429 back-off -------------------------------------------------------------------------

    public function testRateLimitPausesSendingForRetryAfterFromBody(): void
    {
        $this->transport->push(new Response(429, '{"success":false,"message":"Rate limit exceeded","retry_after":30}'));
        $client = $this->client(['retries' => 3]);

        $limited = $client->error('first');

        $this->assertSame(SendResult::FAILED, $limited->status);
        $this->assertSame(429, $limited->httpStatus);
        $this->assertSame(1, $this->transport->count(), '429 is not retried');
        $this->assertTrue($client->isPaused());

        $this->now += 29;
        $this->assertSame(SendResult::RATE_LIMITED, $client->critical('during pause')->status);
        $this->assertSame(SendResult::RATE_LIMITED, $client->heartbeat()->status);
        $this->assertSame(1, $this->transport->count());

        $this->now += 1;
        $this->assertTrue($client->error('after pause')->sent());
        $this->assertSame(2, $this->transport->count());
    }

    public function testRetryAfterHeaderIsUsedWhenTheBodyHasNone(): void
    {
        $this->transport->push(new Response(429, '', ['Retry-After' => '5']));
        $client = $this->client();

        $client->error('x');
        $this->now += 4;
        $this->assertTrue($client->isPaused());
        $this->now += 1;
        $this->assertFalse($client->isPaused());
    }

    public function testRetryAfterHttpDate(): void
    {
        $this->transport->push(new Response(429, '', ['Retry-After' => gmdate('D, d M Y H:i:s', $this->now + 120) . ' GMT']));
        $client = $this->client();

        $client->error('x');
        $this->now += 119;
        $this->assertTrue($client->isPaused());
        $this->now += 1;
        $this->assertFalse($client->isPaused());
    }

    public function testRetryAfterIsCappedBetweenOneSecondAndAnHour(): void
    {
        $this->transport->push(new Response(429, '{"retry_after":999999}'), new Response(429, '{"retry_after":0}'), new Response(429, '{}'));

        $client = $this->client();
        $client->error('huge');
        $this->now += 3599;
        $this->assertTrue($client->isPaused());
        $this->now += 1;
        $this->assertFalse($client->isPaused());

        $client->error('zero');
        $this->assertTrue($client->isPaused(), 'minimum 1 s');
        $this->now += 1;
        $this->assertFalse($client->isPaused());

        $client->error('missing');
        $this->now += 59;
        $this->assertTrue($client->isPaused(), 'default 60 s');
        $this->now += 1;
        $this->assertFalse($client->isPaused());
    }

    // 6b. retries default ----------------------------------------------------------------------

    public function testRetriesDefaultToOneAndAreCappedAtThree(): void
    {
        $this->assertSame(1, Settings::fromArray([])->retries);
        $this->assertSame(1, (new Settings())->retries);
        $this->assertSame(3, Settings::fromArray(['retries' => 10])->retries);
        $this->assertSame(0, Settings::fromArray(['retries' => -1])->retries);

        $this->transport->push(new TransportException('blip'));
        $settings = Settings::fromArray(['url' => 'https://logger.example.com', 'api_key' => 'k', 'api_secret' => 's', 'log_level' => 2, 'retry_delay_ms' => 0]);
        $result = (new InnLoggerClient($settings, $this->transport))->error('x');

        $this->assertTrue($result->sent());
        $this->assertSame(2, $result->attempts);
    }

    // 7. timeout caps --------------------------------------------------------------------------

    public function testTimeoutsAreCappedAt30Seconds(): void
    {
        $settings = Settings::fromArray(['timeout' => 120, 'connect_timeout' => 99]);

        $this->assertSame(30.0, $settings->timeout);
        $this->assertSame(30.0, $settings->connectTimeout);

        $defaults = Settings::fromArray(['timeout' => 0, 'connect_timeout' => -5]);
        $this->assertSame(2.0, $defaults->timeout);
        $this->assertSame(1.0, $defaults->connectTimeout);

        $this->assertSame(30.0, (new Settings(timeout: 1e9))->timeout);
    }

    private function failWith(string $secret, string $header): RuntimeException
    {
        return new RuntimeException('failed');
    }
}
