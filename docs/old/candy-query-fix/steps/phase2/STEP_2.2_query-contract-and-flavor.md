# STEP 2.2 — query() null-contract, interface hygiene, Flavor seeding, EXPLAIN depth

> Read `plans/candy-query-fix/COMMON.md` first. **Agent:** `oac:coder-agent`.
> Audit refs: PART 1 §E.

## Why
- `DatabaseInterface::query()` documents `array|null` (null = "retry after handled connection
  error"), but `Sqlite`/`Postgres` are typed `:array` (can't return null) and `MysqlDatabase`
  returns `[]` when `$pdo===null` — callers treat a dead connection as "0 rows" (LSP break).
- `DatabaseInterface::password(): string` exposes plaintext password (contradicts "never echo
  password"); `prepare(): mixed` leaks a raw `PDOStatement`, meaningless for async drivers.
- `Flavor::detectFromVersionString()` defaults to `Sqlite`, so an unparseable MySQL/PG version
  routes to SQLite providers (wrong EXPLAIN/schema SQL). Detection ignores `driverName()`.
- MySQL/PG EXPLAIN providers discard tree depth → `ExplainView` renders flat (only understands
  SQLite glyphs).

## Goal
- Uniform `query(): array|null` contract honoured by all drivers.
- Interface free of password/`prepare` leaks (or made safe/private).
- Flavor resolved from the real driver first; version string only distinguishes
  MySQL/MariaDB/Percona.
- EXPLAIN tree depth carried through for MySQL/PG.

## Files
- `src/Db/DatabaseInterface.php`, `SqliteDatabase.php`, `MysqlDatabase.php`, `PostgresDatabase.php`.
- `src/Db/Flavor.php`; callers `src/ExplainView.php`, `src/SchemaBrowser.php`.
- `src/Explain/{Mysql,Postgres}ExplainProvider.php`, `src/ExplainView.php` (depth/tag).
- Tests under `tests/Db/`, `tests/Explain/`.

## Do
1. Change all `query()` signatures to `array|null` and return `null` consistently on a handled
   connection loss (not `[]`). Audit callers for the retry semantics.
2. Remove `password()` from the interface (keep private to the impl if reconnect needs it).
   Remove `prepare()` from the interface or replace with a driver-neutral statement type; update
   callers (e.g. ConnectionActions uses prepared statements — keep those working via a sanctioned
   path).
3. `Flavor`: seed from `db->driverName()` (sqlite/mysql/pgsql) first; only use the version
   string to split MySQL/MariaDB/Percona. No silent Sqlite fallback for mysql/pgsql drivers.
4. EXPLAIN: have the JSON providers emit explicit per-node depth (PostgresExplainProvider already
   recurses with `$depth` — stop discarding it) and carry it onto the explain rows so the tree
   renders; map node types to sensible tags.

## Acceptance criteria
- [ ] All drivers' `query()` return `null` on handled connection loss; type is `array|null`.
- [ ] No public `password()`; `prepare()` removed/secured; callers compile and pass.
- [ ] A mysql/pgsql driver with an odd version string resolves to the correct Flavor (not Sqlite).
- [ ] MySQL/PG EXPLAIN output renders with real indentation/depth (assert via fake rows).
- [ ] Full suite green.

## Out of scope / defer
- Exporters → STEP 2.3. Async reconnect parity for Postgres → `NOTE:`/`DEFERRED:` (Phase 7).
