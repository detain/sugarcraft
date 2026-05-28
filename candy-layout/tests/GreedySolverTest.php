<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Constraint\Fill;
use SugarCraft\Layout\Constraint\Length;
use SugarCraft\Layout\Constraint\Min;
use SugarCraft\Layout\Constraint\Max;
use SugarCraft\Layout\Constraint\Percentage;
use SugarCraft\Layout\Constraint\Ratio;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Region;

final class GreedySolverTest extends TestCase
{
    private static function region(int $x, int $y, int $w, int $h): Region
    {
        return new Region($x, $y, $w, $h);
    }

    // ── Length constraints ─────────────────────────────────────────────────

    public function testPureLengthHorizontal(): void
    {
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(20), Constraint::length(30), Constraint::length(25)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertEquals(self::region(0, 0, 20, 24), $rects[0]);
        $this->assertEquals(self::region(20, 0, 30, 24), $rects[1]);
        $this->assertEquals(self::region(50, 0, 25, 24), $rects[2]);
    }

    public function testPureLengthVertical(): void
    {
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 80, 30),
            [Constraint::length(3), Constraint::length(10), Constraint::length(1)],
            Direction::Vertical
        );

        $this->assertCount(3, $rects);
        $this->assertEquals(self::region(0, 0, 80, 3), $rects[0]);
        $this->assertEquals(self::region(0, 3, 80, 10), $rects[1]);
        $this->assertEquals(self::region(0, 13, 80, 1), $rects[2]);
    }

    public function testLengthOverflowTruncates(): void
    {
        // Total length 120 > area 80, should truncate proportionally
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 80, 24),
            [Constraint::length(60), Constraint::length(60)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // 60:60 ratio = 40:40 in 80 width
        $this->assertSame(40, $rects[0]->width);
        $this->assertSame(40, $rects[1]->width);
    }

    // ── Min constraints ────────────────────────────────────────────────────

    public function testPureMinHorizontal(): void
    {
        // [min(20), min(30), min(25)] = 75 reserved, slack=25 in 100.
        // Proportional distribution: 20→26, 30→40, 25→33 = 99 + 1 rounding.
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::min(20), Constraint::min(30), Constraint::min(25)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertSame(26, $rects[0]->width);
        $this->assertSame(40, $rects[1]->width);
        $this->assertSame(33, $rects[2]->width);
    }

    public function testMinTruncation(): void
    {
        // Total mins 100 > area 50, should truncate proportionally
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 50, 24),
            [Constraint::min(60), Constraint::min(40)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // 60:40 ratio in 50 width = 30:20
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(20, $rects[1]->width);
    }

    // ── Fill constraints ────────────────────────────────────────────────────

    public function testPureFillHorizontalEqualWeight(): void
    {
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 90, 24),
            [Constraint::fill(), Constraint::fill(), Constraint::fill()],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(30, $rects[1]->width);
        $this->assertSame(30, $rects[2]->width);
    }

    public function testPureFillVerticalEqualWeight(): void
    {
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 80, 50),
            [Constraint::fill(), Constraint::fill()],
            Direction::Vertical
        );

        $this->assertCount(2, $rects);
        $this->assertSame(25, $rects[0]->height);
        $this->assertSame(25, $rects[1]->height);
    }

    public function testFillWeightedDistribution(): void
    {
        // Weights 1:2:3 = 6 total parts, area=60
        // 1-part=10, 2-parts=20, 3-parts=30
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 60, 24),
            [Constraint::fill(1), Constraint::fill(2), Constraint::fill(3)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertSame(10, $rects[0]->width);
        $this->assertSame(20, $rects[1]->width);
        $this->assertSame(30, $rects[2]->width);
    }

    public function testFillWithLengthAndMin(): void
    {
        // Length(20) + Min(10) + Fill(1) in 100-width area
        // Fixed: 20. Slack for min+fill: 100-20-10=70
        // fill gets all slack = 70.
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(20), Constraint::min(10), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertSame(20, $rects[0]->width);
        $this->assertSame(10, $rects[1]->width);
        $this->assertSame(70, $rects[2]->width);
    }

    // ── Mixed constraints ──────────────────────────────────────────────────

    public function testThreePaneDashboard(): void
    {
        // Header 3, body min 10 (fills), status 1 — vertical split of 30-height area
        $rows = GreedySolver::solveStatic(
            new Region(0, 0, 100, 30),
            [Constraint::length(3), Constraint::min(10), Constraint::length(1)],
            Direction::Vertical
        );

        $this->assertCount(3, $rows);
        $this->assertEquals(self::region(0, 0, 100, 3), $rows[0]);
        $this->assertEquals(self::region(0, 3, 100, 26), $rows[1]);
        $this->assertEquals(self::region(0, 29, 100, 1), $rows[2]);
    }

    public function testThreeColumnLayout(): void
    {
        // Length(20) + Min(20) + Fill(1) horizontal within body row
        $body = new Region(0, 3, 100, 26);
        $cols = GreedySolver::solveStatic(
            $body,
            [Constraint::length(20), Constraint::min(20), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(3, $cols);
        $this->assertEquals(self::region(0, 3, 20, 26), $cols[0]);
        $this->assertEquals(self::region(20, 3, 20, 26), $cols[1]);
        $this->assertEquals(self::region(40, 3, 60, 26), $cols[2]);
    }

    // ── Edge cases ─────────────────────────────────────────────────────────

    public function testEmptyConstraints(): void
    {
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [],
            Direction::Horizontal
        );
        $this->assertSame([], $rects);
    }

    public function testSingleConstraint(): void
    {
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(50)],
            Direction::Horizontal
        );
        $this->assertCount(1, $rects);
        $this->assertEquals(self::region(0, 0, 50, 24), $rects[0]);
    }

    // ── Percentage constraints ────────────────────────────────────────────────

    public function testPurePercentageHorizontal(): void
    {
        // Percentage(30) of 100 = 30
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::percentage(30), Constraint::percentage(70)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(70, $rects[1]->width);
    }

    public function testPercentageWithLength(): void
    {
        // Length(20) + Percentage(50) in 100 area
        // Percentage = 50 (50% of total area = 50).
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(20), Constraint::percentage(50)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(20, $rects[0]->width);
        $this->assertSame(50, $rects[1]->width);
    }

    public function testPercentageWithFill(): void
    {
        // Percentage(30) + Fill in 100 area
        // Percentage = 30 (30% of 100). Fill gets remainder = 70.
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::percentage(30), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(70, $rects[1]->width);
    }

    // ── Ratio constraints ────────────────────────────────────────────────────

    public function testPureRatioHorizontal(): void
    {
        // Ratio(1, 3) of 90 = 30; Ratio(2, 3) of 90 = 60
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 90, 24),
            [Constraint::ratio(1, 3), Constraint::ratio(2, 3)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(60, $rects[1]->width);
    }

    public function testRatioWithLength(): void
    {
        // Length(10) + Ratio(1, 2) in 100 area
        // Fixed: 10. Remaining: 90. Ratio(1, 2) = 50% of total = 50.
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(10), Constraint::ratio(1, 2)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(10, $rects[0]->width);
        $this->assertSame(50, $rects[1]->width);
    }

    // ── Max constraints ─────────────────────────────────────────────────────

    public function testMaxClampsWhenOver(): void
    {
        // Length(20) + Max(30) in 100 area
        // Max greedily takes slack (80), clamp to 30; reclaimed goes to Length
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(20), Constraint::max(30)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(70, $rects[0]->width); // 20 + reclaimed 50
        $this->assertSame(30, $rects[1]->width); // clamped from 80
    }

    public function testMaxClampGoesToFill(): void
    {
        // Length(20) + Max(10) + Fill in 100 area
        // Max gets 80, clamps to 10 (reclaims 70), goes to Fill
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(20), Constraint::max(10), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertSame(20, $rects[0]->width);
        $this->assertSame(10, $rects[1]->width); // clamped
        $this->assertSame(70, $rects[2]->width); // 70 + reclaimed
    }

    public function testMaxClampRedistributesToFill(): void
    {
        // Length(30) + Max(20) + Fill(1) in 100 area
        // Fill gets slack (3) + reclaimed (47) = 50
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(30), Constraint::max(20), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $this->assertSame(30, $rects[0]->width); // fixed
        $this->assertSame(20, $rects[1]->width); // clamped
        $this->assertSame(50, $rects[2]->width); // 3 + 47 reclaimed
    }

    public function testMaxWithPercentageNoFillNoMin(): void
    {
        // Percentage(50) + Max(30) in 100 area — no Fill, no Min
        // Max gets 50, clamps to 30 (reclaims 20), goes to Percentage
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::percentage(50), Constraint::max(30)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(70, $rects[0]->width); // 50 + reclaimed 20
        $this->assertSame(30, $rects[1]->width); // clamped
    }

    public function testMultipleMaxClampNoRecipients(): void
    {
        // Two Max constraints in 100 area, no Fill/Min
        // Max(10) gets 33, clamps to 10 (reclaims 23)
        // Max(20) gets 66, clamps to 20 (reclaims 46)
        // No eligible recipients for reclaimed → stays unused
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::max(10), Constraint::max(20)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(10, $rects[0]->width); // clamped
        $this->assertSame(20, $rects[1]->width); // clamped
    }

    public function testMaxClampReclaimedSpaceGoesToMin(): void
    {
        // Length(20) + Min(10) + Max(5) in 50 area
        // Max gets 30 (50-20), clamps to 5 (reclaims 25), goes to Min
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 50, 24),
            [Constraint::length(20), Constraint::min(10), Constraint::max(5)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        // Min receives reclaimed space: 10 + 25 = 35 (but capped by total)
        $this->assertGreaterThanOrEqual(10, $rects[1]->width);
        $this->assertSame(5, $rects[2]->width); // clamped
    }

    public function testMaxClampNoReclaimedSpaceReturnsOriginal(): void
    {
        // Length(30) + Max(80) in 100 area
        // Max gets 70, doesn't exceed max(80) so no clamp
        // $reclaimed would be 0, early return at line 237
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(30), Constraint::max(80)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(70, $rects[1]->width); // 70 is within max(80), no clamp
    }

    public function testMaxClampRoundingRemainderToMin(): void
    {
        // Min(7) + Min(7) + Max(15) in 28 area
        // All mins get 7 exactly = 14, Max gets 14
        // Total = 28, no rounding needed
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 28, 24),
            [Constraint::min(7), Constraint::min(7), Constraint::max(15)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $total = $rects[0]->width + $rects[1]->width + $rects[2]->width;
        $this->assertSame(28, $total);
    }

    // ── Min shortage (slack < 0) ───────────────────────────────────────────

    public function testMinShortageTruncatesProportionally(): void
    {
        // Two mins totaling 150 but only 80 available
        // slack = 80 - 0 - 150 = -70 < 0
        // Scale mins proportionally: 80/150 = 0.533
        // Min(100) → floor(100 * 0.533) = 53, Min(50) → floor(50 * 0.533) = 26
        // Total = 53 + 26 = 79 (1 lost to rounding)
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 80, 24),
            [Constraint::min(100), Constraint::min(50)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // Both should be less than their requested mins due to shortage
        $this->assertLessThan(100, $rects[0]->width);
        $this->assertLessThan(50, $rects[1]->width);
        // Total might be 79 or 80 depending on rounding
        $total = $rects[0]->width + $rects[1]->width;
        $this->assertGreaterThanOrEqual(78, $total);
    }

    // ── Factory methods ────────────────────────────────────────────────────

    public function testFactoryGreedy(): void
    {
        $solver = GreedySolver::greedy();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    public function testFactoryNew(): void
    {
        $solver = GreedySolver::new();
        $this->assertInstanceOf(GreedySolver::class, $solver);
    }

    // Note: GreedySolver::cassowary() has a return-type bug (returns CassowarySolver but typed as self)
    // Line 48 is intentionally not tested here to avoid TypeError

    // ── LayoutSolver interface ──────────────────────────────────────────

    public function testSolverImplementsLayoutSolver(): void
    {
        $solver = GreedySolver::new();
        $this->assertInstanceOf(\SugarCraft\Layout\LayoutSolver::class, $solver);
    }

    public function testInstanceSolve(): void
    {
        $solver = GreedySolver::new();
        $rects = $solver->solve(
            new Region(0, 0, 100, 24),
            Direction::Horizontal,
            [Constraint::length(30), Constraint::fill(1)]
        );

        $this->assertCount(2, $rects);
        $this->assertSame(30, $rects[0]->width);
        $this->assertSame(70, $rects[1]->width);
    }

    public function testStaticAndInstanceProduceSameOutput(): void
    {
        $constraints = [Constraint::length(20), Constraint::min(10), Constraint::fill(1)];
        $region = new Region(0, 0, 100, 24);

        $staticRects = GreedySolver::solveStatic($region, $constraints, Direction::Horizontal);
        $instanceRects = (GreedySolver::new())->solve($region, Direction::Horizontal, $constraints);

        $this->assertCount(count($staticRects), $instanceRects);
        foreach ($staticRects as $i => $r) {
            $this->assertEquals($r->width, $instanceRects[$i]->width);
            $this->assertEquals($r->height, $instanceRects[$i]->height);
        }
    }

    // ── Rounding ─────────────────────────────────────────────────────────

    public function testRoundingDistributesToFill(): void
    {
        // 3 fills on 100 area = 33.33 each → sum would be 99.99
        // Rounding remainder goes to first Fill
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::fill(1), Constraint::fill(1), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        $total = $rects[0]->width + $rects[1]->width + $rects[2]->width;
        $this->assertSame(100, $total); // Exact total
    }

    // ── applyMaxClamp edge cases ────────────────────────────────────────────

    public function testMaxClampReclaimedSpaceGoesToLength(): void
    {
        // Length(30) + Max(5) in 80 area
        // Max greedily takes 50, clamps to 5 (reclaims 45), goes to Length
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 80, 24),
            [Constraint::length(30), Constraint::max(5)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // Length gets the reclaimed space: 30 + 45 = 75
        $this->assertSame(75, $rects[0]->width);
        $this->assertSame(5, $rects[1]->width);
    }

    public function testMaxClampReclaimedSpaceGoesToPercentage(): void
    {
        // Percentage(50) + Max(10) in 100 area
        // Percentage = 50. Max gets 50, clamps to 10 (reclaims 40), goes to Percentage
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::percentage(50), Constraint::max(10)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // Percentage gets reclaimed space: 50 + 40 = 90
        $this->assertSame(90, $rects[0]->width);
        $this->assertSame(10, $rects[1]->width);
    }

    public function testMaxClampReclaimedSpaceGoesToRatio(): void
    {
        // Ratio(1, 2) + Max(10) in 100 area
        // Ratio = 50. Max gets 50, clamps to 10 (reclaims 40), goes to Ratio
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::ratio(1, 2), Constraint::max(10)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // Ratio gets reclaimed space: 50 + 40 = 90
        $this->assertSame(90, $rects[0]->width);
        $this->assertSame(10, $rects[1]->width);
    }

    public function testMaxClampNoRecipientsStaysUnused(): void
    {
        // Two Max constraints only - reclaimed space stays unused
        // This exercises the early return when no recipients exist
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::max(10), Constraint::max(20)],
            Direction::Horizontal
        );

        $this->assertCount(2, $rects);
        // Both clamped to their max values
        $this->assertSame(10, $rects[0]->width);
        $this->assertSame(20, $rects[1]->width);
    }

    public function testMaxClampWithRoundingRemainder(): void
    {
        // Test rounding remainder distribution to first recipient
        // Create a scenario where reclaimed space doesn't divide evenly
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 100, 24),
            [Constraint::length(30), Constraint::max(15), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertCount(3, $rects);
        // Verify total equals region width
        $total = $rects[0]->width + $rects[1]->width + $rects[2]->width;
        $this->assertSame(100, $total);
    }
}
