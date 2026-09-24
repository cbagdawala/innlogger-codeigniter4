<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Unit;

use InnLogger\CodeIgniter4\Core\ExceptionNormalizer;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExceptionNormalizerTest extends TestCase
{
    public function testNormalizesClassMessageFileLineAndTrace(): void
    {
        $line = __LINE__ + 1;
        $exception = new RuntimeException('Gateway timeout');

        $out = ExceptionNormalizer::normalize($exception);

        $this->assertSame(['class', 'message', 'file', 'line', 'trace'], array_keys($out));
        $this->assertSame(RuntimeException::class, $out['class']);
        $this->assertSame('Gateway timeout', $out['message']);
        $this->assertSame(__FILE__, $out['file']);
        $this->assertSame($line, $out['line']);
        $this->assertStringContainsString('#0 ', $out['trace']);
    }

    public function testIncludesPreviousExceptions(): void
    {
        $out = ExceptionNormalizer::normalize(new RuntimeException('outer', 0, new LogicException('inner cause')));

        $this->assertStringContainsString('Caused by: LogicException: inner cause', $out['trace']);
    }

    public function testTraceIsTruncatedTo64Kb(): void
    {
        $exception = $this->deep(1500);
        $raw = strlen($exception->getTraceAsString());

        $out = ExceptionNormalizer::normalize($exception);

        $this->assertGreaterThan(65536, $raw, 'fixture must produce a trace over 64 KB');
        $this->assertLessThanOrEqual(65536, strlen($out['trace']));
        $this->assertStringEndsWith('[trace truncated]', $out['trace']);
    }

    public function testTruncationNeverSplitsUtf8(): void
    {
        $value = str_repeat('é', 100); // 200 bytes
        $cut = ExceptionNormalizer::truncate($value, 51, '');

        $this->assertLessThanOrEqual(51, strlen($cut));
        $this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
    }

    private function deep(int $depth): RuntimeException
    {
        if ($depth === 0) {
            return new RuntimeException('deep with a long message to pad the trace lines out');
        }

        return $this->deep($depth - 1);
    }
}
