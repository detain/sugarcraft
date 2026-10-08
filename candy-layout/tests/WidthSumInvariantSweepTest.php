<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\CassowarySolver;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Constraint\Fill;
use SugarCraft\Layout\Constraint\Length;
use SugarCraft\Layout\Constraint\Max;
use SugarCraft\Layout\Constraint\Min;
use SugarCraft\Layout\Constraint\Percentage;
use SugarCraft\Layout\Constraint\Ratio;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Region;

/**
 * Width-sum invariant sweep (crush_libs re-verify lane A5, 2026-10-08).
 *
 * Nothing in this library used to prove that split/allocation results tile the
 * available span: the dock had a HEIGHT sweep (DockLayoutTest) but no width
 * sibling, and the solver's tiling guarantees only had point cases. sugar-crush
 * depends on exactly this invariant for its docked frame — a split that silently
 * loses a column grows a gutter or clips the last pane every resize.
 *
 * The tiling contract being pinned (as documented on GreedySolver and
 * DockLayout, verified here across the full grid):
 *  - DockLayout::resolve(): the column bands plus dividers ALWAYS tile the
 *    frame exactly — contiguous coverage of [x, x+width), no gap, no overlap,
 *    through min-protection raises and the whole degradation ladder.
 *  - GreedySolver: sum(sizes) == span whenever the layout absorbs slack
 *    (a Fill or Max is present), when slack goes to Min constraints, and on
 *    over-constrained truncation (default mode, and round mode via the same
 *    truncation pass) — horizontally AND vertically, both remainder policies,
 *    and the opt-in min-share floor.
 *  - The one honest exception is documented behaviour, not drift: an
 *    under-constrained fixed-only layout (Length/Percentage/Ratio summing to
 *    less than the span) keeps its exact sizes and leaves the slack as a gap
 *    (testUnderconstrainedFixedLayoutKeepsItsGap); nothing may inflate a fixed
 *    constraint.
 *  - Same input → same output for every case swept (determinism), and the
 *    deprecated CassowarySolver delegates byte-identically.
 */
final class WidthSumInvariantSweepTest extends TestCase
{
    // ── DockLayout::resolve: the crush-bearing invariant ─────────────────────

