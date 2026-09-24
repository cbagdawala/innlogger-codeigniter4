<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Unit;

use DateTimeImmutable;
use InnLogger\CodeIgniter4\Core\Redactor;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedactorTest extends TestCase
{
    public function testRedactsEveryDefaultKey(): void
    {
        $input = array_fill_keys(Redactor::DEFAULT_KEYS, 'sensitive-value');
        $input['safe'] = 'kept';

        $out = (new Redactor())->redact($input);

        foreach (Redactor::DEFAULT_KEYS as $key) {
            $this->assertSame(Redactor::REPLACEMENT, $out[$key], $key);
        }

        $this->assertSame('kept', $out['safe']);
    }

    public function testRedactionIsCaseInsensitiveAndRecursive(): void
    {
        $out = (new Redactor())->redact([
            'user' => [
                'email' => 'a@example.com',
                'PASSWORD' => 'hunter2',
                'profile' => ['Access_Token' => 'tok', 'cards' => [['Card_Number' => '4111', 'CVV' => '123', 'last4' => '1111']]],
            ],
            'headers' => ['Authorization' => 'Bearer x', 'Cookie' => 'sid=1', 'X-Api-Secret' => 'nope', 'Accept' => 'json'],
        ]);

        $this->assertSame('a@example.com', $out['user']['email']);
        $this->assertSame('[REDACTED]', $out['user']['PASSWORD']);
        $this->assertSame('[REDACTED]', $out['user']['profile']['Access_Token']);
        $this->assertSame('[REDACTED]', $out['user']['profile']['cards'][0]['Card_Number']);
        $this->assertSame('[REDACTED]', $out['user']['profile']['cards'][0]['CVV']);
        $this->assertSame('1111', $out['user']['profile']['cards'][0]['last4']);
        $this->assertSame('[REDACTED]', $out['headers']['Authorization']);
        $this->assertSame('[REDACTED]', $out['headers']['Cookie']);
        $this->assertSame('json', $out['headers']['Accept']);
    }

    public function testRedactsWholeSubtreeUnderASensitiveKey(): void
    {
        $out = (new Redactor())->redact(['token' => ['nested' => 'x']]);

        $this->assertSame('[REDACTED]', $out['token']);
    }

    public function testCustomKeysAreAddedToTheDefaults(): void
    {
        $redactor = new Redactor(['ssn', 'Pin_Code']);
        $out = $redactor->redact(['SSN' => '123-45-6789', 'pin_code' => '0000', 'password' => 'x', 'name' => 'Ann']);

        $this->assertSame('[REDACTED]', $out['SSN']);
        $this->assertSame('[REDACTED]', $out['pin_code']);
        $this->assertSame('[REDACTED]', $out['password']);
        $this->assertSame('Ann', $out['name']);
    }

    public function testObjectsAreNotDumpedThroughTheirPrivateState(): void
    {
        $secretHolder = new class () {
            private string $password = 'hunter2';
        };
        $json = new class () implements JsonSerializable {
            public function jsonSerialize(): array
            {
                return ['token' => 'abc', 'id' => 7];
            }
        };

        $out = (new Redactor())->redact([
            'obj' => $secretHolder,
            'json' => $json,
            'when' => new DateTimeImmutable('2026-09-24T10:00:00+00:00'),
            'error' => new RuntimeException('boom'),
            'inf' => INF,
        ]);

        $this->assertStringStartsWith('[object ', $out['obj']);
        $this->assertSame(['token' => '[REDACTED]', 'id' => 7], $out['json']);
        $this->assertSame('2026-09-24T10:00:00+00:00', $out['when']);
        $this->assertSame(RuntimeException::class, $out['error']['class']);
        $this->assertSame('INF', $out['inf']);
        $this->assertStringNotContainsString('hunter2', json_encode($out));
    }

    public function testDepthIsBounded(): void
    {
        $deep = 'leaf';

        for ($i = 0; $i < 30; $i++) {
            $deep = ['n' => $deep];
        }

        $this->assertStringContainsString('[max depth]', json_encode((new Redactor())->redact($deep)));
    }

    public function testRedactsSensitiveQueryParameters(): void
    {
        $redactor = new Redactor();

        $this->assertSame('/pay?token=%5BREDACTED%5D&amount=5', $redactor->redactUrl('/pay?token=abc&amount=5'));
        $this->assertSame('/p?Password=%5BREDACTED%5D#frag', $redactor->redactUrl('/p?Password=x#frag'));
        $this->assertSame('/plain', $redactor->redactUrl('/plain'));
    }
}
