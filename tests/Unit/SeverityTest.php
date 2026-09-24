<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Tests\Unit;

use InnLogger\CodeIgniter4\Core\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SeverityTest extends TestCase
{
    public function testNamesMatchTheSpecScale(): void
    {
        $expected = [0 => 'OFF', 1 => 'CRITICAL', 2 => 'ERROR', 3 => 'WARNING', 4 => 'NOTICE', 5 => 'INFO', 6 => 'DEBUG', 7 => 'TRACE'];

        foreach ($expected as $level => $name) {
            $this->assertSame($name, Severity::name($level));
        }
    }

    public static function thresholdCases(): array
    {
        return [
            'threshold 0 sends nothing' => [0, []],
            'threshold 1 sends critical only' => [1, [1]],
            'threshold 2 sends critical + error' => [2, [1, 2]],
            'threshold 5 sends critical..info' => [5, [1, 2, 3, 4, 5]],
            'threshold 7 sends everything' => [7, [1, 2, 3, 4, 5, 6, 7]],
        ];
    }

    #[DataProvider('thresholdCases')]
    public function testShouldSendFollowsTheThreshold(int $threshold, array $sent): void
    {
        foreach (range(1, 7) as $level) {
            $this->assertSame(in_array($level, $sent, true), Severity::shouldSend($level, $threshold), "level {$level} threshold {$threshold}");
        }

        $this->assertFalse(Severity::shouldSend(0, $threshold), 'level 0 is never an event');
        $this->assertFalse(Severity::shouldSend(8, $threshold));
    }

    public function testResolvesNamesAndPsr3Aliases(): void
    {
        $this->assertSame(1, Severity::fromMixed('emergency'));
        $this->assertSame(1, Severity::fromMixed('ALERT'));
        $this->assertSame(2, Severity::fromMixed('Error'));
        $this->assertSame(7, Severity::fromMixed('trace'));
        $this->assertSame(3, Severity::fromMixed('3'));
        $this->assertNull(Severity::fromMixed('verbose'));
        $this->assertNull(Severity::fromMixed(9));
    }

    public function testNormalizesThresholds(): void
    {
        $this->assertSame(0, Severity::normalizeThreshold(null));
        $this->assertSame(0, Severity::normalizeThreshold(''));
        $this->assertSame(0, Severity::normalizeThreshold(-3));
        $this->assertSame(7, Severity::normalizeThreshold(42));
        $this->assertSame(2, Severity::normalizeThreshold('error'));
        $this->assertSame(5, Severity::normalizeThreshold('5'));
        $this->assertSame(0, Severity::normalizeThreshold('nonsense'));
    }
}
