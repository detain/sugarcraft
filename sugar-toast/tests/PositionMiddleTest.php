<?php

declare(strict_types=1);

namespace SugarCraft\Toast\Tests;

use SugarCraft\Toast\{Position, Toast, ToastType};
use PHPUnit\Framework\TestCase;

final class PositionMiddleTest extends TestCase
{
    public function testMiddleLeftXOffset(): void
    {
        // MiddleLeft aligns left, same as TopLeft/BottomLeft
        $this->assertSame(0, Position::MiddleLeft->xOffset(20, 80));
    }

    public function testMiddleCenterXOffset(): void
    {
        // MiddleCenter is horizontally centered
        $this->assertSame(30, Position::MiddleCenter->xOffset(20, 80));
    }

    public function testMiddleRightXOffset(): void
    {
        // MiddleRight aligns right
        $this->assertSame(60, Position::MiddleRight->xOffset(20, 80));
    }

    public function testMiddleLeftYOffsetWithoutStacking(): void
    {
        // MiddleLeft with no stacking: vertically centered
        // centerY = floor((24 - 3) / 2) = 10
        $this->assertSame(10, Position::MiddleLeft->yOffset(3, 24, 0));
    }

    public function testMiddleCenterYOffsetWithoutStacking(): void
    {
        // MiddleCenter with no stacking: vertically centered
        $this->assertSame(10, Position::MiddleCenter->yOffset(3, 24, 0));
    }

    public function testMiddleRightYOffsetWithoutStacking(): void
    {
        // MiddleRight with no stacking: vertically centered
        $this->assertSame(10, Position::MiddleRight->yOffset(3, 24, 0));
    }

    public function testMiddleYOffsetWithStacking(): void
    {
        // With stacking, middle positions stack upward (y decreases)
        // First alert: centerY = floor((24 - 3) / 2) = 10
        $this->assertSame(10, Position::MiddleCenter->yOffset(3, 24, 0));

        // Second alert (stacked above first): centerY - h1 = 10 - 3 = 7
        $this->assertSame(7, Position::MiddleCenter->yOffset(3, 24, 3));

        // Third alert (stacked above first two): centerY - h1 - h2 = 10 - 3 - 3 = 4
        $this->assertSame(4, Position::MiddleCenter->yOffset(3, 24, 6));
    }

    public function testMiddleYOffsetVariableHeights(): void
    {
        // Alerts with different heights stack correctly using centerY - totalAlertLines
        // Alert height 4 at center: floor((24 - 4) / 2) = 10
        $this->assertSame(10, Position::MiddleCenter->yOffset(4, 24, 0));

        // Second alert (height 2) with totalAlertLines=4:
        // y = floor((24 - 2) / 2) - 4 = 11 - 4 = 7
        $this->assertSame(7, Position::MiddleCenter->yOffset(2, 24, 4));
    }

    public function testTopBottomUnaffectedByMiddleCases(): void
    {
        // Ensure existing Top* and Bottom* positions still work
        $this->assertSame(0, Position::TopLeft->yOffset(3, 24, 0));
        $this->assertSame(21, Position::BottomLeft->yOffset(3, 24, 0));

        // With stacking, Top positions stack DOWNWARD (y increases by the
        // cumulative height of earlier alerts) so stacked toasts don't
        // overlap. Overlapping previously left stale SGR fragments leaking
        // past the box border.
        $this->assertSame(3, Position::TopLeft->yOffset(3, 24, 3));
        $this->assertSame(18, Position::BottomLeft->yOffset(3, 24, 3));
    }

    /**
     * Regression test: Bottom/Middle positions must not return negative y
     * when the stacked alert height exceeds the viewport. The toast must
     * pin to y=0 (top visible edge) rather than rendering at a negative y
     * that would silently clip it.
     *
     * Pre-fix example: viewportHeight=10, alertHeight=3, totalAlertLines=10
     *   Bottom: 10 - 3 - 10 = -3 (CLIPPED)
     *   Middle: floor((10-3)/2) - 10 = -7 (CLIPPED)
     * Post-fix: both return max(0, ...) = 0 (VISIBLE at top edge)
     */
    public function testBottomYOffsetClampedToZeroWhenShortViewport(): void
    {
        // Short viewport (10) with cumulative stacked alerts (10) exceeding it
        // Old formula: 10 - 3 - 10 = -3
        $this->assertSame(0, Position::BottomLeft->yOffset(3, 10, 10));
        $this->assertSame(0, Position::BottomCenter->yOffset(3, 10, 10));
        $this->assertSame(0, Position::BottomRight->yOffset(3, 10, 10));

        // Also verify normal case still works (non-negative)
        $this->assertSame(7, Position::BottomLeft->yOffset(3, 10, 0));
    }

    public function testMiddleYOffsetClampedToZeroWhenShortViewport(): void
    {
        // Short viewport with stacking exceeding it
        // Old formula: floor((10-3)/2) - 10 = 3 - 10 = -7
        $this->assertSame(0, Position::MiddleLeft->yOffset(3, 10, 10));
        $this->assertSame(0, Position::MiddleCenter->yOffset(3, 10, 10));
        $this->assertSame(0, Position::MiddleRight->yOffset(3, 10, 10));

        // Also verify normal case still works (non-negative)
        $this->assertSame(3, Position::MiddleCenter->yOffset(3, 10, 0));
    }
}
