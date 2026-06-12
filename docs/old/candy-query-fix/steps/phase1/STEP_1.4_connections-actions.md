# STEP 1.4 — Connections real-server actions (KILL, instrumentation, MDL, ids)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (KILL), §D (Connections). **Phase 1 closeout** — the between-block after
> this step is scoped to all of Phase 1.

## Why
The connection actions are wrong against a real MySQL server:
- `KILL ? / KILL QUERY ?` use prepared-statement **placeholders** — MySQL's `KILL` does not
  accept them; prepare/execute errors (`ConnectionActions.php:125-135`). Kill is non-functional.
- Instrumentation toggle does `UPDATE setup_actors … WHERE HOST='%' AND USER='%'`
  (`:76-93`) instead of `UPDATE performance_schema.threads SET INSTRUMENTED=? WHERE THREAD_ID=?`
  — wrong table, affects 0 rows, reports success.
- MDL tab filters `metadata_locks.THREAD_ID` (`ConnectionDetailTabs.php:193-207`) — should be
  `OWNER_THREAD_ID`.
- Systematic PROCESSLIST_ID vs THREAD_ID confusion: PS tables key on `THREAD_ID`, `KILL` takes
  the connection/processlist id; the code passes one id type to both.
- `EXPLAIN {$query}` (`ConnectionDetailTabs.php:180`) interpolates another session's raw
  `PROCESSLIST_INFO` into a new statement.

## Goal
Connection actions behave correctly and safely against MySQL.

## Files
- `src/Admin/Connections/ConnectionActions.php` (KILL, instrumentation).
- `src/Admin/Connections/ConnectionDetailTabs.php` (MDL column, EXPLAIN guard).
- `src/Admin/Connections/ProcesslistResult.php` (carry BOTH ids; `isBackground` via `threads.TYPE`).
- `src/Admin/Connections/ProcesslistProvider.php` (ensure THREAD_ID is selected in the PS path).
- Tests under `tests/Admin/Connections/` (assert generated SQL via fakes).

## Do
1. `KILL`: build `'KILL CONNECTION ' . (int) $connectionId` and `'KILL QUERY ' . (int)
   $connectionId` and run via `exec()` (no placeholders). Int-cast is injection-safe. Keep the
   background-thread refusal. Use the **connection/processlist id**, not THREAD_ID.
2. Instrumentation toggle: `UPDATE performance_schema.threads SET INSTRUMENTED = ? WHERE
   THREAD_ID = ? LIMIT 1` (prepared; `'YES'`/`'NO'`), keyed by the selected row's **THREAD_ID**.
3. MDL: filter `metadata_locks.OWNER_THREAD_ID = ?` (prepared), select OBJECT_TYPE / LOCK_STATUS
   (GRANTED/PENDING) etc. by THREAD_ID.
4. `ProcesslistResult`: carry both `processlistId` and `threadId`; use `threads.TYPE` for
   background detection (not the `'NULL'` string heuristic); fix `infoTruncated` to mb-safe and
   off-by-one-correct.
5. EXPLAIN: guard to a single `SELECT…` statement before running, or drop the feature with a
   `NOTE:`. Never execute multi-statement server-originated text.

## Acceptance criteria
- [ ] Generated KILL SQL is `KILL CONNECTION <int>` / `KILL QUERY <int>` (no `?`); background
      threads refused (asserted via fake).
- [ ] Instrumentation UPDATE targets `performance_schema.threads` by THREAD_ID, parameterized.
- [ ] MDL query uses `OWNER_THREAD_ID`.
- [ ] Both ids available on `ProcesslistResult`; correct id used per query.
- [ ] EXPLAIN refuses non-SELECT / multi-statement input.
- [ ] Full suite green.

## Out of scope / defer
- Postgres connections adapter wiring → `DEFERRED:`. Live-server verification → `DEFERRED:` for
  Step 8.1 (assert SQL strings via fakes here).
