<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Region;

/**
 * Characterizes the opt-in min-share floor (GreedySolver::withMinShare) against
 * a faithful in-test replica of sugar-boxer's NON-flex distribute(), and pins
 * the flag plumbing + the tight-viewport invariant it exists for.
 *
 * The floor is the fourth quirk compat()'s three flags cannot express: a
 * sequential "reserve ≥1 cell per not-yet-placed region" clamp that keeps the
 * trailing region from collapsing to 0. See {@see GreedySolver::withMinShare()}
 * and plan_missing.md W12.
 */
final class GreedySolverMinShareTest extends TestCase
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

    // ─────────────────────────────────────────────────────────────────────────
    // sugar-boxer distribute() replica — READ-ONLY reference for byte-parity.
    // (SugarBoxer::distribute() ~lines 888-915; returns starting offsets.)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param list<int> $weights
     * @return list<int> starting offsets
     */
    private static function distributeOffsets(int $available, array $weights, int $spacing, int $borderPad): array
    {
        $n = count($weights);
        $totalWeight = array_sum($weights);
        $contentSpan = $available - $spacing * max(0, $n - 1);

        if ($totalWeight === 0) {
            $totalWeight = $n;
            $weights = array_fill(0, $n, 1);
        }

        $offsets = [0 => $borderPad];
        $used = $borderPad;
        for ($i = 0; $i < $n - 1; $i++) {
            $share = (int) round($weights[$i] / $totalWeight * $contentSpan);
            $remainingChildren = $n - 1 - $i;
            $share = max(0, min($share, $contentSpan - $used - $remainingChildren));
            $used += $share + $spacing;
            $offsets[] = $used;
        }

        return $offsets;
    }

    /**
     * The rendered per-child sizes distribute() implies, exactly as SugarBoxer's
     * renderHorizontal() derives them: non-last = offset delta minus spacing;
     * last = full width minus border minus its offset. (available = w - 2b.)
     *
     * @param list<int> $weights
     * @return list<int>
     */
    private static function distributeSizes(int $available, array $weights, int $spacing, int $borderPad): array
    {
        $n = count($weights);
        $offsets = self::distributeOffsets($available, $weights, $spacing, $borderPad);
        $w = $available + 2 * $borderPad;
        $b = $borderPad;
        $sizes = [];
        for ($i = 0; $i < $n; $i++) {
            $sizes[] = ($i === $n - 1)
                ? ($w - $b - $offsets[$i])
                : ($offsets[$i + 1] - $offsets[$i] - $spacing);
        }
        return $sizes;
    }

    /** @param list<int> $weights @return Constraint[] */
    private static function fills(array $weights): array
    {
        return array_map(static fn (int $wt): Constraint => Constraint::fill($wt), $weights);
    }

    // ── Flag plumbing / immutability ────────────────────────────────────────

    public function testMinShareIsOffByDefault(): void
    {
        $d = GreedySolver::new();
        $this->assertSame(0, $d->minShare);
        $this->assertSame(0, $d->reserveGap);
        $this->assertSame(0, $d->reserveLead);
    }

    public function testWithMinShareDefaultsToOneCell(): void
    {
        $s = GreedySolver::new()->withMinShare();
        $this->assertSame(1, $s->minShare);
        $this->assertSame(0, $s->reserveGap);
        $this->assertSame(0, $s->reserveLead);
    }

    public function testWithMinShareSetsAllThreeAndIsImmutable(): void
    {
        $base = GreedySolver::new();
        $s = $base->withMinShare(2, 3, 4);
        $this->assertSame(2, $s->minShare);
        $this->assertSame(3, $s->reserveGap);
        $this->assertSame(4, $s->reserveLead);
        // Original instance untouched (immutable + fluent).
        $this->assertSame(0, $base->minShare);
        $this->assertNotSame($base, $s);
    }

    public function testFloorComposesWithCompatFlags(): void
    {
        $s = GreedySolver::compat()->withMinShare(1);
        // compat() flags survive the floor toggle …
        $this->assertTrue($s->roundSplit);
        $this->assertTrue($s->remainderToLast);
        $this->assertFalse($s->truncateOverflow);
        // … and the floor is engaged.
        $this->assertSame(1, $s->minShare);
    }

    public function testNegativeFloorParamsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GreedySolver(minShare: -1);
    }

    public function testNonFillConstraintRejectedUnderFloor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        GreedySolver::new()->withMinShare(1)->solve(
            Region::fromSize(20, 1),
            Direction::Horizontal,
            [Constraint::length(5), Constraint::fill(1)],
        );
    }

    // ── Functional: single / multi region ──────────────────────────────────

    public function testSingleRegionTakesFullSpan(): void
    {
        $rects = GreedySolver::new()->withMinShare(1)->solve(
            Region::fromSize(17, 1),
            Direction::Horizontal,
            self::fills([1]),
        );
        $this->assertSame([17], self::widths($rects));
    }

    public function testEqualWeightsSplitEvenly(): void
    {
        $rects = GreedySolver::new()->withMinShare(1)->solve(
            Region::fromSize(10, 1),
            Direction::Horizontal,
            self::fills([1, 1]),
        );
        $this->assertSame([5, 5], self::widths($rects));
    }

    public function testWeightedSplitLastAbsorbsRemainder(): void
    {
        // round(3/6*20)=10, round(2/6*20)=7 → last = 20-10-7 = 3.
        $rects = GreedySolver::new()->withMinShare(1)->solve(
            Region::fromSize(20, 1),
            Direction::Horizontal,
            self::fills([3, 2, 1]),
        );
        $widths = self::widths($rects);
        $this->assertSame(array_sum($widths), 20, 'sizes must tile the span exactly');
        $this->assertSame(self::distributeSizes(20, [3, 2, 1], 0, 0), $widths);
    }

    public function testWorksVerticallyViaFlip(): void
    {
        $rects = GreedySolver::new()->withMinShare(1)->solve(
            new Region(0, 0, 4, 10),
            Direction::Vertical,
            self::fills([1, 1]),
        );
        $this->assertSame([5, 5], self::heights($rects));
    }

    // ── The invariant the floor exists for ──────────────────────────────────

    public function testDefaultModeLetsTrailingRegionCollapseToZero(): void
    {
        // Baseline: without the floor a lopsided weight starves the last region.
        $rects = GreedySolver::new()->solve(
            Region::fromSize(10, 1),
            Direction::Horizontal,
            self::fills([100, 1]),
        );
        $this->assertSame([10, 0], self::widths($rects));
    }

    public function testMinShareKeepsTrailingRegionAlive(): void
    {
        // Same lopsided input, floor on → the trailing region survives (≥ 1).
        $rects = GreedySolver::new()->withMinShare(1)->solve(
            Region::fromSize(10, 1),
            Direction::Horizontal,
            self::fills([100, 1]),
        );
        $widths = self::widths($rects);
        $this->assertSame([9, 1], $widths);
        $this->assertGreaterThanOrEqual(1, $widths[1], 'trailing region must not collapse');
    }

    // ── Byte-parity characterization sweep vs distribute() ──────────────────

    public function testMinShareIsByteIdenticalToDistributeAcrossSweep(): void
    {
        $weightSets = [
            [1, 1], [1, 2], [2, 1], [1, 1, 1], [1, 2, 3], [3, 2, 1], [5, 1], [1, 5],
            [1, 1, 1, 1], [2, 2, 1], [10, 1, 1], [1, 10, 1], [0, 0], [1, 0, 1],
            [4, 3, 2, 1], [1, 1, 1, 1, 1], [7, 3, 5, 1], [1], [9], [2, 3, 5, 7, 11],
            [1, 1, 1, 1, 1, 1], [0, 0, 0], [6, 0, 6], [1, 2, 4, 8], [100, 1], [1, 100],
        ];
        $spacings = [0, 1, 2, 3];
        $borders = [0, 1];

        $cases = 0;
        $divergences = [];
        foreach ($weightSets as $weights) {
            $n = count($weights);
            $constraints = self::fills($weights);
            for ($available = 0; $available <= 48; $available++) {
                foreach ($spacings as $spacing) {
                    foreach ($borders as $borderPad) {
                        $content = $available - $spacing * max(0, $n - 1);
                        if ($content < 0) {
                            // sugar-boxer guards availableW <= 0 before distributing;
                            // a negative content span is out of contract.
                            continue;
                        }
                        $cases++;

                        $expected = self::distributeSizes($available, $weights, $spacing, $borderPad);

                        // Migration convention (mirrors distributeFlex): solve the
                        // content span, feed the reservation frame via withMinShare.
                        $rects = GreedySolver::new()
                            ->withMinShare(1, $spacing, $borderPad)
                            ->solve(Region::fromSize($content, 1), Direction::Horizontal, $constraints);
                        $actual = self::widths($rects);

                        if ($actual !== $expected) {
                            $divergences[] = sprintf(
                                'w=[%s] avail=%d sp=%d b=%d content=%d expected=[%s] got=[%s]',
                                implode(',', $weights),
                                $available,
                                $spacing,
                                $borderPad,
                                $content,
                                implode(',', $expected),
                                implode(',', $actual),
                            );
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(4896, $cases, 'sweep must exceed the ~4896-case target');
        $this->assertSame(
            [],
            $divergences,
            sprintf(
                '%d/%d cases diverged from distribute(); first few: %s',
                count($divergences),
                $cases,
                implode(' | ', array_slice($divergences, 0, 5))
            ),
        );
    }
}
