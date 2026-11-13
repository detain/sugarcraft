<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Backoff;

final class BackoffTest extends TestCase
{
    public function testCeilingDoublesFromBaseAndCaps(): void
    {
        $b = Backoff::new(500, 2.0, 4000);
        $seq = array_map($b->forAttempt(...), [1, 2, 3, 4, 5, 6]);
        // Byte-compatible with TransientFailure: 0.5s * 2^(n-1), capped.
        $this->assertSame([500, 1000, 2000, 4000, 4000, 4000], $seq);
        $this->assertSame(0, $b->forAttempt(0));
    }

    public function testRemoteSessionHostScheduleIs1BasedShift(): void
    {
        $b = Backoff::new(250, 2.0, 4000);
        $this->assertSame([250, 500, 1000, 2000, 4000, 4000], array_map(fn (int $a) => $b->forAttempt($a + 1), [0, 1, 2, 3, 4, 5]));
    }

    public function testFixedTickFactorOne(): void
    {
        $b = Backoff::new(50, 1.0);
        $this->assertSame([50, 50, 50], [$b->forAttempt(1), $b->forAttempt(2), $b->forAttempt(3)]);
    }

    public function testUncappedHugeAttemptSaturates(): void
    {
        $this->assertSame(PHP_INT_MAX, Backoff::new(500)->forAttempt(2000));
    }

    public function testFullJitterDrawsOverZeroToCeilingAndClamps(): void
    {
        $seen = [];
        $b = Backoff::new(500, 2.0, 4000)->withJitter(function (int $max) use (&$seen): int {
            $seen[] = $max;
            return $max + 999;
        });
        $this->assertSame(1000, $b->forAttempt(2), 'rng over the ceiling is clamped');
        $this->assertSame([1000], $seen);
        $this->assertSame(0, $b->withJitter(static fn (int $m): int => -5)->forAttempt(2));
        $this->assertSame(0, $b->withJitter(static fn (int $m): int => 0)->forAttempt(3));
        $this->assertSame(1000, $b->ceiling(2), 'ceiling stays deterministic');
    }

    public function testDefaultJitterStaysInRange(): void
    {
        $b = Backoff::new(100, 2.0, 800)->withJitter();
        for ($i = 0; $i < 200; $i++) {
            $d = $b->forAttempt(3);
            $this->assertGreaterThanOrEqual(0, $d);
            $this->assertLessThanOrEqual(400, $d);
        }
    }

    public function testWithoutJitterRestoresCeiling(): void
    {
        $b = Backoff::new(100)->withJitter(static fn (int $m): int => 1)->withoutJitter();
        $this->assertSame(200, $b->forAttempt(2));
    }

    public function testServerHintWinsUnjitteredAndUncapped(): void
    {
        $b = Backoff::new(500, 2.0, 1000)->withJitter(static fn (int $m): int => 0)->serverHint(7.25);
        $this->assertSame(7250, $b->forAttempt(1));
        $this->assertSame(7250, $b->forAttempt(9));
        $this->assertSame(7.25, $b->secondsForAttempt(1));
        $this->assertSame(0, $b->serverHint(0.0)->forAttempt(1), 'hint of 0 means retry now');
        $this->assertSame(0, $b->serverHint(null)->forAttempt(1), 'cleared hint falls back to the jitter draw');
    }

    public function testAttemptsNullRetriesForever(): void
    {
        $b = Backoff::new(10);
        $this->assertTrue($b->allows(1_000_000));
        $three = $b->withAttempts(3);
        $this->assertSame([true, true, false], [$three->allows(1), $three->allows(2), $three->allows(3)]);
        $this->assertTrue($three->withAttempts(null)->allows(3));
    }

    public function testImmutable(): void
    {
        $a = Backoff::new(10);
        $a->withJitter()->serverHint(1.0)->withAttempts(2);
        $this->assertSame(20, $a->forAttempt(2));
        $this->assertTrue($a->allows(5));
    }

    /** @dataProvider badArgs */
    public function testRejectsBadArgs(callable $make): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $make();
    }

    public static function badArgs(): array
    {
        return [
            'zero base' => [static fn () => Backoff::new(0)],
            'nan base' => [static fn () => Backoff::new(NAN)],
            'shrinking factor' => [static fn () => Backoff::new(1, 0.5)],
            'negative cap' => [static fn () => Backoff::new(1, 2.0, -1)],
            'negative hint' => [static fn () => Backoff::new(1)->serverHint(-1.0)],
            'zero attempts' => [static fn () => Backoff::new(1)->withAttempts(0)],
        ];
    }
}
