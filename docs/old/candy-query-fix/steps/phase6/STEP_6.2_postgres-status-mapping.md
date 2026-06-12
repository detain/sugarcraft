# STEP 6.2 — Postgres status-var mapping + shared_buffers scaling + catalog statics

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §C (PG status dropped, shared_buffers), §D.

## Why
In `App::createAdminFetchPromise` the PG `$statusQuery` selects `pg_stat_database` columns, but
the `.then()` mapper only keeps rows shaped `Variable_name`/`Value` (MySQL) or `name`/`setting`
(pg_settings) — the PG status rows match neither, so `statusVars` is always `[]` for Postgres and
the dashboard/status page show "No data". `PostgresWidgetCatalog` treats `shared_buffers` (8KB
blocks / unit-suffixed) as raw bytes. Catalog methods are `static` but called on an instance.

## Goal
- Postgres status variables populate correctly (dashboard/status render real data).
- `shared_buffers` scaled to bytes.
- Static/instance consistency across `WidgetCatalog`/`PostgresWidgetCatalog`/`WidgetRegistry`.

## Files
- `src/App.php` (`createAdminFetchPromise` PG branch + `.then()` mapper).
- `src/Admin/Dashboard/{PostgresWidgetCatalog,WidgetCatalog,WidgetRegistry}.php`.
- `src/Admin/PostgresServerContext.php` if it shapes the snapshot.
- Tests under `tests/Admin/Dashboard/`.

## Do
1. Add a Postgres branch in the status mapper that pivots `pg_stat_database` rows into name→value
   pairs the calc engine can read (decide on a stable keying, e.g. summed/aggregated across DBs
   or keyed by `datname`; document in a WHY comment + `NOTE:`).
2. Scale `shared_buffers` to bytes (multiply blocks by `block_size`, or read via
   `current_setting` with unit conversion); fix the `'%.0f B'` mislabel.
3. Make catalog method invocation consistent (either drop `static` or call statically) across
   both catalogs and the registry.

## Acceptance criteria
- [ ] PG `statusVars` is non-empty for a fake PG snapshot; PG dashboard renders real values.
- [ ] `shared_buffers` shows a sane byte figure.
- [ ] No static-called-as-instance (or instance-called-as-static) mismatch.
- [ ] Full suite green.

## Out of scope / defer
- MySQL dashboard cell accuracy → 6.3.
