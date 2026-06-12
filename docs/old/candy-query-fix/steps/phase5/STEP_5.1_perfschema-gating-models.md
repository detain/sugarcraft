# STEP 5.1 — PerfSchema version gating + SetupTimers + Threads INSTRUMENTED

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D (Performance Schema Setup); `docs/mysql_workbench_dash.md` §5.3, §6.5.

## Why
No server-version gating: `setup_actors`/`setup_objects` are queried unconditionally (relying on
try/catch swallowing) though they're ≥5.6; `setup_objects.ENABLED` is ≥5.6.3; `setup_timers` is
<8.0-only and has **no model at all**. The Threads tab never selects/commits `INSTRUMENTED`, so
the per-thread instrumentation grid is missing.

## Goal
- Load queries + tab visibility gated by detected server version.
- A `SetupTimers` model (loads `setup_timers`/`performance_timers`, commits `UPDATE setup_timers
  SET timer_name=? WHERE name=?`) gated to <8.0.
- Threads tab selects and commits `INSTRUMENTED`.

## Files
- `src/Admin/PerfSchema/{PerfSchemaPage,SetupActors,SetupObjects,SetupThreads}.php`.
- New `src/Admin/PerfSchema/SetupTimers.php` (model — note: only `performance_timers` enumeration
  exists today; add the `setup_timers` load/commit, <8.0-gated).
- `src/Admin/ServerContext*.php` (expose the version if not already convenient).
- Tests under `tests/Admin/PerfSchema/`.

## Do
1. Detect server version once (from `ServerContext`) and gate: skip `setup_actors`/`setup_objects`
   loads on <5.6; omit `setup_objects.ENABLED` on <5.6.3; expose/hide the corresponding tabs.
2. Add `SetupTimers`: load `SELECT name, timer_name FROM performance_schema.setup_timers` (<8.0)
   and the `performance_timers` availability list; commit `UPDATE setup_timers SET timer_name=?
   WHERE name=?` (prepared). On ≥8.0 the timer is fixed — show read-only/hidden.
3. Threads tab: select `INSTRUMENTED` in the threads query; model it as a togglable column;
   commit `UPDATE performance_schema.threads SET INSTRUMENTED='YES'|'NO' WHERE THREAD_ID IN (…)`
   (the commit-SQL specifics are tightened in STEP 5.2 — here just carry the column + intent).

## Acceptance criteria
- [ ] Version gating verified against fake contexts at 5.5 / 5.6 / 5.6.3 / 5.7 / 8.0 (correct
      tables loaded, correct tabs shown).
- [ ] `SetupTimers` loads + emits a valid <8.0 UPDATE; hidden/read-only on ≥8.0.
- [ ] Threads model carries `INSTRUMENTED` and can mark threads for an IN() update.
- [ ] Full suite green.

## Out of scope / defer
- Commit-SQL parameterization, instrument RLIKE, tri-state cascade, tree render → STEP 5.2.
- Easy Setup detection/defaults → STEP 5.3.
