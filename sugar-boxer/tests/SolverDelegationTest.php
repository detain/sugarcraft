<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Boxer\Node;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Layout\Constraint\Constraint;
use SugarCraft\Layout\Direction;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\Region;

/**
 * Load-bearing pins for the distribute()/distributeFlex() → candy-layout
 * delegation (plan_missing.md W12 / candy-layout #1372 + #1386).
 *
 * BOTH sugar-boxer distribution paths now delegate to candy-layout (fork-b dedup
 * complete): distributeFlex() maps each child to a Constraint (fixed → Length,
 * flex → Fill) solved with {@see GreedySolver::compat()}; the NON-flex
 * distribute() maps each child to a Fill weighted by minWidth/minHeight solved
 * with {@see GreedySolver::withMinShare()} — whose sequential min-share floor
 * reproduces distribute()'s bespoke "reserve >=1 col per not-yet-placed child"
 * clamp. A 28 280-case offset sweep + 960-layout rendered-byte sweep confirmed
 * the distribute() migration is byte-identical; a 3456-layout offset + 6448-layout
 * byte sweep did the same for distributeFlex(). These tests fail if either
 * delegation is reverted or mis-parameterised — e.g. dropping the min-share floor
 * (trailing child collapses to 0) or changing distributeFlex's compat axes
 * (re-introducing the #1261 overflow regression).
 */
final class SolverDelegationTest extends TestCase
{
    /**
     * @param list<int> $bases
     * @param list<int> $flexes
     * @return list<int>
     */
    private function distributeFlex(int $available, array $bases, array $flexes, int $spacing, int $borderPad): array
    {
        $boxer = SugarBoxer::new();
        $m = new \ReflectionMethod(SugarBoxer::class, 'distributeFlex');
        $m->setAccessible(true);
        return $m->invoke($boxer, $available, $bases, $flexes, $spacing, $borderPad);
    }

    /**
     * @param list<int> $weights
     * @return list<int>
     */
    private function distribute(int $available, array $weights, int $spacing, int $borderPad): array
    {
        $boxer = SugarBoxer::new();
        $m = new \ReflectionMethod(SugarBoxer::class, 'distribute');
        $m->setAccessible(true);
        return $m->invoke($boxer, $available, $weights, \array_sum($weights), $spacing, $borderPad);
    }

    /**
     * Derive per-child widths from the offset array exactly as renderHorizontal()
     * does: non-last child = gap-adjusted offset delta; last child = leftover.
     *
     * @param list<int> $offsets
     * @return list<int>
     */
    private function childWidths(array $offsets, int $available, int $spacing, int $borderPad): array
    {
        $n = \count($offsets);
        $widths = [];
        for ($i = 0; $i < $n; $i++) {
            $widths[] = $i === $n - 1
                ? $available + $borderPad - $offsets[$i]
                : $offsets[$i + 1] - $offsets[$i] - $spacing;
        }
        return $widths;
    }

    /**
     * The flex path is backed by candy-layout's solver in sugar-boxer parity
     * mode — the exact three-axis configuration that reproduces the old
     * hand-rolled distribution byte-for-byte.
     */
    public function testDistributeFlexIsBackedByCompatGreedySolver(): void
    {
        $boxer = SugarBoxer::new();
        $p = new \ReflectionProperty(SugarBoxer::class, 'flexSolver');
        $p->setAccessible(true);
        $solver = $p->getValue($boxer);

        $this->assertInstanceOf(GreedySolver::class, $solver, 'flex distribution delegates to candy-layout GreedySolver');
        $this->assertTrue($solver->roundSplit, 'compat: round-split ON');
        $this->assertTrue($solver->remainderToLast, 'compat: remainder-to-last ON');
        $this->assertFalse($solver->truncateOverflow, 'compat: overflow truncation OFF (fixed children keep full base)');
        // Equivalent to the ::compat() factory the constructor calls.
        $this->assertEquals(GreedySolver::compat(), $solver);
    }

