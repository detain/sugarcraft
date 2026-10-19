# Step 10 — sugar-style uses sugar-constraints

**Branch:** `ai/sugar-style-layout`
**Depends on:** step-03 (sugar-constraints exists)
**Blocks:** Phase 3 layout-consuming migrations (step-14, step-15)

## Goal

Wire `sugar-style` to consume `sugar-constraints`'s `LayoutSolver` interface. Default solver: `CassowarySolver`. Fallback: `GreedySolver` (which IS the existing `sugar-style/src/Layout/Solver.php` ported verbatim in step-03 — so GreedySolver output is bit-equivalent to today). Public `sugar-style\Layout\Solver` API is preserved as an internal facade that delegates to `LayoutSolver`.

Reference: `docs/repo_map_update.md` §369.3 (consolidation), §387.3 (string-based layout reinvention).

## Files expected to be created

- `sugar-style/src/Layout/SolverFactory.php` — chooses Cassowary vs Greedy based on `SUGARCRAFT_LAYOUT_SOLVER` env or default.

## Files expected to be modified

- `sugar-style/composer.json` — add `sugarcraft/sugar-constraints: @dev` + path-repo via `path-repo-closure`.
- `sugar-style/src/Layout/Solver.php` — keep class, refactor to delegate to a `LayoutSolver`. All existing public methods unchanged in signature + output.
- `sugar-style/src/Layout/Constraint.php` — keep as facade aliasing `SugarCraft\Layout\Constraint\*` types. <!-- @rename-keep -->
- `sugar-style/src/Layout/Layout.php` — internal refactor as needed; public API unchanged.
- Existing `sugar-style/tests/Layout/*Test.php` must still pass.

## Acceptance criteria

- [ ] `sugar-style\Layout\Solver` continues to satisfy every existing test in `sugar-style/tests/`.
- [ ] `SolverFactory::default()` returns a CassowarySolver by default.
- [ ] Env var `SUGARCRAFT_LAYOUT_SOLVER=greedy` switches to GreedySolver (escape hatch for any divergence).
- [ ] Bit-equivalence test: run the sugar-style Solver test corpus with both solvers; assert outputs identical (or document divergence in CALIBER_LEARNINGS).
- [ ] No downstream consumer of `sugar-style\Layout` needs changes.
- [ ] ≥95 % coverage maintained on sugar-style Layout.
- [ ] `git status` clean on master.

## Coder brief

1. **Use `path-repo-closure`** to add `sugarcraft/sugar-constraints: @dev` to sugar-style' composer.json (and to any lib that transitively requires sugar-style).
2. **Refactor `sugar-style/src/Layout/Solver.php`**: convert from monolithic impl to a thin facade. Its `solve()` method delegates to a `LayoutSolver` instance obtained via `SolverFactory::default()`. Preserve every public method signature.
3. **Refactor `sugar-style/src/Layout/Constraint.php`**: if it was a single class with type discriminator, refactor so each constraint case wraps the corresponding `SugarCraft\Layout\Constraint\*` value object. Public API surface unchanged. <!-- @rename-keep -->
4. **SolverFactory** reads `getenv('SUGARCRAFT_LAYOUT_SOLVER')`: `greedy` → `GreedySolver`, anything else (or empty) → `CassowarySolver`.
5. **Verify** by running `cd sugar-style && composer update --quiet && vendor/bin/phpunit`. Then with `SUGARCRAFT_LAYOUT_SOLVER=greedy vendor/bin/phpunit`. Both should pass.
6. **Run all libs that depend on sugar-style** (use `php scripts/affected-libs.php`) to confirm no transitive breakage.
7. Run check-path-repos.

## Tester brief

- All existing `sugar-style/tests/Layout/*Test.php` continue passing — no change to test files unless an existing test asserted internal implementation details (which it shouldn't).
- New test: solve same input via SolverFactory with each env var setting, assert identical output.
- New test: SolverFactory env-var parsing (empty, "greedy", "cassowary", garbage).

## Scribe brief

- `sugar-style/README.md`: a paragraph under `## Shared foundations` noting layout now delegates to sugar-constraints. Env-var escape hatch documented.
- `sugar-style/CALIBER_LEARNINGS.md`: any Cassowary-vs-Greedy divergence noted with worked example.
- MATCHUPS: update sugar-style row note "constraint solver delegated to sugar-constraints".

## Ship brief

- **PR title**: `sugar-style: delegate layout solving to sugar-constraints`
- **PR body**:
  ```
  ## Summary
  - sugar-style\Layout\Solver now delegates to sugar-constraints's LayoutSolver interface.
  - Default solver: CassowarySolver. Fallback via SUGARCRAFT_LAYOUT_SOLVER=greedy env var.
  - GreedySolver is bit-equivalent to the previous sugar-style impl; CassowarySolver may improve edge cases.
  - Fully back-compatible: every existing sugar-style test passes unchanged.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-style (existing suite green)
  - [x] vendor/bin/phpunit with SUGARCRAFT_LAYOUT_SOLVER=greedy (env-switch verified)
  - [x] vendor/bin/phpunit in every sugar-style dependent lib (affected-libs.php)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_10.md, docs/repo_map_update.md §369.3, §387.3
  ```
- Commit subject: `sugar-style: delegate Solver to sugar-constraints`.
