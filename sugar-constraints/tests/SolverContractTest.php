<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Constraints\Constraint\Constraint;
use SugarCraft\Constraints\Constraint\Fill;
use SugarCraft\Constraints\Direction;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\LayoutSolver;
use SugarCraft\Constraints\Region;

/**
 * Contract-level guards: input validation on solve(), the shape of its
 * refusal messages, the static-call trap on the LayoutSolver interface, and
 * the dock geometry iteration order. These are the promises the docblocks
 * make that the allocation sweeps do not otherwise exercise.
 */
final class SolverContractTest extends TestCase
{
    public function testAKeyedConstraintArrayIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraints must be a list');

        GreedySolver::new()->solve(
            Region::fromSize(10, 1),
            Direction::Horizontal,
            ['a' => Constraint::fill(1)],
        );
    }

    public function testASparseConstraintArrayIsRefusedByName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Constraints must be a list');

        GreedySolver::new()->solve(
            Region::fromSize(10, 1),
            Direction::Horizontal,
            [0 => Constraint::fill(1), 2 => Constraint::fill(1)],
        );
    }

    public function testAProperListStillSolvesAfterTheGuardArms(): void
    {
        // Positive control: the guard must not bite well-formed input, and
        // zero-keyed lists (a common hand-built shape) stay legal.
        $rects = GreedySolver::new()->solve(
            Region::fromSize(10, 1),
            Direction::Horizontal,
            [0 => Constraint::fill(1), 1 => Constraint::fill(1)],
        );

        $this->assertSame([5, 5], array_map(static fn (Region $r): int => $r->width, $rects));
    }

    public function testTheUnsupportedTypeRefusalNamesIndexAndRuntimeType(): void
    {
        $caught = null;
        try {
            GreedySolver::new()->solve(
                Region::fromSize(10, 1),
                Direction::Horizontal,
                [Constraint::length(4), new \stdClass()],
            );
        } catch (\InvalidArgumentException $e) {
            $caught = $e->getMessage();
        }

        $this->assertNotNull($caught, 'a foreign object in the constraint list must be refused');
        $this->assertStringContainsString('index 1', $caught, 'the refusal must locate the offender: ' . $caught);
        $this->assertStringContainsString('stdClass', $caught, 'the refusal must name the runtime type: ' . $caught);
    }

    public function testTheInterfaceDeclaresNoStaticMembersSoStaticFactoryCallsFatal(): void
    {
        $contract = new \ReflectionClass(LayoutSolver::class);

        $this->assertTrue($contract->isInterface());
        // Interfaces expose no inherited static surface; the ::new()/::greedy()/
        // ::compat() factories live on GreedySolver. Claiming otherwise in prose
        // ("call LayoutSolver::greedy()") would compile-fatal for readers.
        $statics = array_values(array_filter(
            $contract->getMethods(),
            static fn (\ReflectionMethod $m): bool => $m->isStatic(),
        ));
        $this->assertSame([], $statics);
        $this->assertFalse(method_exists(LayoutSolver::class, 'greedy'));
        $this->assertTrue(method_exists(GreedySolver::class, 'greedy'));
    }

    public function testRegionsIterateInInsertionOrderLeftStackThenCenterThenRightStack(): void
    {
        $dock = DockLayout::new('chat')
            ->withSlotAdded(Side::Left, 'files')
            ->withSlotAdded(Side::Left, 'git')
            ->withSlotAdded(Side::Right, 'term');

        $geometry = $dock->resolve(Region::fromSize(100, 30));

        $this->assertSame(['files', 'git', 'chat', 'term'], array_keys($geometry->regions));
    }

    #[DataProvider('allZeroWeightFillShapes')]
    public function testAnAllZeroFillSetTilesNothingUnderTheDefaultPolicy(int $span, int $regions): void
    {
        // totalWeight 0 divides nothing: every Fill collapses to 0 and the
        // span stays untiled. Documented carve-out of the tiling guarantee
        // (see GreedySolver class docblock); the min-share floor restores it.
        $constraints = array_fill(0, $regions, new Fill(0));

        $widths = array_map(
            static fn (Region $r): int => $r->width,
            GreedySolver::new()->solve(Region::fromSize($span, 1), Direction::Horizontal, $constraints),
        );

        $this->assertSame(array_fill(0, $regions, 0), $widths);
    }

    /** @return array<string, array{int, int}> */
    public static function allZeroWeightFillShapes(): array
    {
        return [
            'two panes at 100' => [100, 2],
            'three panes at 2026' => [2026, 3],
        ];
    }
}
