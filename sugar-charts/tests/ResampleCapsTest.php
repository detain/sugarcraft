<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Charts\Aggregation\Resample;

/**
 * sugar-charts-040: the upsample walk length is caller-determined
 * (span / interval), so unbounded output is a platform hazard, and the
 * downsample bucket advance used to walk empty buckets one at a time.
 */
final class ResampleCapsTest extends TestCase
{
    /** Ten years apart at one-second resolution: 315 million projected rows. */
    private const FAR_PAIR = [[0, 1.0], [315360000, 2.0]];

    public function testLinearAcrossADecadeAtOneSecondThrowsInsteadOfAllocating(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('315360001');
        Resample::linear(1, self::FAR_PAIR);
    }

    public function testNearestAcrossADecadeAtOneSecondThrows(): void
    {
        $this->expectException(RuntimeException::class);
        Resample::nearest(1, self::FAR_PAIR);
    }

    public function testAutoRoutingResampleThrowsOnTheSamePair(): void
    {
        $this->expectException(RuntimeException::class);
        Resample::new(1)->resample(self::FAR_PAIR);
    }

    public function testCapBoundaryIsInclusive(): void
    {
        // rows = endTs - 0 + 1 at interval 1: exactly at the ceiling passes.
        $atCap = Resample::new(1)->resample([[0, 1.0], [Resample::MAX_RESAMPLE_POINTS - 1, 2.0]]);
        self::assertCount(Resample::MAX_RESAMPLE_POINTS, $atCap);

        $this->expectException(RuntimeException::class);
        Resample::new(1)->resample([[0, 1.0], [Resample::MAX_RESAMPLE_POINTS, 2.0]]);
    }

    public function testRaisedCapLetsOutputThroughThatTheDefaultRefuses(): void
    {
        $pair = [[0, 1.0], [150000, 2.0]];
        $this->expectException(RuntimeException::class);
        Resample::new(1)->resample($pair);
    }

    public function testRaisedCapPassesTheSameRows(): void
    {
        $rows = Resample::new(1, 0, 200000)->resample([[0, 1.0], [150000, 2.0]]);
        self::assertCount(150001, $rows);
        self::assertSame(0, $rows[0]['ts']);
        self::assertSame(150000, $rows[150000]['ts']);
    }

    public function testWithMaxPointsLowersTheCeiling(): void
    {
        // interval 2 over [0, 7] => 4 projected rows > lowered cap of 3.
        $this->expectException(RuntimeException::class);
        Resample::new(2)->withMaxPoints(3)->resample([[0, 1.0], [7, 2.0]]);
    }

    public function testWithMaxPointsRejectsNonPositive(): void
    {
        foreach ([0, -1] as $bad) {
            try {
                Resample::new(5)->withMaxPoints($bad);
                self::fail('expected refusal for ' . var_export($bad, true));
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString((string) $bad, $e->getMessage());
            }
        }
        // null restores the class ceiling rather than refusing.
        self::assertNull(Resample::new(5)->withMaxPoints(null)->maxPoints);
    }

    public function testDownsampleMeanCompletesOnASparseRangeWithoutWalkingEmptyBuckets(): void
    {
        $started = hrtime(true);
        $rows = Resample::mean(1, [[500000000, 7.0]]);
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertSame([['ts' => 500000000, 'value' => 7.0]], $rows);
        // The old per-empty-bucket walk needs ~5e8 iterations (tens of
        // seconds); the arithmetic jump is O(1).
        self::assertLessThan(50.0, $elapsedMs, 'downsample must not walk empty buckets');
    }

    public function testDownsampleLastCompletesOnASparseRange(): void
    {
        $started = hrtime(true);
        $rows = Resample::last(1, [[400000000, 3.0], [400000001, 4.0]]);
        self::assertLessThan(50.0, (hrtime(true) - $started) / 1_000_000);
        // start 0: first point lands in bucket 400000000, second in 400000001.
        self::assertSame(
            [['ts' => 400000000, 'value' => 3.0], ['ts' => 400000001, 'value' => 4.0]],
            $rows
        );
    }

    public function testDenseBucketResultsAreUnchangedByTheArithmeticJump(): void
    {
        $rows = Resample::mean(10, [[0, 1.0], [5, 2.0], [12, 3.0], [30, 4.0]]);
        self::assertSame(
            [['ts' => 0, 'value' => 1.5], ['ts' => 10, 'value' => 3.0], ['ts' => 30, 'value' => 4.0]],
            $rows
        );

        $last = Resample::last(10, [[0, 1.0], [5, 2.0], [12, 3.0], [30, 4.0]]);
        self::assertSame(
            [['ts' => 0, 'value' => 2.0], ['ts' => 10, 'value' => 3.0], ['ts' => 30, 'value' => 4.0]],
            $last
        );
    }

    public function testBucketAlignedExactlyOnBoundaryStillAdvances(): void
    {
        // pt at 20 with interval 10: the old loop advanced while
        // pt >= start+interval, so bucket 10 is the landing start.
        $rows = Resample::mean(10, [[0, 1.0], [20, 2.0]]);
        self::assertSame(
            [['ts' => 0, 'value' => 1.0], ['ts' => 20, 'value' => 2.0]],
            $rows
        );
    }
}
