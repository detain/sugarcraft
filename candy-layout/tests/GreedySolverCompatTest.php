<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Region;

/**
 * Characterizes the opt-in boxer-compat solver mode against hand-computed
 * expected splits, and pins the DEFAULT mode's divergent behaviour on the same
 * inputs so the two modes are provably distinct.
 *
 * Compat mode reproduces sugar-boxer's distribute()/distributeFlex():
 * round()-split + remainder-to-LAST + non-truncating overflow. See
 * {@see GreedySolver::compat()} and plan_missing.md W12.
 */
final class GreedySolverCompatTest extends TestCase
{
    /** @param Region[] $rects @return int[] */
    private static function widths(array $rects): array
    {
        return array_map(static fn (Region $r): int => $r->width, $rects);
    }

    /** @param Region[] $rects @return int[] */
    private static function heights(array $rects): array
    {
        return array_map(static fn (Region $r): int => $r->height, $rects);
    }

    /** @param Region[] $rects @return int[] */
    private static function xs(array $rects): array
    {
        return array_map(static fn (Region $r): int => $r->x, $rects);
    }

    // ── Flag plumbing / immutability ────────────────────────────────────────

    public function testCompatSetsAllThreeFlags(): void
    {
        $c = GreedySolver::compat();
        $this->assertTrue($c->roundSplit);
        $this->assertTrue($c->remainderToLast);
        $this->assertFalse($c->truncateOverflow);
    }

    public function testDefaultFlagsPreserveLegacyBehaviour(): void
    {
        $d = GreedySolver::new();
        $this->assertFalse($d->roundSplit);
        $this->assertFalse($d->remainderToLast);
        $this->assertTrue($d->truncateOverflow);
    }

    public function testGranularTogglesEqualCompat(): void
    {
        $built = GreedySolver::new()
            ->withRoundSplit()
            ->withRemainderToLast()
            ->withoutOverflowTruncation();

        $this->assertEquals(GreedySolver::compat(), $built);
    }

    public function testWithTogglesAreImmutable(): void
    {
        $base = GreedySolver::new();
        $mutated = $base->withRoundSplit();

        // The receiver is unchanged; a NEW instance carries the flag.
        $this->assertFalse($base->roundSplit);
        $this->assertTrue($mutated->roundSplit);
        $this->assertNotSame($base, $mutated);
    }

    // ── Single flex ─────────────────────────────────────────────────────────

