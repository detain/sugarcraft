# STEP 5.3 — Easy Setup detection + default sets (per spec / Appendix C)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D; `docs/mysql_workbench_dash.md` §5.3, Appendix C. **Phase 5 closeout.**

## Why
`EasySetupDetector` uses an instrument enabled-percentage `=== 100` (ignores `TIMED`, ignores
consumers entirely) and a hand-rolled default check that doesn't match Appendix C's
`DEFAULT_INSTRUMENTS_5x`/`DEFAULT_CONSUMERS_5x`. So "fully" misclassifies servers with TIMED off,
and "default" never matches. `EasySetup`'s default instrument/consumer sets are also wrong vs
Appendix C. And `EasySetupDetector` is never wired (page always built with `null` → falls back to
the inferior in-page `detectSetupState()`).

## Goal
- Detection reproduces the spec's verbatim COUNT/SUM logic (fully / disabled / default / custom),
  including TIMED + consumers, excluding `memory/%`.
- Default instrument/consumer sets match Appendix C (5.6/5.7, version-selected).
- `EasySetupDetector` is wired into `PerfSchemaPage`.

## Files
- `src/Admin/PerfSchema/{EasySetup,EasySetupDetector,PerfSchemaPage}.php`.
- `src/App.php` (`adminPage()` — pass the detector instead of `null`).
- Tests under `tests/Admin/PerfSchema/`.

## Do
1. `EasySetupDetector`:
   - **fully** = `COUNT(setup_consumers WHERE enabled='NO')==0` AND `COUNT(setup_instruments
     WHERE NAME NOT LIKE 'memory/%' AND (enabled='NO' OR timed='NO'))==0`.
   - **disabled** = the `'YES'` mirror.
   - **default** = `SUM(IF(...))` comparison of current consumers/instruments against the
     Appendix-C default profile == match.
   - **custom** otherwise.
   Use the `ServerContext` version to pick the 5.6 vs 5.7 default set.
2. `EasySetup`: replace `DEFAULT_INSTRUMENTS`/`DEFAULT_CONSUMERS` with Appendix C's sets
   (instruments: `wait/io/file/%`, `wait/io/table/%`, `wait/lock/table/sql/handler`,
   `statement/%`, `idle`; consumers: `events_statements_current`, `events_transactions_current`,
   `global_instrumentation`, `thread_instrumentation`, `statements_digest`; + 5.6 variants).
   Fix the toggle statements accordingly.
3. Wire `EasySetupDetector` from `App::adminPage()` into `PerfSchemaPage`; remove/retire the
   inferior in-page `detectSetupState()` (or have it delegate to the detector).

## Acceptance criteria
- [ ] Detection returns fully/disabled/default/custom matching the spec queries (assert via fakes
      seeded to each state, incl. a TIMED-off server NOT reported as "fully").
- [ ] Default sets equal Appendix C; reset-to-default toggles the correct rows.
- [ ] `PerfSchemaPage` uses the wired detector (not the `null` fallback).
- [ ] Full suite green.

## Out of scope / defer
- Live-server verification → `DEFERRED:` for Step 8.1.
