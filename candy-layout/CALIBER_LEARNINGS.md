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

## 2026-09-22 — Dock layout primitive (src/Dock/*)

- **Leaf-package immutable-fluent without candy-core.** composer.json requires
  only `php ^8.3`, so the `Mutable` trait is off the table: each Dock class
  hand-rolls `private function mutate(array $changes): self` as
  `new self(...array_merge(get_object_vars($this), $changes))` over a private
  promoted-readonly ctor (DockSlot is the exception: public readonly props +
  static guard, mirroring Region.php exposure). Pattern borrowed from
  candy-sprinkles Style.php, pattern-only, never imported.
- **GreedySolver NOT reused.** Dock columns/rows are pure rational arithmetic
  (floor + residual-to-last); routing through constraint objects would add
  indirection with no expressive gain. Region is reused as the sole geometry VO.
- **Rational shares [num,denom]** echo sugar-crush Tui/SplitLayout in prose,
  not dependency. The 1/2 pair ceiling is a *mutator policy* of
  withColumnShare() (clamps, never throws) — deliberately NOT a state
  invariant, because new()-thirds (1/3+1/3 = 2/3 > 1/2) and verbatim
  fromArray() restore must both persist so toArray/fromArray round-trip
  identity holds. resolve() absorbs over-grown manifests via the ladder.
- **Degradation ladder (deterministic):** raise short side to sideMinCols only
  while center keeps centerMinCols (Left first) → else drop side with fewer
  slots (ties drop Left) → drop remaining side → center-only whole frame;
  width/height ≤ 0 resolves to empty geometry, never throws.
- **PHP enum keys on arrays fatal** ("Cannot access offset of type Side on
  array") — maps keyed by Side iterate `[[Side::Left,$l],[Side::Right,$r]]`
  pairs or key by a string `self::key($side)` instead.
- Stack split: rows = height − (count−1) gap rows; non-last slots floor at
  their float weight share, LAST slot takes the residual — deterministic, and
  absurd frames yield zero-height Regions (allowed by Region).
Source: ai/candy-layout-dock
