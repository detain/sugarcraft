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
 * CassowarySolver is hard-deprecated: its Big-M simplex never converged, so
 * `solve()` now emits E_USER_DEPRECATED and delegates wholly to GreedySolver.
 *
 * These tests pin (a) that the deprecation fires and (b) that the delegated
 * output is bit-equivalent to calling GreedySolver directly — which is also the
 * fix for the old Ratio-returns-0 bug.
 */
final class CassowarySolverTest extends TestCase
{
    /**
     * Call CassowarySolver::solve() with the E_USER_DEPRECATED swallowed, so
     * assertions about the *result* stay clean under failOnWarning. The
     * separate {@see testSolveEmitsDeprecation} verifies the notice itself.
     *
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    private function cassowarySolve(Region $region, Direction $dir, array $constraints): array
    {
        set_error_handler(static fn(): bool => true, E_USER_DEPRECATED);
        try {
            return (CassowarySolver::new())->solve($region, $dir, $constraints);
        } finally {
            restore_error_handler();
        }
    }

    // ── LayoutSolver interface + factories ──────────────────────────────────

    public function testSolverImplementsLayoutSolver(): void
    {
        $this->assertInstanceOf(LayoutSolver::class, CassowarySolver::new());
    }

    public function testFactoryMethods(): void
    {
        $this->assertInstanceOf(GreedySolver::class, CassowarySolver::greedy());
        $this->assertInstanceOf(CassowarySolver::class, CassowarySolver::cassowary());
        $this->assertInstanceOf(CassowarySolver::class, CassowarySolver::new());
    }

    // ── Deprecation notice ──────────────────────────────────────────────────

    /**
     * solve() must emit exactly one E_USER_DEPRECATED naming the replacement.
     */
    public function testSolveEmitsDeprecation(): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = [$errno, $errstr];
            return true;
        }, E_USER_DEPRECATED);
        try {
            (CassowarySolver::new())->solve(
                new Region(0, 0, 100, 24),
                Direction::Horizontal,
                [Constraint::length(50)]
            );
        } finally {
            restore_error_handler();
        }

        $this->assertCount(1, $captured, 'solve() must emit exactly one deprecation');
        $this->assertSame(E_USER_DEPRECATED, $captured[0][0]);
        $this->assertStringContainsStringIgnoringCase('deprecated', $captured[0][1]);
        $this->assertStringContainsString('GreedySolver', $captured[0][1]);
    }

    // ── Delegation is bit-equivalent to GreedySolver ────────────────────────

    /**
     * For every constraint mix, CassowarySolver::solve() must return exactly
     * what GreedySolver::solve() returns — the delegation is total.
     *
     * @dataProvider delegationProvider
     * @param list<Constraint> $constraints
     */
    public function testSolveDelegatesToGreedyBitEquivalent(
        Region $region,
        Direction $dir,
        array $constraints,
    ): void {
        $actual = $this->cassowarySolve($region, $dir, $constraints);
        $expected = (GreedySolver::new())->solve($region, $dir, $constraints);

        $this->assertEquals($expected, $actual);
    }

    /** @return array<string, array{Region, Direction, list<Constraint>}> */
    public static function delegationProvider(): array
    {
        return [
            'empty' => [new Region(0, 0, 100, 24), Direction::Horizontal, []],
            'pure length h' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::length(20), Constraint::length(30), Constraint::length(25)],
            ],
            'length + fill h' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::length(20), Constraint::fill(1)],
            ],
            'three fills h' => [
                new Region(0, 0, 90, 24), Direction::Horizontal,
                [Constraint::fill(), Constraint::fill(), Constraint::fill()],
            ],
            'percentage h' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::percentage(30), Constraint::percentage(70)],
            ],
            // Previously the simplex Ratio path returned 0; delegation fixes it.
            'ratio h (fixes ratio bug)' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::ratio(1, 2), Constraint::ratio(1, 2)],
            ],
            'min h' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::min(20), Constraint::min(30)],
            ],
            'max h' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::length(20), Constraint::max(30)],
            ],
            'mixed length/min/fill h' => [
                new Region(0, 0, 100, 24), Direction::Horizontal,
                [Constraint::length(20), Constraint::min(10), Constraint::fill(1)],
            ],
            'pure length v' => [
                new Region(0, 0, 80, 30), Direction::Vertical,
                [Constraint::length(3), Constraint::length(10), Constraint::length(1)],
            ],
            'length + fill v' => [
                new Region(0, 0, 80, 50), Direction::Vertical,
                [Constraint::length(10), Constraint::fill(1)],
            ],
            'non-zero origin h' => [
                new Region(5, 7, 100, 24), Direction::Horizontal,
                [Constraint::length(20), Constraint::min(10), Constraint::fill(1)],
            ],
        ];
    }

    /**
     * Ratio through the deprecated shim now distributes correctly (delegation
     * fix), instead of the old simplex's 0. Pins the concrete widths.
     */
    public function testRatioDelegationDistributesCorrectly(): void
    {
        $rects = $this->cassowarySolve(
            new Region(0, 0, 100, 24),
            Direction::Horizontal,
            [Constraint::ratio(1, 2), Constraint::ratio(1, 2)]
        );

        $this->assertCount(2, $rects);
        $this->assertSame(50, $rects[0]->width);
        $this->assertSame(50, $rects[1]->width);
    }

    /**
     * Empty constraint list still delegates cleanly to an empty result.
     */
    public function testEmptyConstraintsDelegate(): void
    {
        $this->assertSame(
            [],
            $this->cassowarySolve(new Region(0, 0, 100, 24), Direction::Horizontal, [])
        );
    }
}
