<?php

declare(strict_types=1);

namespace SugarCraft\Constraints;

/**
 * Interface for constraint-based layout solvers.
 *
 * Solves a list of constraints against a region in the given direction,
 * returning a list of sub-regions. For a well-constrained set the returned
 * widths (or heights) tile the region's total dimension exactly. Four shapes
 * deliberately fall outside that guarantee:
 *  - an UNDER-CONSTRAINED set — nothing but fixed Length/Percentage/Ratio
 *    whose sum is below the region — leaves an untiled gap rather than
 *    inventing growth for constraints that asked for none;
 *  - a solver in compatibility mode ({@see GreedySolver::compat()},
 *    {@see GreedySolver::withoutOverflowTruncation()}) keeps every region at
 *    its full base size when demand exceeds the area, so the layout runs
 *    off-grid on purpose;
 *  - a set with zero total elastic demand — every `Fill` weight 0, so no
 *    positive-weight recipient exists to absorb the span — leaves the slack
 *    untiled (`[fill(0)]@100` resolves to one 0-wide region);
 *  - extreme-magnitude inputs — spans or sizes large enough that the
 *    `span × n` / `span × numerator` products overflow int64 (they are
 *    unguarded) — where resolved sizes ride IEEE-754 rounding and an
 *    overflowing final cast makes {@see Region} construction throw a
 *    misleading negative-width `InvalidArgumentException`. Documented, not
 *    guarded: terminal frames never approach that scale.
 *
 * Mirrors ratatui's layout constraint solving (divergence: Length can inflate
 * under the Max reclaim ladder — ratatui's never does; README Tiling
 * guarantee, ladder step 6).
 *
 * The contract is `solve()` alone. Construction lives on the concrete classes
 * (`GreedySolver::new()`/`::greedy()`/`::compat()`, the deprecated
 * `CassowarySolver::new()`), so an implementer never has to know — or build —
 * any other solver. PHP interface methods are instance methods: the static
 * factories above are NOT part of this contract, and calling one through the
 * interface name (`LayoutSolver::greedy()`) is a fatal error, not a polymorphic
 * dispatch — always construct a concrete class.
 */
interface LayoutSolver
{
    /**
     * Solve constraints against a region in the given direction.
     *
     * @param Region $region      The available region to split.
     * @param Direction $dir    Horizontal or vertical split.
     * @param list<Constraint> $constraints  Ordered list of constraints.
     * @return list<Region>      Sub-regions in order.
     */
    public function solve(Region $region, Direction $dir, array $constraints): array;
}
