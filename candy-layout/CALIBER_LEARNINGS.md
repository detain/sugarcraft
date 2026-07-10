# CALIBER_LEARNINGS: candy-layout

## Session Learnings

### 2026-05-28 — Cassowary + Greedy co-existence
Pattern: Extracted Cassowary + Greedy layout solvers into candy-layout in step-03. GreedySolver is golden-parity with candy-sprinkles. Cassowary is simplified prototype — full simplex with Big-M method.
Anti-pattern: None identified yet.
Source: step-03 ai/candy-layout-new

### 2026-07-10 — CassowarySolver hard-deprecated → GreedySolver
Anti-pattern (two shipped bugs in the Big-M simplex prototype):
  1. **Never converged** — `solveCore()` hit its 1000-pivot cap on EVERY call,
     including trivial pure-Length sets, then silently returned partial/garbage
     state (the fail-fast `throw` was commented out to avoid reddening tests).
     Bland's-rule pivot selection did not fix the cycling.
  2. **Ratio-returns-0** — the Ratio path produced 0 instead of the ratio size.
Fix: `CassowarySolver::solve()` now emits `E_USER_DEPRECATED` and delegates
WHOLLY to `GreedySolver` (which correctly supports every constraint type, so
delegation also FIXES the Ratio bug). Did NOT rewrite the simplex — too risky
for a foundation lib. Deleted the dead pivot loop + its helpers (Variable,
Relation, ConstraintDef, EditInfo, Tableau); kept `Expression` (still tested).
Only in-repo caller is candy-sprinkles `SolverFactory` (env-gated), which
constructs but never calls `solve()`, so the deprecation is inert downstream.
Pattern: `expectDeprecation()` was REMOVED in PHPUnit 10 — assert deprecations
via a scoped `set_error_handler(..., E_USER_DEPRECATED)` that captures + swallows,
restored in `finally`. Keeps the suite green under `failOnWarning=true`.
Also: GreedySolver `applyMaxClamp()` divided by zero when every reclaim
recipient was `Fill(0)` (weight-sum 0) — guarded with an equal-shares fallback.
Region now rejects negative x/y/w/h (matches the constraint value-objects).
Source: ai/candy-layout-solver-deprecate