    /**
     * Fixed sibling reserves its natural size (Length); the single flex child
     * absorbs the leftover after the inter-child gap.
     */
    public function testCharacterizedBasicFlexSplit(): void
    {
        // content = 20 - 1 gap = 19; Length(6) + Fill(1) → sizes [6,13]; offsets [0, 0+6+1].
        $this->assertSame([0, 7], $this->distributeFlex(20, [6, 0], [0, 1], 1, 0));
    }

    /**
     * OVERFLOW, non-truncating: fixed bases (3 + 3) exceed the 5-cell span, so
     * the flex child collapses to 0 and BOTH fixed children keep their FULL base
     * size and run off-grid. A truncating solver would shrink them to [2,·,2]
     * (offsets [0,2,2]); compat keeps [3,·,3] (offsets [0,3,3]). This is the exact
     * regression the naive Greedy path caused at #1261.
     */
    public function testCharacterizedOverflowKeepsFixedBasesFullSize(): void
    {
        $this->assertSame([0, 3, 3], $this->distributeFlex(5, [3, 0, 3], [0, 1, 0], 0, 0));
    }

    /**
     * Multiple equal-weight flex children: floor() shares lose 2 cells over a
     * 20-cell span (6+6+6=18) and the LAST flex child absorbs the whole remainder
     * → sizes [6,6,8], offsets [0,6,12]. A remainder-to-FIRST solver would yield
     * [8,6,6] / offsets [0,8,14].
     */
    public function testCharacterizedRemainderGoesToLastFlex(): void
    {
        $this->assertSame([0, 6, 12], $this->distributeFlex(20, [0, 0, 0], [1, 1, 1], 0, 0));
    }

    /**
     * Weighted flex split with a leading fixed child and a border pad: content =
     * 24, Length(4) reserves 4, remaining 20 split 3:1 → [15,5], last flex absorbs
     * the remainder. offsets start at the border pad (1).
     */
    public function testCharacterizedWeightedFlexSplitWithBorderPad(): void
    {
        $this->assertSame([1, 5, 20], $this->distributeFlex(24, [4, 0, 0], [0, 3, 1], 0, 1));
    }

    /**
     * End-to-end render proof of non-truncating overflow: two fixed panels plus a
     * flex panel in a 5-wide viewport. compat keeps the fixed panels at full width
     * so the frame reads "AA│CC"; a truncating solver would shrink the first panel
     * and render "A│CCC".
     */
    public function testOverflowRenderKeepsFixedPanelsFullSize(): void
    {
        $layout = Node::horizontal(
            Node::leaf('AAA')->withBorder(false)->withMinWidth(3),
            Node::leaf('B')->withBorder(false)->withGrow(),
            Node::leaf('CCC')->withBorder(false)->withMinWidth(3),
        )->withBorder(false)->withSpacing(0);

        $out = SugarBoxer::new()->render($layout, 5, 1);
        $this->assertSame('AA│CC', \rtrim($out));
    }

    // -------------------------------------------------------------------------
    // NON-flex distribute() → GreedySolver::withMinShare() (candy-layout #1386)
    // -------------------------------------------------------------------------

    /**
     * Characterized non-flex splits: representative offset arrays captured from
     * the pre-migration hand-rolled distribute(). A 28 280-case sweep proved the
     * withMinShare() delegation reproduces these byte-for-byte; these pins fail if
     * the mapping or the floor's reserveGap/reserveLead frame ever drifts.
     */
    public function testCharacterizedNonFlexSplits(): void
    {
        // Even split, no gaps/pad: round-split, last child absorbs the remainder.
        $this->assertSame([0, 7, 14], $this->distribute(20, [1, 1, 1], 0, 0));
        // Same with a 1-cell inter-child gap folded into the content span.
        $this->assertSame([0, 7, 14], $this->distribute(20, [1, 1, 1], 1, 0));
        // Leading border pad shifts every offset by 1.
        $this->assertSame([1, 19], $this->distribute(24, [3, 1], 0, 1));
        // Weighted split with spacing + border pad.
        $this->assertSame([1, 4, 7], $this->distribute(10, [2, 3, 5], 2, 1));
    }

