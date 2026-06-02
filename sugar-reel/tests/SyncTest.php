<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Sync;

/**
 * Unit tests for Sync wall-clock pacing math.
 *
 * @covers \SugarCraft\Reel\Sync
 */
final class SyncTest extends TestCase
{
    // -------------------------------------------------------------------------
    // targetFrame
    // -------------------------------------------------------------------------

    /**
     * @testdox targetFrame(0.0, 30.0, 1.0) returns 0
     */
    public function testTargetFrameAtTimeZero(): void
    {
        $this->assertSame(0, Sync::targetFrame(0.0, 30.0, 1.0));
    }

    /**
     * @testdox targetFrame(1.0, 30.0, 1.0) returns 30 (one second at 30 fps)
     */
    public function testTargetFrameAt1Second(): void
    {
        $this->assertSame(30, Sync::targetFrame(1.0, 30.0, 1.0));
    }

    /**
     * @testdox targetFrame(1.0, 30.0, 2.0) returns 60 (2× speed)
     */
    public function testTargetFrameWithSpeed2(): void
    {
        $this->assertSame(60, Sync::targetFrame(1.0, 30.0, 2.0));
    }

    /**
     * @testdox targetFrame(1.0, 30.0, 0.5) returns 15 (0.5× speed)
     */
    public function testTargetFrameWithSpeed05(): void
    {
        $this->assertSame(15, Sync::targetFrame(1.0, 30.0, 0.5));
    }

    /**
     * @testdox targetFrame(0.5, 29.97, 1.0) returns floor(0.5 * 29.97) = 14
     */
    public function testTargetFrameAtFpsNonInteger(): void
    {
        $this->assertSame(14, Sync::targetFrame(0.5, 29.97, 1.0));
    }

    /**
     * @testdox targetFrame returns 0 for negative elapsed time (guard)
     */
    public function testTargetFrameNegativeReturnsZero(): void
    {
        $this->assertSame(0, Sync::targetFrame(-1.0, 30.0, 1.0));
        $this->assertSame(0, Sync::targetFrame(-0.001, 30.0, 1.0));
    }

    // -------------------------------------------------------------------------
    // shouldSkip
    // -------------------------------------------------------------------------

    /**
     * @testdox shouldSkip(0, 3) returns true when behind by more than 2 frames
     */
    public function testShouldSkipReturnsTrueWhenBehindByMoreThan2(): void
    {
        $this->assertTrue(Sync::shouldSkip(0, 3));
        $this->assertTrue(Sync::shouldSkip(5, 10));
    }

    /**
     * @testdox shouldSkip(0, 2) returns false when behind by exactly 2 frames
     */
    public function testShouldSkipReturnsFalseWhenBehindBy2(): void
    {
        $this->assertFalse(Sync::shouldSkip(0, 2));
        $this->assertFalse(Sync::shouldSkip(10, 12));
    }

    /**
     * @testdox shouldSkip(5, 3) returns false when ahead (current > target)
     */
    public function testShouldSkipReturnsFalseWhenAhead(): void
    {
        $this->assertFalse(Sync::shouldSkip(5, 3));
        $this->assertFalse(Sync::shouldSkip(10, 5));
    }

    /**
     * @testdox shouldSkip(3, 3) returns false when at target
     */
    public function testShouldSkipReturnsFalseWhenAtTarget(): void
    {
        $this->assertFalse(Sync::shouldSkip(3, 3));
    }

    // -------------------------------------------------------------------------
    // shouldHold
    // -------------------------------------------------------------------------

    /**
     * @testdox shouldHold(5, 3) returns true when ahead of schedule
     */
    public function testShouldHoldReturnsTrueWhenAhead(): void
    {
        $this->assertTrue(Sync::shouldHold(5, 3));
        $this->assertTrue(Sync::shouldHold(10, 5));
    }

    /**
     * @testdox shouldHold(2, 5) returns false when behind
     */
    public function testShouldHoldReturnsFalseWhenBehind(): void
    {
        $this->assertFalse(Sync::shouldHold(2, 5));
        $this->assertFalse(Sync::shouldHold(0, 3));
    }

    /**
     * @testdox shouldHold(3, 3) returns false when at target
     */
    public function testShouldHoldReturnsFalseWhenAtTarget(): void
    {
        $this->assertFalse(Sync::shouldHold(3, 3));
    }
}
