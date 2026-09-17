<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\CassowarySolver;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\LayoutSolver;
use SugarCraft\Layout\Region;

/**
 * E736/Phase-6 edge cases re-derived against the current tree: the scenarios
 * the retired-simplex plan listed that the compat/min-share/default suites do
 * not yet pin — Max-at-available-space boundary, Fill weight overflow
 * (PHP_INT_MAX), mixed Percentage+Min tiling, Length-only slack behaviour, plus
 * the interface and direct-construction back-compat promises.
 */
final class SolverEdgeCaseTest extends TestCase
{
    /**
     * Run the deprecated Cassowary path with its E_USER_DEPRECATED swallowed so
     * result assertions stay clean under failOnWarning.
     *
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    private function cassowarySolve(Region $region, Direction $dir, array $constraints): array
    {
        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            return (new CassowarySolver())->solve($region, $dir, $constraints);
        } finally {
            restore_error_handler();
        }
    }

    // ── Contract pins ────────────────────────────────────────────────────────

    public function testGreedySolverSatisfiesLayoutSolverContract(): void
    {
        $solver = GreedySolver::new();
        $this->assertInstanceOf(LayoutSolver::class, $solver);
        // Interface statics are declarations only (calling them fatals) — both
        // concrete solvers must supply the factories the contract names.
        $contract = new \ReflectionClass(LayoutSolver::class);
        foreach (['solve', 'greedy', 'cassowary'] as $method) {
            $this->assertTrue($contract->hasMethod($method), "LayoutSolver must declare {$method}()");
        }
        foreach ([GreedySolver::class, CassowarySolver::class] as $implementor) {
            foreach (['solve', 'greedy', 'cassowary'] as $method) {
                $this->assertTrue(
                    (new \ReflectionClass($implementor))->hasMethod($method),
                    "{$implementor} must fulfil the {$method}() contract"
                );
            }
        }
    }

    public function testDirectConstructionOfDeprecatedSolverKeepsWorking(): void
    {
        // CassowarySolver's class docblock promises legacy `new CassowarySolver()`
        // call-sites keep working; pin construction AND a real solve() through
        // that bare entry point (not just the factories).
        $solver = new CassowarySolver();
        $this->assertInstanceOf(LayoutSolver::class, $solver);

        $constraints = [Constraint::percentage(30), Constraint::min(10)];
        $region = Region::fromSize(100, 24);

        $expected = (new GreedySolver())->solve($region, Direction::Horizontal, $constraints);
        $this->assertEquals(
            $expected,
            $this->cassowarySolve($region, Direction::Horizontal, $constraints),
            'bare-constructor Cassowary solve must stay bit-equivalent to GreedySolver'
        );
    }

    // ── 6.1 scenario: mixed Percentage + Min ─────────────────────────────

    public function testPercentageWithMinMixedTilesRegionExactly(): void
    {
        // Percentage(30) reserves 30; Min(10) floors at 10 and, with no
        // Fill/Max present, absorbs the whole 60-cell slack proportionally
        // (its only peer is itself) → 70.
        $rects = GreedySolver::solveStatic(
            Region::fromSize(100, 10),
            [Constraint::percentage(30), Constraint::min(10)],
            Direction::Horizontal
        );

        $this->assertSame([30, 70], array_map(static fn(Region $r): int => $r->width, $rects));
        $this->assertSame([0, 30], array_map(static fn(Region $r): int => $r->x, $rects));
        $this->assertSame(100, array_sum(array_map(static fn(Region $r): int => $r->width, $rects)));
    }

    // ── 6.1 scenario: Max exactly equal to available space ───────────────

    public function testMaxExactlyEqualAvailableSpaceKeepsWholeSpan(): void
    {
        $rects = GreedySolver::solveStatic(
            Region::fromSize(50, 8),
            [Constraint::max(50)],
            Direction::Horizontal
        );

        $this->assertCount(1, $rects);
        $this->assertSame(50, $rects[0]->width, 'Max(n) at slack == n must take the full span, not clamp below it');
    }

    public function testMaxAtBoundaryCompetingWithFillSharesSlackByWeight(): void
    {
        // Fill(1) + Max(50) over 50 cells: weights 1:50 → floor gives 0/49,
        // the whole remainder (1) lands on the first Fill.
        $rects = GreedySolver::solveStatic(
            Region::fromSize(50, 8),
            [Constraint::fill(1), Constraint::max(50)],
            Direction::Horizontal
        );

        $this->assertSame([1, 49], array_map(static fn(Region $r): int => $r->width, $rects));
        $this->assertSame(50, array_sum(array_map(static fn(Region $r): int => $r->width, $rects)));
    }

    // ── 6.1 scenario: Fill weight sum overflow (very large weights) ──────

    public function testHugeFillWeightsStillTileExactly(): void
    {
        // PHP_INT_MAX + PHP_INT_MAX overflows the int sum into float; the
        // proportional pass must still round-trip to an exact tiling.
        $rects = GreedySolver::solveStatic(
            Region::fromSize(101, 5),
            [Constraint::fill(PHP_INT_MAX), Constraint::fill(PHP_INT_MAX)],
            Direction::Horizontal
        );

        $this->assertSame([51, 50], array_map(static fn(Region $r): int => $r->width, $rects));
        $this->assertSame(101, array_sum(array_map(static fn(Region $r): int => $r->width, $rects)));
    }

    public function testExtremeWeightRatioCollapsesPeerToZeroWithoutCrash(): void
    {
        // Fill(PHP_INT_MAX) vs Fill(1): the tiny share floors to 0 and the
        // whole rounding remainder (1 cell) rides the first Fill → [100, 0].
        $rects = GreedySolver::solveStatic(
            Region::fromSize(100, 5),
            [Constraint::fill(PHP_INT_MAX), Constraint::fill(1)],
            Direction::Horizontal
        );

        $this->assertSame([100, 0], array_map(static fn(Region $r): int => $r->width, $rects));
        $this->assertSame(100, array_sum(array_map(static fn(Region $r): int => $r->width, $rects)));
    }

    // ── 6.1 scenario: negative slack / exceed-area distribution ──────────

    public function testOverflowBeyondAreaNeverProducesNegativeWidths(): void
    {
        // Demand 150 over 100 → proportional truncation; every raw size is
        // floored against the 2/3 scale and none may go negative.
        $rects = GreedySolver::solveStatic(
            Region::fromSize(100, 4),
            [Constraint::length(60), Constraint::min(90)],
            Direction::Horizontal
        );

        $widths = array_map(static fn(Region $r): int => $r->width, $rects);
        $this->assertSame([40, 60], $widths);
        $this->assertLessThanOrEqual(100, array_sum($widths));
        foreach ($widths as $width) {
            $this->assertGreaterThanOrEqual(0, $width);
        }
    }

    // ── honest behaviour pin: Length-only layouts leave the slack as a gap ──

    public function testLengthOnlyLayoutLeavesTrailingGap(): void
    {
        // Step 3 guards: Min/Length are exact values and rounding reclamation
        // only corrects diff <= MAX_FLOOR_RECLAIM. A 90-cell shortfall is
        // intentional slack — nothing may inflate the Length.
        $rects = GreedySolver::solveStatic(
            Region::fromSize(100, 3),
            [Constraint::length(10)],
            Direction::Horizontal
        );

        $this->assertSame([10], array_map(static fn(Region $r): int => $r->width, $rects));
    }

    public function testVerticalMirrorOfPercentageMinEdge(): void
    {
        // The vertical path flips through the same horizontal pipeline; pin
        // that the mixed Percentage+Min sizes and the tiling survive the flip.
        // Height 60 → Percentage(30)=18, Min(10) absorbs the 32-cell slack → 42.
        $rects = GreedySolver::solveStatic(
            Region::fromSize(30, 60),
            [Constraint::percentage(30), Constraint::min(10)],
            Direction::Vertical
        );

        $this->assertSame([18, 42], array_map(static fn(Region $r): int => $r->height, $rects));
        $this->assertSame([30, 30], array_map(static fn(Region $r): int => $r->width, $rects));
        $this->assertSame([0, 18], array_map(static fn(Region $r): int => $r->y, $rects));
        $this->assertSame(60, array_sum(array_map(static fn(Region $r): int => $r->height, $rects)));
    }
}
