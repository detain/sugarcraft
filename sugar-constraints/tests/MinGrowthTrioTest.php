<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\Region;

/**
 * Doc-promise pin (SC-16): {@see Min}'s growth-policy claim, verbatim —
 *
 *   "a Min takes slack directly only when the set has neither Fill nor Max.
 *   Otherwise it grows only when a Max clamp pass hands cells back, and it
 *   takes those before any Fill does. Plain 'grows if space is available'
 *   overstates it — [Min(10), Fill(1), Max(6)] in 17 cells stays 10/1/6
 *   because nothing is clamped, while [Min(10), Max(6), Fill(1)] in 100
 *   cells gives 81/6/13 because the Max clamp hands its excess to Min."
 *
 * Pin [Min(10), Fill(1), Max(6)]@17 -> [10,1,6] asserts the no-clamp case:
 * Min holds its floor at 10 (never starved, never grown), Max caps at 6 (its
 * ceiling is absolute), and the 1-cell slack that survives both lands on
 * Fill. The 100-cell pins assert the clamp case the 17-cell pin cannot reach:
 * the Max clamp's excess lands on Min before Fill. A solver that let Min
 * absorb slack directly next to a Fill, or let Fill take clamp reclaim ahead
 * of Min, breaks these pins.
 *
 * Targeted run: vendor/bin/phpunit --filter MinGrowthTrioTest
 */
final class MinGrowthTrioTest extends TestCase
{
    public function testMinFillMaxTrioInSeventeenCellsStaysTenOneSix(): void
    {
        $regions = GreedySolver::new()->solve(
            Region::fromSize(17, 1),
            Direction::Horizontal,
            [Constraint::min(10), Constraint::fill(1), Constraint::max(6)],
        );

        $widths = array_map(static fn(Region $r): int => $r->width, $regions);
        $this->assertSame([10, 1, 6], $widths);
    }

    public function testMaxClampHandsExcessToMinBeforeFillAtHundredCells(): void
    {
        $regions = GreedySolver::new()->solve(
            Region::fromSize(100, 1),
            Direction::Horizontal,
            [Constraint::min(10), Constraint::max(6), Constraint::fill(1)],
        );

        $widths = array_map(static fn(Region $r): int => $r->width, $regions);
        $this->assertSame([81, 6, 13], $widths);
    }

    public function testLargerMaxClampStillFeedsMinFirstAtHundredCells(): void
    {
        $regions = GreedySolver::new()->solve(
            Region::fromSize(100, 1),
            Direction::Horizontal,
            [Constraint::min(10), Constraint::max(50), Constraint::fill(1)],
        );

        $widths = array_map(static fn(Region $r): int => $r->width, $regions);
        $this->assertSame([48, 50, 2], $widths);
    }
}
