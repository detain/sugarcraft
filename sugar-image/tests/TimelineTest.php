<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Timeline;

/**
 * @covers \SugarCraft\Image\Timeline
 */
final class TimelineTest extends TestCase
{
    public function testEachTileCoversItsOwnSlice(): void
    {
        // 10 tiles over 20 s: step 2 s.
        $timeline = Timeline::new(10, 20.0);

        $this->assertSame(0, $timeline->tileAt(0.0));
        $this->assertSame(0, $timeline->tileAt(1.999));
        $this->assertSame(1, $timeline->tileAt(2.0));
        $this->assertSame(4, $timeline->tileAt(9.5));
        $this->assertSame(9, $timeline->tileAt(19.999));
    }

    public function testPositionAtOrPastTheEndClampsToTheLastTile(): void
    {
        $timeline = Timeline::new(10, 20.0);

        $this->assertSame(9, $timeline->tileAt(20.0));
        $this->assertSame(9, $timeline->tileAt(1.0e18), 'a huge overshoot must not wrap through the int cast');
    }

    public function testNegativePositionClampsToTheFirstTile(): void
    {
        $this->assertSame(0, Timeline::new(10, 20.0)->tileAt(-5.0));
    }

    public function testABoundaryOnANonBinaryStepLandsInItsOwnTile(): void
    {
        // Step 0.1 is inexact in binary: the boundary at 0.3 belongs to tile 3
        // (the division by the rounded step used to answer tile 2).
        $timeline = Timeline::new(10, 1.0);

        $this->assertSame(1, $timeline->tileAt(0.1));
        $this->assertSame(2, $timeline->tileAt(0.2));
        $this->assertSame(3, $timeline->tileAt(0.3));
        $this->assertSame(6, $timeline->tileAt(0.6));
    }

    public function testSingleTileTimelineAlwaysAnswersZero(): void
    {
        $timeline = Timeline::new(1, 3.0);

        $this->assertSame(0, $timeline->tileAt(0.0));
        $this->assertSame(0, $timeline->tileAt(3.0));
        $this->assertSame(0, $timeline->tileAt(99.0));
    }

    public function testNonFinitePositionIsRefused(): void
    {
        $timeline = Timeline::new(4, 8.0);

        $this->expectException(\InvalidArgumentException::class);
        $timeline->tileAt(NAN);
    }

    public function testInfinitePositionIsRefused(): void
    {
        $timeline = Timeline::new(4, 8.0);

        $this->expectException(\InvalidArgumentException::class);
        $timeline->tileAt(INF);
    }

    public function testZeroTilesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timeline::new(0, 8.0);
    }

    public function testZeroDurationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timeline::new(4, 0.0);
    }
}
