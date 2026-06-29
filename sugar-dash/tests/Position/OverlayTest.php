<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Position;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Position\Overlay;

/**
 * Tests for the overlay positioning helper.
 */
final class OverlayTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // fitsBelow
    // ═══════════════════════════════════════════════════════════════

    public function testFitsBelowTrueWhenSpaceAvailable(): void
    {
        // overlay height 3, anchor at 0, container 10
        // belowY = 0 + 1 = 1; 1 + 3 = 4 <= 10 → fits
        $this->assertTrue(Overlay::fitsBelow(3, 0, 10));
    }

    public function testFitsBelowFalseWhenTooCloseToBottom(): void
    {
        // belowY = 5 + 1 = 6; 6 + 5 = 11 > 10 → doesn't fit
        $this->assertFalse(Overlay::fitsBelow(5, 5, 10));
    }

    public function testFitsBelowAtExactBoundary(): void
    {
        // belowY = 6 + 1 = 7; 7 + 3 = 10 <= 10 → fits
        $this->assertTrue(Overlay::fitsBelow(3, 6, 10));
    }

    public function testFitsBelowAtAnchorZero(): void
    {
        $this->assertTrue(Overlay::fitsBelow(1, 0, 10));
    }

    // ═══════════════════════════════════════════════════════════════
    // fitsAbove
    // ═══════════════════════════════════════════════════════════════

    public function testFitsAboveTrueWhenSpaceAvailable(): void
    {
        // aboveY = 5 - 3 = 2 >= 0 → fits
        $this->assertTrue(Overlay::fitsAbove(3, 5));
    }

    public function testFitsAboveFalseWhenAtTop(): void
    {
        // aboveY = 0 - 3 = -3 < 0 → doesn't fit
        $this->assertFalse(Overlay::fitsAbove(3, 0));
    }

    public function testFitsAboveFalseWhenNotEnoughSpace(): void
    {
        // aboveY = 2 - 5 = -3 < 0 → doesn't fit
        $this->assertFalse(Overlay::fitsAbove(5, 2));
    }

    public function testFitsAboveAtExactlyZero(): void
    {
        // aboveY = 3 - 3 = 0 >= 0 → fits
        $this->assertTrue(Overlay::fitsAbove(3, 3));
    }

    // ═══════════════════════════════════════════════════════════════
    // calculate — below placement
    // ═══════════════════════════════════════════════════════════════

    public function testCalculatePrefersBelowWhenSpaceAvailable(): void
    {
        $result = Overlay::calculate(5, 3, 0, 0, 20, 20);
        $this->assertSame('below', $result['position']);
        $this->assertSame(1, $result['y']); // anchorY + 1
    }

    public function testCalculateXClampedToContainerBounds(): void
    {
        // If overlay would overflow right edge
        $result = Overlay::calculate(15, 5, 10, 0, 15, 20);
        // x = anchorX, but anchorX + overlayWidth > containerWidth
        // so x = containerWidth - overlayWidth = 0
        $this->assertLessThanOrEqual(15, $result['x'] + 15);
    }

    public function testCalculateXAlignmentLeftEdge(): void
    {
        $result = Overlay::calculate(5, 3, 2, 5, 20, 20);
        // x aligns with anchorX
        $this->assertSame(2, $result['x']);
    }

    // ═══════════════════════════════════════════════════════════════
    // calculate — above placement
    // ═══════════════════════════════════════════════════════════════

    public function testCalculateFallsBackToAboveWhenBelowDoesNotFit(): void
    {
        // below: 10 + 1 + 8 = 19 > 15 (container height 15) → doesn't fit
        // above: 10 - 8 = 2 >= 0 → fits above
        $result = Overlay::calculate(10, 8, 0, 10, 20, 15);
        $this->assertSame('above', $result['position']);
        $this->assertSame(2, $result['y']); // anchorY - overlayHeight
    }

    // ═══════════════════════════════════════════════════════════════
    // calculate — center fallback
    // ═══════════════════════════════════════════════════════════════

    public function testCalculateCenterFallbackWhenNeitherFits(): void
    {
        // below: 15 + 1 + 10 = 26 > 20 → doesn't fit
        // above: 15 - 10 = 5 >= 0 → actually fits above, so won't be center
        // Let's use a case where neither fits:
        // anchorY = 0, overlayHeight = 15 → below: 0+1+15=16>15? no, fits!  
        // Let me use anchorY = 15, overlayHeight = 10
        // below: 15+1+10=26 > 20 → doesn't fit
        // above: 15-10=5 >= 0 → fits above
        $result = Overlay::calculate(5, 10, 0, 15, 20, 20);
        // Actually this fits above. Let me find a true center case.
        // anchorY=17, overlayHeight=10: below=17+1+10=28>20 ✗, above=17-10=7≥0 ✓ → above
        // Need anchorY where neither: anchorY=19, overlayHeight=10
        // below: 19+1+10=30>20 ✗, above: 19-10=9≥0 ✓ → above
        // Actually with positive anchorY and reasonable heights, above usually fits.
        // True center: when overlayHeight > anchorY and (anchorY+1+overlayHeight) > containerHeight
        // anchorY=19, overlayHeight=20: below=19+1+20=40>20 ✗, above=19-20=-1<0 ✗ → center
        $result = Overlay::calculate(5, 20, 0, 19, 20, 20);
        $this->assertSame('center', $result['position']);
    }

    public function testCalculateCenterFallbackYValue(): void
    {
        // True center case: anchorY=19, overlayHeight=20
        $result = Overlay::calculate(5, 20, 0, 19, 20, 20);
        $this->assertSame('center', $result['position']);
        // Y should be max(0, floor((20-20)/2)) = 0
        $this->assertSame(0, $result['y']);
    }

    // ═══════════════════════════════════════════════════════════════
    // calculate — x position clamping
    // ═══════════════════════════════════════════════════════════════

    public function testCalculateOverlayWiderThanContainer(): void
    {
        // Overlay wider than container: should clamp x to 0
        $result = Overlay::calculate(25, 5, 0, 0, 20, 20);
        $this->assertSame(0, $result['x']);
        // Width 25 is clamped to 20, so x=0 fits
        $this->assertLessThanOrEqual(20, $result['x'] + 20);
    }

    public function testCalculateNegativeAnchorX(): void
    {
        // Negative anchorX clamped to 0
        $result = Overlay::calculate(5, 3, -5, 0, 20, 20);
        $this->assertGreaterThanOrEqual(0, $result['x']);
    }
}