    /**
     * The min-share floor is load-bearing: in a viewport too tight for a naive
     * proportional split, distribute() still reserves >= 1 column for EVERY child
     * so the trailing children never collapse to 0. A plain candy-layout solve
     * (no withMinShare floor) starves them — asserted here as the contrast, which
     * pins that distribute() routes through withMinShare(1, spacing, borderPad)
     * with the correct reservation frame and not a bare Fill split.
     */
    public function testTightViewportFloorReservesEveryChild(): void
    {
        // weights [10,1,1] into a 6-cell span: naive round would hand ~5 cols to
        // the heavy child and 0 to the tail; the floor yields widths [4,1,1].
        $offsets = $this->distribute(6, [10, 1, 1], 0, 0);
        $this->assertSame([0, 4, 5], $offsets);
        foreach ($this->childWidths($offsets, 6, 0, 0) as $w) {
            $this->assertGreaterThanOrEqual(1, $w, 'every child keeps >= 1 column under the min-share floor');
        }

        // 4 children into a 5-cell span: widths [1,1,1,2] — still no collapse.
        $offsets = $this->distribute(5, [1, 1, 1, 1], 0, 0);
        $this->assertSame([0, 1, 2, 3], $offsets);
        foreach ($this->childWidths($offsets, 5, 0, 0) as $w) {
            $this->assertGreaterThanOrEqual(1, $w, 'every child keeps >= 1 column under the min-share floor');
        }

        // Contrast: a floor-LESS candy-layout solve collapses the tail. This is
        // exactly what the migration must NOT regress to.
        $naive = GreedySolver::new()->solve(
            Region::fromSize(6, 1),
            Direction::Horizontal,
            [Constraint::fill(10), Constraint::fill(1), Constraint::fill(1)],
        );
        $this->assertSame(0, $naive[1]->width, 'without the floor the 2nd child collapses');
        $this->assertSame(0, $naive[2]->width, 'without the floor the 3rd child collapses');
    }

    /**
     * Structural delegation pin: distribute()'s offsets are exactly what
     * candy-layout's {@see GreedySolver::withMinShare()} produces for the same
     * Fill mapping and reservation frame (minShare 1, reserveGap = spacing,
     * reserveLead = borderPad). Reconstructs the expected offsets by driving the
     * solver directly across a matrix, so it fails if distribute() stops
     * delegating or passes the wrong floor parameters.
     */
    public function testDistributeMatchesCandyLayoutWithMinShare(): void
    {
        $cases = [
            [20, [1, 1, 1], 0, 0],
            [20, [1, 1, 1], 1, 0],
            [24, [3, 1], 0, 1],
            [6, [10, 1, 1], 0, 0],
            [5, [1, 1, 1, 1], 0, 0],
            [10, [2, 3, 5], 2, 1],
            [40, [1, 3, 8, 2, 5], 1, 1],
        ];
        foreach ($cases as [$available, $weights, $spacing, $borderPad]) {
            $n = \count($weights);
            $contentSpan = $available - $spacing * \max(0, $n - 1);
            $regions = GreedySolver::new()
                ->withMinShare(1, $spacing, $borderPad)
                ->solve(
                    Region::fromSize(\max(0, $contentSpan), 1),
                    Direction::Horizontal,
                    \array_map(static fn (int $w): Constraint => Constraint::fill($w), $weights),
                );
            $expected = [$borderPad];
            for ($i = 0; $i < $n - 1; $i++) {
                $expected[] = $expected[$i] + $regions[$i]->width + $spacing;
            }
            $this->assertSame(
                $expected,
                $this->distribute($available, $weights, $spacing, $borderPad),
                "distribute() must match candy-layout withMinShare for [{$available}, ("
                    . \implode(',', $weights) . "), sp={$spacing}, pad={$borderPad}]",
            );
        }
    }
}
