<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\Region;

/**
 * Pins for GreedySolver::demandOf() — the mechanized host-side degradation
 * comparison (finding 018 / SC-05). The README six-constraint tour set is the
 * canonical shape: its appetite exceeds the 100-cell span, which is exactly
 * the signal the Degradation section now points at this method for.
 */
final class GreedySolverDemandTest extends TestCase
{
    /**
     * The README tour: 20 (length) + 10 (min floor) + 0 (fill claims only
     * slack) + 30 (percentage of 100, floored) + 33 (ratio 1/3 of 100,
     * floored) + 50 (max cap) = 143. Demand above the span is the whole
     * point — truncation below would hide.
     */
    public function testReadmeTourSetDemandExceedsItsSpan(): void
    {
        $solver = GreedySolver::new();
        $constraints = [
            Constraint::length(20),
            Constraint::min(10),
            Constraint::fill(1),
            Constraint::percentage(30),
            Constraint::ratio(1, 3),
            Constraint::max(50),
        ];

        $demand = $solver->demandOf(Region::fromSize(100, 20), Direction::Horizontal, $constraints);

        $this->assertSame(143, $demand);
        $this->assertGreaterThan(100, $demand, 'the documented tour set must be detectably over its span');
    }

    public function testVerticalAxisMeasuresHeightNotWidth(): void
    {
        $solver = GreedySolver::new();
        $constraints = [Constraint::length(20), Constraint::min(5)];

        $this->assertSame(25, $solver->demandOf(Region::fromSize(5, 80), Direction::Vertical, $constraints));
    }

    public function testProportionalResolutionFollowsTheInstanceRoundingPolicy(): void
    {
        $constraints = [Constraint::ratio(1, 3)];
        // 101 / 3 is 33.67: the default floors, compat() rounds — demandOf
        // resolves with the SAME arithmetic the allocation walk uses.
        $this->assertSame(33, GreedySolver::new()->demandOf(Region::fromSize(101, 1), Direction::Horizontal, $constraints));
        $this->assertSame(34, GreedySolver::compat()->demandOf(Region::fromSize(101, 1), Direction::Horizontal, $constraints));
        // Percentage shares the door: 33% of 101 floors to 33 either way here,
        // so pin the policy-sensitive percentage edge as well: 101*15/100=15.15.
        $this->assertSame(15, GreedySolver::new()->demandOf(Region::fromSize(101, 1), Direction::Horizontal, [Constraint::percentage(15)]));
        $this->assertSame(15, GreedySolver::compat()->demandOf(Region::fromSize(101, 1), Direction::Horizontal, [Constraint::percentage(15)]));
        // A genuinely half-way percentage: 50*45/100 = 22.5 → floor 22, round 23.
        $this->assertSame(22, GreedySolver::new()->demandOf(Region::fromSize(50, 1), Direction::Horizontal, [Constraint::percentage(45)]));
        $this->assertSame(23, GreedySolver::compat()->demandOf(Region::fromSize(50, 1), Direction::Horizontal, [Constraint::percentage(45)]));
    }

    public function testFillContributesNothingAndMaxItsWholeCap(): void
    {
        $solver = GreedySolver::new();
        $this->assertSame(
            0,
            $solver->demandOf(Region::fromSize(100, 1), Direction::Horizontal, [Constraint::fill(7), Constraint::fill(1)])
        );
        $this->assertSame(
            60,
            $solver->demandOf(Region::fromSize(100, 1), Direction::Horizontal, [Constraint::max(60)])
        );
    }

    public function testUnderConstrainedSetSignalsNoTruncation(): void
    {
        $demand = GreedySolver::new()->demandOf(
            Region::fromSize(100, 1),
            Direction::Horizontal,
            [Constraint::length(24), Constraint::fill(1)]
        );
        $this->assertSame(24, $demand);
        $this->assertLessThanOrEqual(100, $demand, 'the sidebar quickstart set is never over-constrained');
    }

    public function testEmptyListDemandsZero(): void
    {
        $this->assertSame(0, GreedySolver::new()->demandOf(Region::fromSize(100, 1), Direction::Horizontal, []));
    }

    public function testKeyedArraySharesTheSolveBoundaryRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraints must be a list (sequential array of Constraint); got a keyed or sparse array');
        GreedySolver::new()->demandOf(
            Region::fromSize(100, 1),
            Direction::Horizontal,
            ['a' => Constraint::length(10)]
        );
    }

    public function testForeignPayloadNamesItsIndexAndType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported constraint type at index 1: int');
        GreedySolver::new()->demandOf(
            Region::fromSize(100, 1),
            Direction::Horizontal,
            [Constraint::length(10), 7]
        );
    }

    public function testDemandOfIsPureAndSolverStateless(): void
    {
        $solver = GreedySolver::new();
        $constraints = [Constraint::min(30), Constraint::max(10)];
        $first = $solver->demandOf(Region::fromSize(40, 1), Direction::Horizontal, $constraints);
        $second = $solver->demandOf(Region::fromSize(40, 1), Direction::Horizontal, $constraints);
        $this->assertSame($first, $second);
        // The walk never mutates the instance's policy flags.
        $this->assertFalse($solver->roundSplit);
        $this->assertFalse($solver->remainderToLast);
        $this->assertSame(0, $solver->minShare);
    }
}
