<?php

declare(strict_types=1);

namespace SugarCraft\Boxer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Boxer\Node;
use SugarCraft\Boxer\SugarBoxer;
use SugarCraft\Layout\GreedySolver;

/**
 * Load-bearing pins for the distributeFlex() → candy-layout delegation
 * (plan_missing.md W12 / candy-layout #1372).
 *
 * distributeFlex() no longer computes flex distribution by hand — it maps each
 * child to a candy-layout Constraint (fixed → Length, flex → Fill) and solves
 * with {@see GreedySolver::compat()}. A 3456-layout offset sweep plus a
 * 6448-layout rendered-byte sweep confirmed this is byte-identical to the old
 * arithmetic. These tests fail if the delegation is reverted or the solver's
 * compat axes (round-split / remainder-to-last / non-truncating overflow) are
 * changed — i.e. they guard against re-introducing the #1261 overflow regression
 * that the compat() seam exists to prevent.
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
}