    public function testCompatSingleFlexFillsRemainingSpan(): void
    {
        // [Length(3), Fill(1)] in width 10: the lone flex child takes all slack.
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 10, 5),
            Direction::Horizontal,
            [Constraint::length(3), Constraint::fill(1)]
        );

        $this->assertSame([3, 7], self::widths($rects));
        $this->assertSame([0, 3], self::xs($rects));
    }

    // ── Multi-flex: remainder to LAST flex ──────────────────────────────────

    public function testCompatMultiFlexRemainderGoesToLast(): void
    {
        // [Fill, Fill, Fill] in width 10: floor(10/3)=3 each = 9, remainder 1.
        // Compat hands the leftover to the LAST fill.
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 10, 5),
            Direction::Horizontal,
            [Constraint::fill(1), Constraint::fill(1), Constraint::fill(1)]
        );

        $this->assertSame([3, 3, 4], self::widths($rects));
    }

    public function testDefaultMultiFlexRemainderGoesToFirst(): void
    {
        // Same input, default mode: remainder to the FIRST fill → proves distinct.
        $rects = GreedySolver::new()->solve(
            new Region(0, 0, 10, 5),
            Direction::Horizontal,
            [Constraint::fill(1), Constraint::fill(1), Constraint::fill(1)]
        );

        $this->assertSame([4, 3, 3], self::widths($rects));
    }

    // ── Non-flex: round() split ─────────────────────────────────────────────

    public function testCompatNonFlexUsesRoundSplit(): void
    {
        // [33%, 33%, 33%] in width 30: exact 9.9 each.
        // round(9.9)=10 → [10,10,10]. floor(9.9)=9 → default leaves [9,9,9].
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 30, 5),
            Direction::Horizontal,
            [Constraint::percentage(33), Constraint::percentage(33), Constraint::percentage(33)]
        );

        $this->assertSame([10, 10, 10], self::widths($rects));
    }

    public function testDefaultNonFlexUsesFloorSplit(): void
    {
        $rects = GreedySolver::new()->solve(
            new Region(0, 0, 30, 5),
            Direction::Horizontal,
            [Constraint::percentage(33), Constraint::percentage(33), Constraint::percentage(33)]
        );

        // floor(9.9)=9; the 3-cell shortfall exceeds the diff<=2 reclaim guard,
        // so default leaves it as intentional slack.
        $this->assertSame([9, 9, 9], self::widths($rects));
    }

    // ── Non-flex: rounding remainder to LAST region ─────────────────────────

    public function testCompatNonFlexRemainderGoesToLast(): void
    {
        // [33%, 33%, 33%] in width 10: round(3.3)=3 each = 9, remainder 1.
        // Compat hands the leftover cell to the LAST region.
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 10, 5),
            Direction::Horizontal,
            [Constraint::percentage(33), Constraint::percentage(33), Constraint::percentage(33)]
        );

        $this->assertSame([3, 3, 4], self::widths($rects));
        $this->assertSame([0, 3, 6], self::xs($rects));
    }

    public function testDefaultNonFlexRemainderGoesToFirst(): void
    {
        // Same input, default: floor(3.3)=3 each, diff=1 (<=2) reclaimed to the
        // FIRST Percentage → [4,3,3]. Proves the two modes are distinct.
        $rects = GreedySolver::new()->solve(
            new Region(0, 0, 10, 5),
            Direction::Horizontal,
            [Constraint::percentage(33), Constraint::percentage(33), Constraint::percentage(33)]
        );

        $this->assertSame([4, 3, 3], self::widths($rects));
    }

    // ── Overflow: regions keep full base size, NO truncation ────────────────

    public function testCompatOverflowKeepsFullBaseSizeNoTruncation(): void
    {
        // [Length(60), Length(60)] in width 80: demand 120 > 80.
        // Compat keeps each region at its full base size (layout runs off-grid),
        // matching sugar-boxer where fixed children never shrink.
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 80, 5),
            Direction::Horizontal,
            [Constraint::length(60), Constraint::length(60)]
        );

        $this->assertSame([60, 60], self::widths($rects));
        // Second region starts at x=60 and runs to 120 — deliberately off-grid.
        $this->assertSame([0, 60], self::xs($rects));
    }

    public function testDefaultOverflowTruncatesProportionally(): void
    {
        // Same input, default: 60:60 truncated proportionally into width 80.
        $rects = GreedySolver::new()->solve(
            new Region(0, 0, 80, 5),
            Direction::Horizontal,
            [Constraint::length(60), Constraint::length(60)]
        );

        $this->assertSame([40, 40], self::widths($rects));
    }

    public function testCompatOverflowKeepsFullBaseSizeForMin(): void
    {
        // [Min(50), Min(50)] in width 80: reserved 100 > 80.
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 80, 5),
            Direction::Horizontal,
            [Constraint::min(50), Constraint::min(50)]
        );

        $this->assertSame([50, 50], self::widths($rects));
    }

    public function testCompatOverflowNonTruncatingVertical(): void
    {
        // Vertical overflow: [Length(20), Length(20)] in height 30 keeps 20,20.
        $rects = GreedySolver::compat()->solve(
            new Region(0, 0, 12, 30),
            Direction::Vertical,
            [Constraint::length(20), Constraint::length(20)]
        );

        $this->assertSame([20, 20], self::heights($rects));

        // Default truncates the same input to 15,15.
        $def = GreedySolver::new()->solve(
            new Region(0, 0, 12, 30),
            Direction::Vertical,
            [Constraint::length(20), Constraint::length(20)]
        );
        $this->assertSame([15, 15], self::heights($def));
    }

    // ── solveStatic() stays on the DEFAULT path ─────────────────────────────

    public function testSolveStaticIsAlwaysDefaultMode(): void
    {
        // The static parity entry point never picks up the compat toggles.
        $rects = GreedySolver::solveStatic(
            new Region(0, 0, 80, 5),
            [Constraint::length(60), Constraint::length(60)],
            Direction::Horizontal
        );

        $this->assertSame([40, 40], self::widths($rects));
    }
}
