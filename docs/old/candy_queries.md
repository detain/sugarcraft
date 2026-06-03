# candy-query — Multi-Driver + Workbench-Style Admin: Orchestration Plan

## Context

`candy-query` (`SugarCraft\Query`, `/home/sites/sugarcraft/candy-query`) is a terminal SQLite browser — a PHP/TEA port of `jorgerojas26/lazysql`. It already ships a clean `Db\DatabaseInterface` (7 methods), a `SqliteDatabase` implementation, a legacy `Database` concrete still used by `bin/candy-query`, an immutable TEA `App` model, a 3-pane `Renderer`, and supporting value classes (`SchemaBrowser`, `ResultPager`, `ResultTable`, `CellEditor`, `SnippetStore`, `ExplainView`). Its README already calls out multi-driver (MySQL/Postgres) as a planned follow-up and notes the `Database`→interface promotion is "a one-class job once the second driver lands."

Two bodies of work are requested:
1. **Multi-driver support** — MySQL + PostgreSQL drivers (with MariaDB/Percona handled as MySQL *flavors* via `@@version` detection), promoting the SQLite-only browser to genuinely multi-engine.
2. **A MySQL-Workbench-style admin/observability layer** — Dashboard, Server Status, Status/System Variables, Client Connections, Performance Reports, and Performance Schema Setup — reverse-engineered in exhaustive detail in `docs/mysql_workbench_dash.md`. Admin sections target **MySQL first** behind a clean `AdminProvider` interface so Postgres equivalents (`pg_stat_*`) can land later.

This change matters because it turns a single-file SQLite viewer into a portable, low-privilege DBA cockpit — the flagship Dashboard works on a near-`USAGE` MySQL account using only `SHOW GLOBAL STATUS`/`SHOW VARIABLES`.