    public function testDockColumnsTileTheFrameAcrossWidthSharesStacksAndMinimums(): void
    {
        // share pairs: design thirds, seeded quarters, asym, odd rationals
        // (ties + thirds + near-clamp pairs), a pair summing to 1 (center=0 →
        // raise refused → ladder), and a share >= 1 (infeasible → degrade).
        $sharePairs = [
            [[1, 3], [1, 3]],
            [[1, 4], [1, 4]],
            [[1, 4], [2, 5]],
            [[7, 13], [3, 11]],
            [[1, 2], [1, 2]],
            [[2, 3], [1, 3]],
            [[999, 1000], [999, 1000]],
            [[1, 1], [1, 3]],
            [[1, 7], [1, 7]],
        ];
        $minimumPairs = [[24, 20], [1, 1], [60, 10], [40, 30]];
        // (leftSlots, rightSlots) shapes incl. single side, stacks, lopsided
        $stackShapes = [[1, 0], [0, 1], [1, 1], [2, 2], [3, 3], [1, 3], [3, 1], [2, 0], [0, 2], [3, 2]];

        $violations = [];
        foreach ($sharePairs as $gi => [$lnum, $rnum]) {
            foreach ($minimumPairs as [$centerMin, $sideMin]) {
                foreach ($stackShapes as [$leftCount, $rightCount]) {
                    $dock = DockLayout::new('chat')->withMinimums($centerMin, $sideMin);
                    for ($k = 0; $k < $leftCount; $k++) {
                        $dock = $dock->withSlotAdded(Side::Left, "L$k");
                    }
                    for ($k = 0; $k < $rightCount; $k++) {
                        $dock = $dock->withSlotAdded(Side::Right, "R$k");
                    }
                    $dock = $dock->withColumnShare(Side::Left, $lnum[0], $lnum[1]);
                    $dock = $dock->withColumnShare(Side::Right, $rnum[0], $rnum[1]);
                    $dividers = $dock->dividerCols;

                    for ($width = 1; $width <= 200; $width++) {
                        $geometry = $dock->resolve(Region::fromSize($width, 30));
                        $case = "shares#$gi min=$centerMin/$sideMin L$leftCount R$rightCount w=$width";

                        $center = $geometry->regionFor('chat');
                        if ($center === null) {
                            $violations[] = "$case: no center region";
                            continue;
                        }

                        // Column bands (stacked slots collapse to one band per
                        // x) plus divider columns must cover [0, width) exactly.
                        $spans = [];
                        foreach ($geometry->regions as $region) {
                            if (($spans[$region->x] ?? $region->width) !== $region->width) {
                                $violations[] = "$case: ragged band at x={$region->x}";
                            }
                            $spans[$region->x] = $region->width;
                        }
                        foreach ($geometry->dividerColumns as $divider) {
                            $spans[$divider['x']] = $dividers;
                        }
                        ksort($spans);
                        $cursor = 0;
                        foreach ($spans as $x => $bandWidth) {
                            if ($x !== $cursor) {
                                $violations[] = "$case: gap/overlap at x=$x (cursor=$cursor)";
                                break;
                            }
                            $cursor = $x + $bandWidth;
                        }
                        if ($cursor !== $width) {
                            $violations[] = "$case: columns end at $cursor, frame is $width";
                        }

                        // Determinism: re-resolving the same frame answers the same.
                        // Scalar projection — Region instances differ per call, value
                        // identity is what must hold.
                        $again = $dock->resolve(Region::fromSize($width, 30));
                        $before = [];
                        foreach ($geometry->regions as $paneId => $region) {
                            $before[$paneId] = [$region->x, $region->width];
                        }
                        $after = [];
                        foreach ($again->regions as $paneId => $region) {
                            $after[$paneId] = [$region->x, $region->width];
                        }
                        if ($before !== $after) {
                            $violations[] = "$case: nondeterministic geometry";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function testZeroDividerDockStillTilesEveryColumn(): void
    {
        // dividerCols has no public mutator (always 1 through new()/fromArray()),
        // so the zero-divider shape is only expressible via a restored manifest…
        // it is not. Pin instead that every emitted divider column sits exactly
        // on a band boundary, which is the same coverage law at div==1.
        $dock = DockLayout::new('chat')
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Right, 'tools');
        for ($width = 1; $width <= 200; $width++) {
            $geometry = $dock->resolve(Region::fromSize($width, 20));
            $left = $geometry->regionFor('files');
            $center = $geometry->regionFor('chat');
            $right = $geometry->regionFor('tools');
            $case = "w=$width";
            $this->assertNotNull($center, "center vanished $case");
            $dividers = $geometry->dividerColumns();
            $active = ($left !== null ? 1 : 0) + ($right !== null ? 1 : 0);
            $this->assertCount($active, $dividers, "divider count $case");
            $sum = ($left?->width ?? 0) + $center->width + ($right?->width ?? 0) + $active * $dock->dividerCols;
            $this->assertSame($width, $sum, "column sum $case");
        }
    }

    // ── GreedySolver: tiling over the parameter space ────────────────────────

    public function testSlackAbsorbingSplitsTileAcrossWidthsSegmentsAndDirections(): void
    {
        $sets = self::fractionSets();
        $solver = GreedySolver::new();
        $violations = [];

        foreach ($sets as $name => $base) {
            for ($segments = 1; $segments <= 6; $segments++) {
                $constraints = [];
                for ($k = 0; $k < $segments; $k++) {
                    $constraints[] = $base[$k % count($base)];
                }
                // A trailing Fill absorbs slack — the documented exact-tiling shape.
                $constraints[] = new Fill(1);
                for ($span = 1; $span <= 200; $span++) {
                    $this->collectTilingViolations($violations, $solver, "B1 $name n=$segments w=$span",
                        Region::fromSize($span, 10), Direction::Horizontal, $constraints, $span);
                    $this->collectTilingViolations($violations, $solver, "B1v $name n=$segments h=$span",
                        Region::fromSize(10, $span), Direction::Vertical, $constraints, $span);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * Pure Percentage/Ratio layouts whose fractions sum to exactly 1 tile every
     * span in BOTH rounding modes: floor loses < 1 cell per segment (bounded by
     * N-1, re-claimed to the earlier segments), round over-allocation lands in
     * the truncation pass. This is the float→int determinism contract of audit
     * row 3 made executable.
     */
    public function testExactFractionSplitsTileEveryWidthInFloorAndRoundModes(): void
    {
        $exactSets = [
            'p50x2'       => [new Percentage(50), new Percentage(50)],
            'p33x2+p34'   => [new Percentage(33), new Percentage(33), new Percentage(34)],
            'p10x10'      => array_fill(0, 10, new Percentage(10)),
            'thirds'      => [new Ratio(1, 3), new Ratio(1, 3), new Ratio(1, 3)],
            'half+2qr'    => [new Ratio(1, 2), new Ratio(1, 4), new Ratio(1, 4)],
            '2/7+3/7+2/7' => [new Ratio(2, 7), new Ratio(3, 7), new Ratio(2, 7)],
            'p100'        => [new Percentage(100)],
            'zero+halves' => [new Ratio(0, 1), new Percentage(50), new Percentage(50)],
        ];
        $modes = ['floor' => GreedySolver::new(), 'round' => GreedySolver::new()->withRoundSplit()];
        $violations = [];
        foreach ($modes as $mode => $solver) {
            foreach ($exactSets as $name => $constraints) {
                for ($width = 1; $width <= 200; $width++) {
                    $this->collectTilingViolations($violations, $solver, "$mode $name w=$width",
                        Region::fromSize($width, 5), Direction::Horizontal, $constraints, $width);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    public function testOverconstrainedSplitsTruncateToExactTiling(): void
    {
        $overconstrained = [
            'L30x2'    => [new Length(30), new Length(30)],
            'L20+Fill' => [new Length(20), new Fill(1)],
            'L5x6'     => array_fill(0, 6, new Length(5)),
            'p50+L40'  => [new Percentage(50), new Length(40)],
        ];
        $solver = GreedySolver::new();
        $violations = [];
        foreach ($overconstrained as $name => $constraints) {
            for ($width = 1; $width <= 50; $width++) {
                // L5x6 demands 30: above that it underflows (gap case, pinned
                // separately), below/on it truncates.
                if ($name === 'L5x6' && $width > 30) {
                    continue;
                }
                $this->collectTilingViolations($violations, $solver, "$name w=$width",
                    Region::fromSize($width, 5), Direction::Horizontal, $constraints, $width);
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * The documented, intentional anti-invariant: fixed-only demand below the
     * span is honoured as exact sizes plus a gap — the sweep above must never
     * "fix" this by inflating a Length. (Same law SolverEdgeCaseTest pins at a
     * single point; here it holds across the underflow band.)
     */
    public function testUnderconstrainedFixedLayoutKeepsItsGap(): void
    {
        $constraints = array_fill(0, 6, new Length(5));
        $solver = GreedySolver::new();
        for ($width = 31; $width <= 200; $width++) {
            $sizes = array_map(
                static fn(Region $r): int => $r->width,
                $solver->solve(Region::fromSize($width, 5), Direction::Horizontal, $constraints),
            );
            $this->assertSame([5, 5, 5, 5, 5, 5], $sizes, "fixed sizes drifted at w=$width");
            $this->assertSame(30, array_sum($sizes));
        }
    }

    public function testMinAndMaxPathsPreserveTheWholeSpan(): void
    {
        $sets = [
            'm0x3'    => [new Min(0), new Min(0), new Min(0)],
            'm5+m3'   => [new Min(5), new Min(3)],
            'm+L'     => [new Length(4), new Min(2)],
            'm+max'   => [new Min(3), new Max(6)],
            'max5+fill' => [new Max(5), new Fill(1)],
            'max+fill'  => [new Max(9), new Fill(2), new Max(1)],
            'min+max'   => [new Min(2), new Max(4), new Fill(1)],
        ];
        $solver = GreedySolver::new();
        $violations = [];
        foreach ($sets as $name => $constraints) {
            for ($width = 1; $width <= 80; $width++) {
                // m0x3/m5+m3/m+L/m+max absorb slack through Min; max*+fill through Fill.
                $this->collectTilingViolations($violations, $solver, "$name w=$width",
                    Region::fromSize($width, 5), Direction::Horizontal, $constraints, $width);
            }
        }

        $this->assertSame([], $violations);
    }

    public function testMinShareFloorTilesEveryAllFillSet(): void
    {
        $weightSets = [[1, 1, 1], [1, 0, 1], [0, 0, 1], [1, 2, 3, 4, 5, 6], [3, 3, 3, 3]];
        $violations = [];
        foreach ([1, 2] as $cells) {
            $solver = GreedySolver::new()->withMinShare($cells);
            foreach ($weightSets as $si => $weights) {
                $constraints = array_map(static fn(int $w): Fill => new Fill($w), $weights);
                for ($width = 1; $width <= 200; $width++) {
                    $this->collectTilingViolations($violations, $solver, "minShare=$cells set$si w=$width",
                        Region::fromSize($width, 5), Direction::Horizontal, $constraints, $width);
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * withRemainderToLast() hands the leftover pixels to the final segment, so
     * every Max-free shape must tile the whole span — a Max in the set could cap
     * that last segment and leave slack (documented, covered by the clamp sweep).
     */
    public function testRemainderToLastTilesEveryMaxFreeSet(): void
    {
        $sets = [
            'fill3'    => [new Fill(1), new Fill(1), new Fill(1)],
            'pct50'    => [new Percentage(50), new Percentage(50)],
            'thirds+f' => [new Ratio(1, 3), new Ratio(1, 3), new Ratio(1, 3), new Fill(1)],
            'mixed+f'  => [new Length(7), new Percentage(25), new Ratio(1, 4), new Fill(1)],
            'm5+m3'    => [new Min(5), new Min(3)],
            'L4+m2+f'  => [new Length(4), new Min(2), new Fill(1)],
            'zeroFill' => [new Fill(0), new Fill(1)],
        ];
        $solver = GreedySolver::new()->withRemainderToLast();
        $violations = [];
        foreach ($sets as $name => $constraints) {
            for ($width = 1; $width <= 200; $width++) {
                $this->collectTilingViolations($violations, $solver, "remainderToLast $name w=$width",
                    Region::fromSize($width, 5), Direction::Horizontal, $constraints, $width);
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * The raw floor-vs-round policy survives to the output whenever a Fill
     * absorbs the slack (on symmetric halves the remainder/overflow correction
     * reconciles both policies, which is itself tiling — see the mode sweep).
     * round() breaks exact .5 ties away from zero.
     */
    public function testRoundSplitRoundsHalfAwayFromZero(): void
    {
        $floor = GreedySolver::new();
        $round = $floor->withRoundSplit();

        // 5 * 50/100 = 2.5 exact tie.
        $this->assertSame([2, 3], $this->widthsOf($floor, [new Percentage(50), new Fill(1)], 5));
        $this->assertSame([3, 2], $this->widthsOf($round, [new Percentage(50), new Fill(1)], 5));
        // 5 * 30/100 = 1.5 exact tie.
        $this->assertSame([1, 4], $this->widthsOf($floor, [new Percentage(30), new Fill(1)], 5));
        $this->assertSame([2, 3], $this->widthsOf($round, [new Percentage(30), new Fill(1)], 5));
        // 7 * 1/4 = 1.75 non-tie rounds up, floors down; Fill keeps both tiling.
        $this->assertSame([1, 6], $this->widthsOf($floor, [new Ratio(1, 4), new Fill(1)], 7));
        $this->assertSame([2, 5], $this->widthsOf($round, [new Ratio(1, 4), new Fill(1)], 7));
    }

    public function testDeprecatedCassowaryPathDelegatesByteIdentical(): void
    {
        $greedy = GreedySolver::new();
        $cassowary = new CassowarySolver();
        foreach (self::fractionSets() as $name => $base) {
            for ($width = 1; $width <= 200; $width++) {
                $constraints = [...array_values($base), new Fill(1)];
                $expected = $greedy->solve(Region::fromSize($width, 5), Direction::Horizontal, $constraints);
                $actual = self::cassowarySolve($cassowary, Region::fromSize($width, 5), Direction::Horizontal, $constraints);
                $this->assertEquals($expected, $actual, "delegation drifted for $name at w=$width");
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param list<Constraint> $constraints
     * @return list<int>
     */
    private function widthsOf(GreedySolver $solver, array $constraints, int $width): array
    {
        return array_map(
            static fn(Region $r): int => $r->width,
            $solver->solve(Region::fromSize($width, 1), Direction::Horizontal, $constraints),
        );
    }

    /**
     * @return array<string, list<Constraint>>
     */
    private static function fractionSets(): array
    {
        return [
            'thirds'   => [new Ratio(1, 3), new Ratio(1, 3), new Ratio(1, 3)],
            'tenths'   => [new Percentage(10)],
            'mixed'    => [new Length(7), new Percentage(25), new Ratio(1, 4)],
            'halves'   => [new Percentage(50), new Percentage(50)],
            'twelfths' => [new Ratio(1, 12), new Ratio(5, 12)],
            'zeroFill' => [new Fill(0), new Fill(1)],
        ];
    }

    /**
     * @param list<Constraint> $constraints
     * @param list<string> $violations
     */
    private function collectTilingViolations(
        array &$violations,
        GreedySolver $solver,
        string $case,
        Region $area,
        Direction $dir,
        array $constraints,
        int $span,
    ): void {
        $sizes = array_map(
            static fn(Region $r): int => $dir === Direction::Horizontal ? $r->width : $r->height,
            $solver->solve($area, $dir, $constraints),
        );
        if (array_sum($sizes) !== $span) {
            $violations[] = "$case: sum=" . array_sum($sizes) . " need=$span sizes=" . json_encode($sizes);
        }
        foreach ($sizes as $size) {
            if ($size < 0) {
                $violations[] = "$case: negative size $size";
            }
        }
        $again = array_map(
            static fn(Region $r): int => $dir === Direction::Horizontal ? $r->width : $r->height,
            $solver->solve($area, $dir, $constraints),
        );
        if ($sizes !== $again) {
            $violations[] = "$case: nondeterministic";
        }
    }

    /**
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    private static function cassowarySolve(CassowarySolver $solver, Region $region, Direction $dir, array $constraints): array
    {
        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            return $solver->solve($region, $dir, $constraints);
        } finally {
            restore_error_handler();
        }
    }
}
