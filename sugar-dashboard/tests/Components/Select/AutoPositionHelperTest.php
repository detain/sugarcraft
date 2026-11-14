<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Select;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Select\AutoPositionHelper;
use SugarCraft\Dashboard\Components\Select\OverlayPosition;
use InvalidArgumentException;

/**
 * Boundary matrix for the overlay flip decision (D-142). The class had
 * zero tests; these pins name every branch the shipped math takes.
 */
final class AutoPositionHelperTest extends TestCase
{
    // ─── Space comparison ──────────────────────────────────────────

    public function testMoreRoomBelowChoosesBottom(): void
    {
        // y=2 above, 24-2-1=21 below: below wins.
        $this->assertSame(
            OverlayPosition::Bottom,
            AutoPositionHelper::vertical(2, 1, 24),
        );
    }

    public function testMoreRoomAboveChoosesTop(): void
    {
        // y=20 above, 24-20-1=3 below: above wins.
        $this->assertSame(
            OverlayPosition::Top,
            AutoPositionHelper::vertical(20, 1, 24),
        );
    }

    public function testTieSendsTheOverlayUp(): void
    {
        // Exact tie: above 11, below 24-11-2 = 11. The shipped rule is
        // `spaceAbove >= spaceBelow`, so the tie breaks toward Top.
        $this->assertSame(
            OverlayPosition::Top,
            AutoPositionHelper::vertical(11, 2, 24),
        );
    }

    // ─── Edge positions ────────────────────────────────────────────

    public function testAnchorAtScreenTopCannotFlipUp(): void
    {
        // y=0 gives zero room above; any positive room below wins.
        $this->assertSame(
            OverlayPosition::Bottom,
            AutoPositionHelper::vertical(0, 1, 10),
        );
    }

    public function testAnchorPastScreenBottomStillChoosesUp(): void
    {
        // Below-room goes negative when the anchor overflows the screen;
        // the same comparison picks Top without special-casing.
        $this->assertSame(
            OverlayPosition::Top,
            AutoPositionHelper::vertical(20, 6, 24),
        );
    }

    public function testZeroHeightAnchorAtZeroHeightScreen(): void
    {
        // Degenerate: 0 >= 0 → Top (tie rule), no division by zero and no
        // crash path exists in the decision.
        $this->assertSame(
            OverlayPosition::Top,
            AutoPositionHelper::vertical(0, 0, 0),
        );
    }

    // ─── String anchor acceptance ──────────────────────────────────

    public function testNumericStringAnchorsAreAccepted(): void
    {
        $this->assertSame(
            OverlayPosition::Bottom,
            AutoPositionHelper::vertical('2', 1, 24),
        );
        // Float-string truncates through the int cast: '12.7' behaves as 12.
        $this->assertSame(
            OverlayPosition::Top,
            AutoPositionHelper::vertical('12.7', 1, 24),
        );
        // Signed numeric strings parse as expected: -3 above is never a
        // winner, 24-(-3)-1=26 below takes it.
        $this->assertSame(
            OverlayPosition::Bottom,
            AutoPositionHelper::vertical('-3', 1, 24),
        );
    }

    public function testNonNumericAnchorStringThrowsInsteadOfCastingToZero(): void
    {
        // The old seam silently cast 'auto' to y=0 (D-142): a garbage
        // coordinate is a programmer error and must say so loudly.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("anchor rect Y must be numeric, got 'auto'");

        AutoPositionHelper::vertical('auto', 1, 24);
    }
}
