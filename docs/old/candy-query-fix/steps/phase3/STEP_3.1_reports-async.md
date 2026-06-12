# STEP 3.1 — Reports run async (no blocking queries on the render path)

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §D (Reports sync queries).

## Why
`ReportsPage::loadCurrentReport()` calls `ReportRunner::run()` → `db->query()` synchronously,
and it runs from `validate()` and every `withRefresh`/`withSelect*` — a blocking call on the
event loop. `AvailabilityChecker::discoverViews()` adds another sync `SHOW FULL TABLES`. The
rest of admin is non-blocking via `AdminQueryCache`. Reports must match.

## Goal
Report queries (view discovery + `SELECT * FROM sys.<view>`) flow through the async cache /
`Cmd::promise`, exactly like the processlist/replica paths. `view()`/`validate()` never block.

## Files
- `src/Admin/Reports/{ReportsPage,ReportRunner,AvailabilityChecker}.php`.
- `src/App.php` (admin fetch path — add report queries to the cached fetch, mirroring how
  process-list/status queries are issued in `createAdminFetchPromise`).
- `src/Admin/{AdminQueryCache,CachedConnection}.php` (read to follow the pattern).
- Tests under `tests/Admin/Reports/`.

## Do
1. Route report execution through the async cache: on report/refresh selection, issue the query
   into the cache (or a `Cmd::promise`) and render from the cached result; show a loading state
   on a miss and fill on the next tick.
2. Same for `AvailabilityChecker` view discovery — cache the `SHOW FULL TABLES FROM sys` result.
3. `AvailabilityChecker` (and any report error handling) catch `\Throwable`, not just
   `\PDOException` (React/cached connections can surface other types).
4. No `db->query()` left in `validate()`/`view()`/`update()`.

## Acceptance criteria
- [x] No synchronous DB query in the Reports render/validate path (grep `->query(` in
      ReportsPage/ReportRunner/AvailabilityChecker shows only async/cached usage).
- [x] A cache-miss shows loading; a subsequent tick renders rows (assert with fake cache).
- [x] Error paths catch `\Throwable`.
- [ ] Full suite green. (NOTE: 1 expected failure — testViewShowsErrorWhenSysSchemaNotAvailable tests old sync behavior; needs TESTS_CI update)

## Out of scope / defer
- Tree navigation + catalog expansion → STEP 3.2.
