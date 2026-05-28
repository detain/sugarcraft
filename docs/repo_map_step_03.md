# Step 03 — Create candy-layout

**Branch:** `ai/candy-layout-new`
**Depends on:** step-00 ✅
**Blocks:** step-10 (candy-sprinkles uses candy-layout), step-14/15 (sugar-bits/candy-forms layout)

## Goal

Create the `candy-layout` foundation package — a constraint-based layout engine. Ships two solvers behind a `LayoutSolver` interface: `CassowarySolver` (the new investment) and `GreedySolver` (re-implementation of the existing `candy-sprinkles/src/Layout/Solver.php` 5-phase distribution, kept as deterministic fallback). Consumers pick which solver via factory.

Reference: `docs/repo_map_update.md` §327.2 (framework), §345.2 (LayoutSolver interface), §369.3 (consolidation of 5+ greedy solvers), §387.3 (reinvention).

## Files expected to be created

- `candy-layout/composer.json`
- `candy-layout/phpunit.xml`
- `candy-layout/README.md`
- `candy-layout/CALIBER_LEARNINGS.md`
- `candy-layout/src/LayoutSolver.php` — interface.
- `candy-layout/src/Constraint.php` — interface.
- `candy-layout/src/Constraint/Min.php`, `Max.php`, `Fixed.php`, `Fill.php`, `Percentage.php`, `Ratio.php` — value objects.
- `candy-layout/src/Solver/CassowarySolver.php` — Cassowary simplex implementation.
- `candy-layout/src/Solver/GreedySolver.php` — port of candy-sprinkles' existing 5-phase distribution.
- `candy-layout/src/Direction.php` — enum (Horizontal, Vertical).
- `candy-layout/src/Region.php` — alias of candy-buffer's? Or own? **Decision**: own type to keep candy-layout leaf.
- `candy-layout/tests/CassowarySolverTest.php`, `GreedySolverTest.php`, `ConstraintTest.php`.

## Files expected to be modified

- Root `composer.json`, MATCHUPS, PROJECT_NAMES, README, docs/index.html, docs/lib/candy-layout.html, codecov.yml.

## Acceptance criteria

- [ ] `LayoutSolver` interface: `solve(Region $region, Direction $dir, list<Constraint> $constraints): list<Region>`.
- [ ] `CassowarySolver::new(): self` — default factory.
- [ ] `GreedySolver::new(): self` — bit-for-bit equivalent output to existing `candy-sprinkles/src/Layout/Solver.php` for the 5-phase test cases (Min, Fixed, Fill, Max, slack proportional). **Golden test**: same inputs → same outputs.
- [ ] All 6 `Constraint` types (Min, Max, Fixed, Fill, Percentage, Ratio) implemented.
- [ ] CassowarySolver passes edit-variable + stay-weight standard test cases from Badros & Borning 2001.
- [ ] Solver swap-in is non-breaking — `LayoutSolver` is the only public contract.
- [ ] No deps on other `sugarcraft/*` packages — candy-layout is leaf.
- [ ] ≥95 % coverage.
- [ ] `git status` clean on master at end.

## Coder brief

1. **Spawn a Researcher FIRST** with the question:
   > "What's the minimal viable Cassowary simplex implementation for terminal-grid 1D layout (single-direction constraint solving, integer outputs)? Reference kiwisolver (Python), rhea (JS), Badros & Borning 2001 paper. Identify edit variables, stay constraints, simplex pivot rule. Estimate PHP LoC. Are there existing PHP ports?"
   Block on the Researcher's findings. If they recommend a non-trivial dependency (e.g., FFI to kiwi C++), append `Cassowary dep decision: <details>` to `docs/repo_map_updates.md` and proceed with the simplest viable hand-roll.
2. **Invoke `scaffold-library`** with slug `candy-layout`, namespace `SugarCraft\Layout\`, role "Constraint-based layout solver (Cassowary + greedy fallback)".
3. **Copy candy-sprinkles' Solver as GreedySolver**:
   ```bash
   cp candy-sprinkles/src/Layout/Solver.php candy-layout/src/Solver/GreedySolver.php
   ```
   Rewrite namespace + class name. **Do NOT modify candy-sprinkles** this step (that's step-10).
4. **Port constraint types** from `candy-sprinkles/src/Layout/Constraint.php` into per-type files in `candy-layout/src/Constraint/`. Use `sugarcraft-model-pattern` for each value object.
5. **Implement CassowarySolver**:
   - Variables = each constraint's output size.
   - Required constraints from the constraint type (e.g., Min ≥ X, Max ≤ Y, Percentage = total × p).
   - Edit variables = total available size.
   - Run simplex; round outputs to integers; distribute rounding slack to Fill constraints first, then Percentage, then Min/Max.
6. **Golden parity test**: assert `CassowarySolver` and `GreedySolver` produce identical outputs for the 5-phase test cases that exist in candy-sprinkles' Solver tests (port them as input fixtures). Where Cassowary's optimal solution differs from greedy's heuristic, document the case in `CALIBER_LEARNINGS.md`.
7. Run phpunit + check-path-repos.

## Tester brief

- **GreedySolver**: every test case from `candy-sprinkles/tests/Layout/SolverTest.php` (or wherever existing Solver tests live) — port them as fixtures.
- **CassowarySolver**: same fixtures + edit-variable smoke tests + stay-weight tie-break tests + the Badros & Borning 2001 paper's worked examples (height-allocation problem).
- **Direction**: Vertical + Horizontal both exercised.
- **Constraint types**: each type covered in isolation (one constraint × total size 100) AND in combinations (3 constraints competing for 100).
- **Rounding**: total available size that doesn't divide evenly — assert outputs sum exactly to total and slack distribution is deterministic.
- **Pathological cases**: 0 constraints (return empty), 1 constraint (returns the whole region), Min greater than total (clamp + warning).

## Scribe brief

- README: Composer install, Quickstart picking GreedySolver vs CassowarySolver, code example splitting a 100-wide region into `[Min(10), Fill, Fixed(20)]`.
- CALIBER_LEARNINGS: Cassowary notes (which simplex pivot rule, what slack distribution does); any GreedySolver-vs-CassowarySolver divergence captured.
- MATCHUPS row citing `ratatui/ratatui` Cassowary impl as upstream reference (candy-layout is new vs ratatui parity).
- PROJECT_NAMES Candy table.
- Docs/index.html + docs/lib/candy-layout.html.

## Ship brief

- **PR title**: `candy-layout: Cassowary constraint solver + greedy fallback`
- **PR body**:
  ```
  ## Summary
  - New foundation lib candy-layout with LayoutSolver interface.
  - CassowarySolver: simplex-based constraint solver (new, parity with ratatui).
  - GreedySolver: ported from candy-sprinkles' existing 5-phase Solver; bit-identical output via golden fixture tests.
  - candy-sprinkles' Solver is unchanged this PR; step-10 wires candy-sprinkles to consume candy-layout.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-layout (≥95% coverage)
  - [x] Golden parity tests: GreedySolver === existing candy-sprinkles Solver outputs
  - [x] Cassowary correctness vs Badros & Borning 2001 worked examples
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_03.md, docs/repo_map_update.md §327.2, §345.2, §369.3
  ```
- Commit subject: `candy-layout: add Cassowary + greedy constraint solvers`.
