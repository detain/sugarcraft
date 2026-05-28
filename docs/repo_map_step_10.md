# Step 10 — candy-sprinkles uses candy-layout

**Branch:** `ai/candy-sprinkles-layout`
**Depends on:** step-03 (candy-layout exists)
**Blocks:** Phase 3 layout-consuming migrations (step-14, step-15)

## Goal

Wire `candy-sprinkles` to consume `candy-layout`'s `LayoutSolver` interface. Default solver: `CassowarySolver`. Fallback: `GreedySolver` (which IS the existing `candy-sprinkles/src/Layout/Solver.php` ported verbatim in step-03 — so GreedySolver output is bit-equivalent to today). Public `candy-sprinkles\Layout\Solver` API is preserved as an internal facade that delegates to `LayoutSolver`.

Reference: `docs/repo_map_update.md` §369.3 (consolidation), §387.3 (string-based layout reinvention).

## Files expected to be created

- `candy-sprinkles/src/Layout/SolverFactory.php` — chooses Cassowary vs Greedy based on `SUGARCRAFT_LAYOUT_SOLVER` env or default.

## Files expected to be modified

- `candy-sprinkles/composer.json` — add `sugarcraft/candy-layout: @dev` + path-repo via `path-repo-closure`.
- `candy-sprinkles/src/Layout/Solver.php` — keep class, refactor to delegate to a `LayoutSolver`. All existing public methods unchanged in signature + output.
- `candy-sprinkles/src/Layout/Constraint.php` — keep as facade aliasing `SugarCraft\Layout\Constraint\*` types.
- `candy-sprinkles/src/Layout/Layout.php` — internal refactor as needed; public API unchanged.
- Existing `candy-sprinkles/tests/Layout/*Test.php` must still pass.

## Acceptance criteria

- [ ] `candy-sprinkles\Layout\Solver` continues to satisfy every existing test in `candy-sprinkles/tests/`.
- [ ] `SolverFactory::default()` returns a CassowarySolver by default.
- [ ] Env var `SUGARCRAFT_LAYOUT_SOLVER=greedy` switches to GreedySolver (escape hatch for any divergence).
- [ ] Bit-equivalence test: run the candy-sprinkles Solver test corpus with both solvers; assert outputs identical (or document divergence in CALIBER_LEARNINGS).
- [ ] No downstream consumer of `candy-sprinkles\Layout` needs changes.
- [ ] ≥95 % coverage maintained on candy-sprinkles Layout.
- [ ] `git status` clean on master.

## Coder brief

1. **Use `path-repo-closure`** to add `sugarcraft/candy-layout: @dev` to candy-sprinkles' composer.json (and to any lib that transitively requires candy-sprinkles).
2. **Refactor `candy-sprinkles/src/Layout/Solver.php`**: convert from monolithic impl to a thin facade. Its `solve()` method delegates to a `LayoutSolver` instance obtained via `SolverFactory::default()`. Preserve every public method signature.
3. **Refactor `candy-sprinkles/src/Layout/Constraint.php`**: if it was a single class with type discriminator, refactor so each constraint case wraps the corresponding `SugarCraft\Layout\Constraint\*` value object. Public API surface unchanged.
4. **SolverFactory** reads `getenv('SUGARCRAFT_LAYOUT_SOLVER')`: `greedy` → `GreedySolver`, anything else (or empty) → `CassowarySolver`.
5. **Verify** by running `cd candy-sprinkles && composer update --quiet && vendor/bin/phpunit`. Then with `SUGARCRAFT_LAYOUT_SOLVER=greedy vendor/bin/phpunit`. Both should pass.
6. **Run all libs that depend on candy-sprinkles** (use `php scripts/affected-libs.php`) to confirm no transitive breakage.
7. Run check-path-repos.

## Tester brief

- All existing `candy-sprinkles/tests/Layout/*Test.php` continue passing — no change to test files unless an existing test asserted internal implementation details (which it shouldn't).
- New test: solve same input via SolverFactory with each env var setting, assert identical output.
- New test: SolverFactory env-var parsing (empty, "greedy", "cassowary", garbage).

## Scribe brief

- `candy-sprinkles/README.md`: a paragraph under `## Shared foundations` noting layout now delegates to candy-layout. Env-var escape hatch documented.
- `candy-sprinkles/CALIBER_LEARNINGS.md`: any Cassowary-vs-Greedy divergence noted with worked example.
- MATCHUPS: update candy-sprinkles row note "constraint solver delegated to candy-layout".

## Ship brief

- **PR title**: `candy-sprinkles: delegate layout solving to candy-layout`
- **PR body**:
  ```
  ## Summary
  - candy-sprinkles\Layout\Solver now delegates to candy-layout's LayoutSolver interface.
  - Default solver: CassowarySolver. Fallback via SUGARCRAFT_LAYOUT_SOLVER=greedy env var.
  - GreedySolver is bit-equivalent to the previous candy-sprinkles impl; CassowarySolver may improve edge cases.
  - Fully back-compatible: every existing candy-sprinkles test passes unchanged.

  ## Test plan
  - [x] vendor/bin/phpunit in candy-sprinkles (existing suite green)
  - [x] vendor/bin/phpunit with SUGARCRAFT_LAYOUT_SOLVER=greedy (env-switch verified)
  - [x] vendor/bin/phpunit in every candy-sprinkles dependent lib (affected-libs.php)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_10.md, docs/repo_map_update.md §369.3, §387.3
  ```
- Commit subject: `candy-sprinkles: delegate Solver to candy-layout`.