### Locked scope decisions
- **Engines:** MySQL + PostgreSQL drivers; MariaDB/Percona as MySQL flavors via version detection.
- **Admin coverage:** all 6 sections for **MySQL first**, behind an `AdminProvider` seam; Postgres admin stubbed for a later phase.
- **Widgets:** **reuse SugarCraft libs** (`sugar-charts`, `sugar-dash`, `sugar-table`, `candy-async`, `candy-metrics`, `candy-layout`, …) rather than building our own. Wire them as **normal Packagist deps with `dev-master` constraints — NOT path-repo entries.**
- **PR granularity:** **one PR per step** (overrides the repo's usual 2–4-item bundling for this effort).

### Reuse map (verified present in monorepo)
- **sugar-charts** (`SugarCraft\Charts`): `Sparkline`, `LineChart`, `TimeSeries`, `Streamline`, `BarChart`, `Canvas/BrailleGrid`, `Aggregation\BucketByTime|MovingAverage|Resample` → live throughput graphs/sparklines.
- **sugar-dash** (`SugarCraft\Dash\Plot\Chart`): `Gauge`, `GaugeCircle`, `Meter`, `Progress`, `MetricsGrid`, `AreaChart` → round/level meters & counters.
- **sugar-table** (`SugarCraft\Table`): `Table`/`Column`/`Row` with sort/filter/paginate/viewport → Reports & Variables grids.
- **candy-async** (`SugarCraft\Async`): `AsyncOps::throttle/debounce/retry`, `CancellationSource`, `Subscriptions` → polling cadence & shutdown.
- **candy-layout** (`SugarCraft\Layout`): `GreedySolver`/`CassowarySolver` + `Constraint`/`Region` → responsive dashboard panes. (`candy-sprinkles` also exposes a `Layout` constraint API already used by `Renderer`.)
- **candy-metrics** (`SugarCraft\Metrics`): `Registry` + backends → optional in-process metric fan-out / history.
- **candy-core** (`SugarCraft\Core\Util\Width`): ANSI-aware width/truncate/pad for cell sizing.
- **Gap to fill in-lib:** byte/duration/K-M-G humanizers do **not** exist in the monorepo → a small `Format` helper (1024-based `scaleValue` + SI byte + picosecond→time) is built inside candy-query (the only genuinely-new utility).

### Key facts about the existing code (anchors)
- `src/Db/DatabaseInterface.php` — `tables(): array`, `rows($t,$limit)`, `query($sql)`, `lastInsertId()`, `quote($v)`, `exec($sql)`, `close()`.
- `src/Db/SqliteDatabase.php` — `final … implements DatabaseInterface`, `::open($path)` factory.
- `src/Database.php` — **legacy** concrete still wired in `bin/candy-query:31`; carries `importCsv/exportCsv/exportSql` (NOT on the interface).
- `src/App.php` — `final class App implements Model`; `::start(DatabaseInterface $db)`, `update(Msg): [Model,?Cmd]`, `view()` → `Renderer::render($this)`; immutable, `AppBuilder` mirrors its 14 fields.
- `src/Pane.php` — backed enum `Tables|Rows|Query` + `next()`.
- `src/Renderer.php` — static, `Layout::joinHorizontal` 3-pane; uses `candy-sprinkles` `Style`/`Border`/`Layout`.
- `src/SchemaBrowser.php` / `src/ExplainView.php` — **SQLite-specific** (PRAGMA / `EXPLAIN QUERY PLAN`); need driver seams.
- Tests: 10 files, behavioral + structural; use `:memory:` SQLite; `assertStringContains*` (no golden bytes).

---

## Orchestration model (carried down at every level)

A **3-tier serial agent tree**, all spawns serial (never parallel — concurrent writes to `MATCHUPS.md`/`README.md`/composer collide):

```
MASTER agent (owns candy_queries.md, tracks phase/step progress)
 └─ PHASE agent  (one per phase, spawned serially; owns the phase's step list)
     └─ STEP loop (one STEP at a time, serially):
         ├─ CODER agent      → implements the step's small change set
         ├─ REVIEWER agent   → reviews diff vs. step spec ──┐
         ├─ FIXER agent      → fixes reviewer findings      │ repeat REVIEWER⇄FIXER
         │   (loop until REVIEWER returns "no problems") ───┘
         ├─ TESTER agent     → adds/updates/repairs PHPUnit tests until green
         ├─ DOCUMENTOR agent → README / docs/lib/candy-query.html / docs site / PHP docblocks / VHS notes
         └─ SHIP            → commit + push + PR + merge + sync local master (ONE PR for this step)
```

**Agent roles** (each is its own sub-agent, given only the context it needs — keep coder context minimal):

| Agent | Input | Output / Done-when |
|---|---|---|
| **Master** | `candy_queries.md` | Spawns phase agents in order; after each phase verifies `git log`/PR merged + `master` is green; stops on unrecoverable failure and reports. |
| **Phase** | phase number + its step list from `candy_queries.md` | Spawns coder→review→test→doc→ship per step, serially; reports phase summary up. |
| **Coder** | ONE step's spec (files to touch, acceptance criteria, reuse pointers) | Minimal diff implementing the step. Does NOT write tests or docs. Reports changed files + a one-paragraph summary of intent for the reviewer. |
| **Reviewer** | the diff + the step spec | Verdict `PASS`/`CHANGES` with a concrete findings list (correctness, convention adherence, interface/immutability, security/SQL-injection, reuse-not-reinvent). Read-only. |
| **Fixer** | reviewer findings + diff | Applies fixes for the findings only. Loops back to Reviewer. |
| **Tester** | final code + step spec | Adds/updates PHPUnit tests (`tests/<Class>Test.php`), runs `vendor/bin/phpunit` from the lib root until green; uses `:memory:` SQLite / fakes for non-SQLite drivers. Reports test count. |
| **Documentor** | final code + tests | Updates `README.md`, `docs/lib/candy-query.html`, `docs/index.html` tile if needed, PHP docblocks (`Mirrors …`/`Why` comments), `.vhs/*.tape` notes, `CALIBER_LEARNINGS.md` if a gotcha surfaced. No code logic changes. |
| **Ship** | green working tree | Runs the ship cadence below. |

**Per-step ship cadence (ONE PR per step):**
```sh
# author Joe Huss <detain@interserver.net>; branch ai/candy-query-<step-slug>
git checkout -b ai/candy-query-<step-slug>
git add <only this step's files>          # do NOT stage Caliber-managed files
git commit -m "candy-query: <step title>"  # see Caliber note below
git push -u origin ai/candy-query-<step-slug>
unset GITHUB_TOKEN && gh pr create --fill --title "candy-query: <step title>" --body "<summary + ## Test plan: N tests>"
unset GITHUB_TOKEN && gh pr merge <n> --merge --delete-branch
git checkout master && git pull --ff-only
```

---

## Invariants every agent MUST receive and obey (the "carried down" rules)

1. **`unset GITHUB_TOKEN` before EVERY `gh` invocation** (chain it: `unset GITHUB_TOKEN && gh …`).
2. **One PR per step.** Branch `ai/candy-query-<step-slug>`; title `candy-query: <step>`; PR body ends with `## Test plan` citing the test count. Merge with `--merge --delete-branch`, then `git checkout master && git pull --ff-only`.
3. **Author** all commits as `Joe Huss <detain@interserver.net>`.
4. **Caliber: do NOT run `caliber refresh` on this machine.** If a pre-commit hook auto-stages Caliber-managed files (`CLAUDE.md .claude/ .cursor/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ …`), **unstage them** before committing so they don't pollute the step's PR. (Documentor may still hand-edit `CALIBER_LEARNINGS.md` content when a real gotcha is learned — that's a deliberate doc edit, separate from the auto-sync.)
5. **Reuse SugarCraft libs, never reinvent.** Pull `sugar-charts`, `sugar-dash`, `sugar-table`, `candy-async`, `candy-metrics`, `candy-layout` etc. as **`require` entries with `dev-master`** in `candy-query/composer.json` — **do NOT add path-repo `repositories[]` entries for them** (per user). Note: `tools/check-path-repos.php` may flag this; that is expected and accepted for this effort.
6. **PHP conventions:** `declare(strict_types=1);` first line; PSR-12 + PSR-4 under `SugarCraft\Query\`; public classes `final` unless extension is a contract; **immutable + fluent** (`with*()` returns new via `mutate()`; `readonly` state; paired `bool $XSet` sentinels for nullable fields); bare accessors (no `get`); `::new()` default factory; doc-comments cite upstream (`Mirrors jorgerojas26/lazysql.<X>` or `Mirrors mysql-workbench <module>`). Comment WHY not WHAT.
7. **SQL safety:** prepared statements for ALL value interpolation (`KILL` ids, `SET GLOBAL`, `setup_*` rows); fixed whitelist / backtick-escaping for identifiers (schema/view/column names come from static catalogs, never user input). Never `eval()` server data — calc expressions are precompiled closures. Never log passwords; strip them from any echoed DSN.
8. **Tests:** every public method ≥1 test; behavioral (`update()`→`[Model,?Cmd]`), snapshot (renderer SGR bytes where stable), coercion (clamp edges), immutability (`with*()` returns new). Non-SQLite drivers use fakes/`DatabaseInterface` test doubles (no live MySQL/PG required in CI). **`composer update` before trusting a local phpunit failure** (per-lib `vendor/` go stale).
9. **Agents are spawned SERIALLY at every tier.** Never run two write-capable agents at once.
10. **Keep coder context minimal:** a coder agent receives only its single step's spec + the named files + reuse pointers, not the whole plan.
11. **Degrade, don't crash:** every admin page probes privileges/feature presence (catch MySQL errno 1142/1227/1146; conn-loss 2002/2003/2013) and shows a friendly screen, mirroring Workbench's validation framework.

---

## Plan body — Phases & Steps

> Each step is sized for one coder agent with minimal context. "Reuse" names the SugarCraft class to compose. Every step runs the full coder→review⇄fix→test→doc→ship pipeline and ships ONE PR.

### Phase 0 — Driver foundation & multi-driver browsing (lazysql parity)
- **0.1 Interface widening + export split.** Extend `Db\DatabaseInterface` with `serverVersion(): string`, `driverName(): string`, `ping(): bool`, `databases(): array` (schemas/catalogs). Move `importCsv/exportCsv/exportSql` off the legacy class into a `Db\Export\CsvExporter`/`SqlExporter` (driver-agnostic, takes a `DatabaseInterface`). Mark `src/Database.php` `@deprecated`, keep as thin alias of `SqliteDatabase` for BC.
- **0.2 Connection config + factory.** `Db\ConnectionConfig` (readonly value object: driver, host, port, user, pass, dbname, sslMode, dsn). `Db\ConnectionFactory::fromDsn()/fromConfig()/fromArgv()` → `DatabaseInterface`. Password never echoed.
- **0.3 MysqlDatabase.** `final class MysqlDatabase implements DatabaseInterface` (PDO `mysql`). `::connect(ConnectionConfig)`. `ext-pdo_mysql` added to composer `require`.
- **0.4 PostgresDatabase.** `final class PostgresDatabase implements DatabaseInterface` (PDO `pgsql`). `::connect(ConnectionConfig)`. `ext-pdo_pgsql` added.
- **0.5 Version + Flavor.** `Db\Version` parser (handles MariaDB `5.5.5-` prefix; `isAtLeast(maj,min,rel)`), `Db\Flavor` enum {MySQL, MariaDB, Percona, Postgres, Sqlite} + capability flags resolved at connect via `@@version`/`@@version_comment`.
- **0.6 Driver-aware schema introspection.** Extract `Schema\SchemaProviderInterface`; implementations: `SqliteSchemaProvider` (PRAGMA, from current `SchemaBrowser`), `MysqlSchemaProvider` (`information_schema`), `PostgresSchemaProvider` (`information_schema`/`pg_catalog`). `SchemaBrowser` delegates to the provider chosen by `Flavor`.
- **0.7 Driver-aware EXPLAIN.** Extract `Explain\ExplainProviderInterface`; `Sqlite` (`EXPLAIN QUERY PLAN`, current code), `Mysql` (`EXPLAIN FORMAT=JSON`→tree), `Postgres` (`EXPLAIN (FORMAT JSON)`→tree). `ExplainView::run()` dispatches by driver.
- **0.8 Entry-point wiring.** `bin/candy-query` + `AppBuilder` accept `--driver/--host/--port/--user/--pass/--db` (or a DSN). `App::start()` uses `ConnectionFactory`. Default behavior (bare path → SQLite) preserved. Retire direct `Database::open()` use.

### Phase 1 — Admin foundations (ServerContext, polling, calc engine, page shell)
- **1.1 ServerContext (MySQL).** Caches `serverVariables` (`SHOW VARIABLES`), `statusVariables`+ts (`SHOW GLOBAL STATUS`), `plugins` (`SHOW PLUGINS`), parsed `version`/`flavor`. Read by all admin pages. Reuse `Db` layer.
- **1.2 StatusPoller + Sampler.** `Admin\StatusPoller` runs `SHOW GLOBAL STATUS` on a 3 s cadence via **candy-async** (`AsyncOps::throttle`); `Admin\Sampler` does delta-per-elapsed rate math (first sample → null; `resetAll()` on detected restart). Two PDO handles (interactive vs. poller) per the doc.
- **1.3 Calc engine.** `Admin\Calc\{RawValue,RatePerSecond,TupleRatePerSecond,MakeTuple}` over an `Admin\StatusSnapshot` (assoc array + ts). Expressions are **precompiled closures**, never eval.
- **1.4 Format helper.** `Admin\Format`: 1024-based `scaleValue` (K/M/G/T), SI byte formatter, picosecond→us/ms/s/h:m:s, duration. (The one new utility; reuse `Core\Util\Width` for sizing.)
- **1.5 Admin page shell + router.** `Admin\PageBase` lifecycle `validate()→errorScreen()|build()|refresh()`; `Admin\Validation\*` (connection, PS-usable, privilege). Add an `Admin` focus to `Pane` (or a parallel `AdminPane` enum) and a left **sidebar** with two sections (Management / Performance) listing the 6 pages, integrated into `App`/`Renderer`. Reuse **candy-layout** for the responsive shell.

### Phase 2 — Performance Dashboard
- **2.1 Widget model + tables.** `Admin\Dashboard\Widget` struct + the declarative `GLOBAL_DASHBOARD_WIDGETS_{NETWORK,MYSQL_PRE80,MYSQL_POST80,INNODB}` arrays (exact expressions per doc Appendix A), paired with calc classes; version-gated assembly.
- **2.2 Timeline graphs.** `Admin\Dashboard\TimeSeriesCell` rendering throughput/connection graphs via **sugar-charts** `TimeSeries`/`Streamline`/`Canvas\BrailleGrid`; auto-scale "nice ceiling"; newest-at-right.
- **2.3 Counters / meters.** Counter cell (K/M/G via `Format`), round-meter & level-meter via **sugar-dash** `Gauge`/`GaugeCircle`/`Meter`; connections level vs. `max_connections`.
- **2.4 DashboardPage.** 3-column responsive grid (Network/MySQL/InnoDB) via candy-layout; 3 s refresh sampling the cache; `[p]` pause, `[r]` reset, `[1-6]` section keys.

### Phase 3 — Server Status + Status/System Variables
- **3.1 Server Status page.** Info card (host/socket/port/version/uptime→running-since via `Format`), feature/dir/SSL/replica/firewall panels using a `tristate()` helper; `SHOW REPLICA STATUS`/`SHOW SLAVE STATUS` chosen by flavor; graceful 1227 handling.
- **3.2 Sidebar gauges.** CPU(optional)/Connections/Traffic/Key-eff/QPS/InnoDB gauges with threshold coloring (green→yellow→red) via sugar-dash + the Sampler.
- **3.3 Variable metadata.** Port `wb_admin_variable_list` → `data/variable_metadata.json` (name, description, editable, groups); loader `Admin\Variables\Catalog`.
- **3.4 Variables page.** Dual tab (Status `SHOW GLOBAL STATUS` / System `SHOW GLOBAL VARIABLES`), category tree + search, `[rw]` inline edit (`SET GLOBAL` via prepared value), 8.0 persistence (`SET PERSIST`/`persisted_variables`). Grid via **sugar-table**.

### Phase 4 — Client Connections
- **4.1 Processlist provider.** PS path (`performance_schema.threads` + `session_connect_attrs` join) chosen by `SELECT @@performance_schema`; fallback `SHOW FULL PROCESSLIST`. Truncate `PROCESSLIST_INFO`.
- **4.2 Counters strip + filters.** `Threads_*`/`Connections`/`Aborted_*`/`Connection_errors_*` from status snapshot; "hide sleeping/background", "don't load full info"; refresh-rate selector (`0.5…30 s`/off, default off).
- **4.3 Actions + detail tabs.** Kill / kill-query (prepared ids; refuse background), toggle instrumentation, Details/Attributes/MDL(`metadata_locks`) tabs, thread-stack via `sys.ps_thread_stack`, text `EXPLAIN` for selected statement. Grid via sugar-table.

### Phase 5 — Performance Reports
- **5.1 Reports catalog.** Port `sys_reports.js` → `data/sys_reports.json`; `Admin\Reports\Catalog` (category→caption→view, column types/units per doc Appendix B).
- **5.2 Availability + runner.** `SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'` discovery (skip absent views); `ReportRunner` `SELECT * FROM sys.<view> [LIMIT n]`; `UnitFormatter` (picoseconds→time, bytes). MariaDB fallback: hide `sys`-only reports or use direct PS aggregation.
- **5.3 Reports page.** Left report tree + right sortable/exportable grid (**sugar-table** sort/filter/paginate), per-column unit toggle, CSV export (reuse `CsvExporter` from 0.1). Gated behind PS+sys validation.

### Phase 6 — Performance Schema Setup
- **6.1 Setup models.** `Admin\PerfSchema\{SetupInstruments,SetupConsumers,SetupActors,SetupObjects,SetupThreads,SetupTimers}` loading the `setup_*`/`threads`/`performance_timers` queries; `InstrumentTree` (`/`-split, tri-state −1/0/1, bottom-up group state); change-tracking overlay (original vs current).
- **6.2 Minimal-diff commit.** `commitStatements()` per model: instruments RLIKE buckets, consumers/threads `IN(...)`, actors/objects keyed `INSERT/UPDATE/DELETE`, timers `UPDATE`. Only emit for dirty sections; reset tracking after.
- **6.3 Easy Setup.** Detection (fully/default/custom/disabled COUNT/SUM queries, `memory/%` excluded) + toggle statements + default instrument/consumer sets (doc Appendix C).
- **6.4 PS Setup page.** Tabs: Easy Setup, Instruments (collapsible tri-state tree `[x]/[ ]/[~]`), Consumers, Actors&Objects, Threads, Options(timer). Privilege-aware read-only mode; "pending changes" + commit/revert.

### Phase 7 — Polish, history, Postgres-admin seam, docs
- **7.1 Resilience.** Reconnect on 2002/2003/2013; restart detection (Uptime drop) → `Sampler::resetAll()`; statement timeout for heavy reports so the loop never freezes.
- **7.2 Alerting.** Turn thresholds into notifications (e.g. connections > 90% of `max_connections`); optional toast via existing libs.
- **7.3 Optional history.** Opt-in SQLite snapshot persistence for multi-hour dashboards (exceeds Workbench); reuse `candy-metrics` `Registry`/backends if it fits.
- **7.4 Postgres-admin seam.** Finalize `Admin\AdminProvider` interface; ship Postgres stub providers (`pg_stat_activity`→connections, `pg_stat_database`→dashboard counters) wired into Dashboard + Connections as a proof, the rest stubbed with "Postgres support coming" screens.
- **7.5 Docs/site final pass.** README feature matrix, `docs/lib/candy-query.html`, `docs/index.html` tile, new `.vhs/*.tape` demos (dashboard, reports), `MATCHUPS.md`/`PROJECT_NAMES.md` if status changes. (Per-step documentor already keeps these current; this is the consolidation pass.)

**Verification (end-to-end):** for every touched step, `cd /home/sites/sugarcraft/candy-query && composer update && vendor/bin/phpunit` green; manual smoke via `php bin/candy-query --driver=mysql --host=… --db=…` (and a SQLite path for regression) reaching each admin page; the Dashboard renders live sparklines against a throwaway MySQL on a low-priv account; CSV export round-trips; PS Setup commit produces the expected minimal SQL against a scratch instance. CI auto-discovers candy-query via `scripts/affected-libs.php`; `.github/workflows/vhs.yml` `all=(...)` array updated if new tapes are added.

---

## Notes / risks
- **Path-repo deviation:** wiring SugarCraft deps as Packagist `dev-master` (no path repos) per user request diverges from the monorepo's symlink convention; `tools/check-path-repos.php` will flag it and local resolution relies on published packages. Flagged here so it's a conscious choice, not a surprise.
- **One-PR-per-step volume:** ~40–60 PRs. Accepted per user; overrides the bundle-2-4 convention for this effort.
- **Admin sections are MySQL-first;** Postgres admin is a seam + stubs (Phase 7.4), not full parity.
- **No live MySQL/PG in CI:** driver + admin logic is unit-tested against `DatabaseInterface` fakes; live smoke is manual (verification section).

---

## Appendix — Master startup prompt
The launch prompt lives in `candy_queries_prompt.md`; paste it to a fresh agent to kick off the master.
