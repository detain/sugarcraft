<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Breakpoint;

final class BreakpointTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════════
    // narrow()
    // ═══════════════════════════════════════════════════════════════════

    public function testNarrowBelowThreshold(): void
    {
        $this->assertTrue(Breakpoint::narrow(80));
        $this->assertTrue(Breakpoint::narrow(89));
        $this->assertTrue(Breakpoint::narrow(1));
        $this->assertTrue(Breakpoint::narrow(0));
    }

    public function testNarrowAtThresholdIsFalse(): void
    {
        // At threshold (90 by default) is NOT narrow — boundary is exclusive.
        $this->assertFalse(Breakpoint::narrow(90));
        $this->assertFalse(Breakpoint::narrow(91));
        $this->assertFalse(Breakpoint::narrow(120));
    }

    public function testNarrowWithCustomThreshold(): void
    {
        $this->assertTrue(Breakpoint::narrow(50, 60));
        $this->assertFalse(Breakpoint::narrow(60, 60));
        $this->assertFalse(Breakpoint::narrow(70, 60));
    }

    // ═══════════════════════════════════════════════════════════════════
    // wide()
    // ═══════════════════════════════════════════════════════════════════

    public function testWideAboveThreshold(): void
    {
        $this->assertTrue(Breakpoint::wide(140));
        $this->assertTrue(Breakpoint::wide(200));
    }

    public function testWideBelowThresholdIsFalse(): void
    {
        $this->assertFalse(Breakpoint::wide(139));
        $this->assertFalse(Breakpoint::wide(90));
        $this->assertFalse(Breakpoint::wide(0));
    }

    public function testWideWithCustomThreshold(): void
    {
        $this->assertTrue(Breakpoint::wide(100, 100));
        $this->assertFalse(Breakpoint::wide(99, 100));
    }

    // ═══════════════════════════════════════════════════════════════════
    // medium()
    // ═══════════════════════════════════════════════════════════════════

    public function testMediumInRange(): void
    {
        // Default: narrow=90, wide=140
        $this->assertTrue(Breakpoint::medium(90));
        $this->assertTrue(Breakpoint::medium(100));
        $this->assertTrue(Breakpoint::medium(139));
    }

    public function testMediumAtBoundaries(): void
    {
        // At narrow boundary (90) — exclusive lower, inclusive upper? No.
        // medium(90, 90, 140) → 90 >= 90 && 90 < 140 → true
        // At wide boundary (140) — 140 >= 90 && 140 < 140 → false
        $this->assertTrue(Breakpoint::medium(90));
        $this->assertFalse(Breakpoint::medium(140));
    }

    public function testMediumOutsideRange(): void
    {
        $this->assertFalse(Breakpoint::medium(80));
        $this->assertFalse(Breakpoint::medium(150));
    }

    public function testMediumWithCustomBounds(): void
    {
        // Within range (exclusive lower, inclusive upper on the upper bound)
        $this->assertTrue(Breakpoint::medium(50, 40, 60));
        $this->assertTrue(Breakpoint::medium(40, 40, 60));  // at lower bound — inclusive, so in range
        $this->assertFalse(Breakpoint::medium(60, 40, 60));   // at upper bound — exclusive, so out of range
    }

    // ═══════════════════════════════════════════════════════════════════
    // pick()
    // ═══════════════════════════════════════════════════════════════════

    public function testPickReturnsMatchingBucket(): void
    {
        $thresholds = [
            'narrow' => 90,
            'medium' => 140,
            'wide' => null,
        ];

        $this->assertSame('narrow', Breakpoint::pick(80, $thresholds));
        $this->assertSame('narrow', Breakpoint::pick(89, $thresholds));
        $this->assertSame('medium', Breakpoint::pick(90, $thresholds));
        $this->assertSame('medium', Breakpoint::pick(139, $thresholds));
        $this->assertSame('wide', Breakpoint::pick(140, $thresholds));
        $this->assertSame('wide', Breakpoint::pick(200, $thresholds));
    }

    public function testPickWithCustomThresholds(): void
    {
        $thresholds = [
            'compact' => 50,
            'normal' => 100,
            'expanded' => null,
        ];

        $this->assertSame('compact', Breakpoint::pick(30, $thresholds));
        $this->assertSame('normal', Breakpoint::pick(75, $thresholds));
        $this->assertSame('expanded', Breakpoint::pick(150, $thresholds));
    }

    public function testPickFallsBackToNarrowWhenNoCatchall(): void
    {
        // When no null-boundary catch-all is provided, return 'narrow' as safe default.
        $thresholds = [
            'small' => 90,
            'large' => 140,
        ];

        $this->assertSame('small', Breakpoint::pick(80, $thresholds));
        $this->assertSame('narrow', Breakpoint::pick(200, $thresholds));
    }

    public function testPickWithEmptyThresholds(): void
    {
        // Empty thresholds → always falls back to 'narrow'.
        $this->assertSame('narrow', Breakpoint::pick(80, []));
        $this->assertSame('narrow', Breakpoint::pick(200, []));
    }

    public function testPickWithOnlyCatchall(): void
    {
        $thresholds = [
            'all' => null,
        ];

        $this->assertSame('all', Breakpoint::pick(1, $thresholds));
        $this->assertSame('all', Breakpoint::pick(999, $thresholds));
    }
}
