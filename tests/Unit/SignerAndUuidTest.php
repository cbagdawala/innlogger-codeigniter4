<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Unit;

use InnLogger\CodeIgniter4\Core\Signer;
use InnLogger\CodeIgniter4\Core\Uuid;
use PHPUnit\Framework\TestCase;

final class SignerAndUuidTest extends TestCase
{
    public function testSignatureIsLowercaseHexHmacOverTimestampNonceAndBody(): void
    {
        $body = '{"message":"hello"}';
        $signature = Signer::sign('1790000000', 'abc123', $body, 'ils_secret');

        // Independent recomputation of the spec formula.
        $expected = bin2hex(hash_hmac('sha256', "1790000000\nabc123\n" . $body, 'ils_secret', true));

        $this->assertSame($expected, $signature);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $signature);
    }

    public function testKnownVector(): void
    {
        // Pinned vector computed outside PHP:
        //   printf '1700000000\nnonce-1\n{"a":1}' | openssl dgst -sha256 -hmac secret
        $this->assertSame(
            '2bf74f9c598310f67e72cdd84a451b1fe472228c6dc4ec1d46401c70bdce5fdd',
            Signer::sign('1700000000', 'nonce-1', '{"a":1}', 'secret'),
        );
    }

    public function testHeadersCarryTheContract(): void
    {
        $signer = new Signer('ilv_key', 'ils_secret');
        $headers = $signer->headers('{"x":1}', 'req-1', 1700000000, 'n0nce');

        $this->assertSame('ilv_key', $headers['X-InnLogger-Key']);
        $this->assertSame('1700000000', $headers['X-InnLogger-Timestamp']);
        $this->assertSame('n0nce', $headers['X-InnLogger-Nonce']);
        $this->assertSame('req-1', $headers['X-InnLogger-Request-Id']);
        $this->assertSame(hash_hmac('sha256', "1700000000\nn0nce\n{\"x\":1}", 'ils_secret'), $headers['X-InnLogger-Signature']);
        $this->assertSame('application/json', $headers['Content-Type']);
        $this->assertNotContains('ils_secret', $headers);
    }

    public function testNoncesAreRandom(): void
    {
        $nonces = array_map(static fn (): string => Signer::nonce(), range(1, 50));

        $this->assertCount(50, array_unique($nonces));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $nonces[0]);
    }

    public function testDebugOutputHidesTheSecret(): void
    {
        $this->assertStringNotContainsString('ils_secret', print_r(new Signer('ilv_key', 'ils_secret'), true));
    }

    public function testUuidV4(): void
    {
        $ids = array_map(static fn (): string => Uuid::v4(), range(1, 100));

        foreach ($ids as $id) {
            $this->assertMatchesRegularExpression(Uuid::PATTERN, $id);
        }

        $this->assertCount(100, array_unique($ids));
    }
}
