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
 *   "a Min grows ONLY when slack survives every Fill and Max in the set
 *   (they take slack first), or when a Max clamp pass hands cells back and
 *   no Fill claims them. Plain 'grows if space is available' overstates
 *   it — [Min(10), Fill(1), Max(6)] in 17 cells stays 10/1/6."
 *
 * Pin [Min(10), Fill(1), Max(6)]@17 -> [10,1,6] asserts it: Min holds its
 * floor at 10 (never starved, never grown), Max caps at 6 (its ceiling is
 * absolute), and the 1-cell slack that survives both lands on Fill — the
 * Fill-first growth order the docblock promises. A solver that let Min
 * absorb slack, or let Max overshoot, breaks this pin.
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
}
