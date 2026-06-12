# STEP 5.2 — PerfSchema commit SQL (parameterized, correct RLIKE) + tri-state tree

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (RLIKE never matches), §D; `docs/mysql_workbench_dash.md` §5.3, §6.5.

## Why
- `SetupInstruments::commitStatements()` wraps the NAME in literal backticks inside the RLIKE
  string (`'`name`'`) → the regex matches no row (silent no-op). `CommitPlanner` computes a
  pattern then ignores it, emitting one UPDATE per instrument with a **bare, unanchored** RLIKE
  literal (substring over-match).
- Consumers/objects/actors commits are **not parameterized** (`CommitPlanner::quote()` just
  doubles quotes) — value-injection surface for `setup_actors.USER/HOST`,
  `setup_objects.OBJECT_SCHEMA/NAME`.
- Tri-state propagation is one-directional (bottom-up only); toggling a group doesn't cascade to
  children. The Instruments tree renders flat (the depth is computed then discarded).

## Goal
- Minimal-diff commits matching upstream: instruments via anchored/prefix RLIKE buckets,
  consumers/threads via `IN(...)`, actors/objects via keyed INSERT/UPDATE/DELETE — all
  parameterized (RLIKE pattern regex-escaped + anchored).
- Two-way tri-state: group toggle cascades down, ancestors recompute (−1 disabled / 0 mixed /
  1 enabled, applied consistently).
- A collapsible tri-state tree (`[x]/[ ]/[~]`) using the real depth.

## Files
- `src/Admin/PerfSchema/{CommitPlanner,SetupInstruments,SetupConsumers,SetupActors,SetupObjects,
  SetupThreads,InstrumentTree,PerfSchemaPage}.php`.
- `src/Admin/PerfSchema/ChangeTracker.php` (drive dirty state through it, or remove if unused).
- Tests under `tests/Admin/PerfSchema/` (assert generated SQL strings + bound params).

## Do
1. Instruments commit: collapse changes to the highest fully-shared tree level; per (column,
   value) bucket emit one `UPDATE setup_instruments SET ENABLED=? WHERE NAME RLIKE ?` (and a
   separate TIMED bucket). Build the regex as anchored `^name$` for leaves / prefix for groups,
   **regex-escaping** metacharacters; bind the regex as a parameter — never interpolate. Remove
   the backtick-wrapping bug.
2. Consumers/threads: `… WHERE NAME IN (?, ?, …)` / `THREAD_ID IN (?, …)` with bound params.
3. Actors/objects: keyed `INSERT`/`UPDATE`/`DELETE` with bound USER/HOST/OBJECT_* values.
   Replace `CommitPlanner::quote()` interpolation with prepared statements throughout.
4. `InstrumentTree`: add top-down `setChildrenState()` cascade + ancestor recompute; settle on
   one −1/0/1 convention (fix the docblock/`aggregateStates` mixed-vs-disabled confusion).
5. `PerfSchemaPage`: render the Instruments list indented by the (currently-discarded) depth with
   group tri-state rows; group toggles operate on subtrees.

## Acceptance criteria
- [ ] Instrument commit emits anchored, regex-escaped, parameterized RLIKE that actually matches
      the intended rows (assert SQL + params via fakes); no backticks in the pattern.
- [ ] All setup_* commits are parameterized (no value string-interpolation).
- [ ] Toggling a group cascades to children and updates ancestor tri-state.
- [ ] Instruments render as an indented tri-state tree.
- [ ] Full suite green.

## Out of scope / defer
- Easy Setup detection/defaults → STEP 5.3.
