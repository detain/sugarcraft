<img src=".assets/icon.png" alt="candy-sql" width="160" align="right">

# CandySql

> Formerly published as `sugarcraft/candy-query` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Query\` → `SugarCraft\Sql\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-sql)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-sql)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-sql?label=packagist)](https://packagist.org/packages/sugarcraft/candy-sql)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

Terminal SQL client on the SugarCraft stack for PHP 8.3+ — MySQL, MariaDB, PostgreSQL and SQLite, with Workbench-style admin dashboards.

```bash
composer require sugarcraft/candy-sql
```

## CLI usage

First run, no database at hand: `php examples/play.php` seeds an in-memory
SQLite instance (`users`/`posts`) and opens the dashboard over it. Invoked with
no arguments the launcher prints a one-line `USAGE:` reminder and exits 2;
`bin/candy-sql --help` prints the full flag table (exit 0) and `--version`
prints the build stamp (exit 0).

```bash
# (the `bin/candy-sql` form below assumes a monorepo checkout — installed as a
#  composer dependency the launcher is at `vendor/bin/candy-sql`, same flags)
# SQLite via bare path (inferred as Flavor::Sqlite)
bin/candy-sql app.sqlite

# Password via environment instead of argv — the preferred shape whenever a
# password is needed (keeps the secret out of /proc/<pid>/cmdline)
CANDY_SQL_PASS=secret bin/candy-sql --driver=mysql --user=root --db=mydb

# Any DSN — MySQL, PostgreSQL, SQLite (flags use --key=value form)
bin/candy-sql --dsn=mysql://user:pass@localhost:3306/dbname
bin/candy-sql --dsn=pgsql://user:pass@localhost:5432/dbname
bin/candy-sql --dsn=sqlite:///absolute/path/to/db.sqlite

# Individual flags (no DSN)
bin/candy-sql --driver=mysql --host=localhost --port=3306 --user=root --db=mydb

# Status-snapshot history: on by default at $HOME/.candy-sql/history.db
bin/candy-sql app.sqlite --no-history
bin/candy-sql app.sqlite --history=/var/tmp/my-history.db
```

Supported drivers are `sqlite`, `mysql` and `pgsql` (`ConnectionFactory::SUPPORTED_DRIVERS`); anything else — including
`sqlsrv://` DSNs — throws at parse time. For SQLite, the bare path must name an **existing** file: `SqliteDatabase::open()`
hard-refuses nonexistent paths rather than silently creating an empty database that loses every write at exit
(src/Db/SqliteDatabase.php:31; `ConnectionFactory::fromPath()` inherits the refusal at :315) — point at a real file or
import first (e.g. `sqlite3 fresh.sqlite < dump.sql`).

`App::start($db, Flavor $flavor = Flavor::Sqlite, ?HistoryRecorder $historyRecorder = null, ?string $sslMode = null)` accepts an
optional `$flavor` override, an optional history recorder (the launcher builds one itself — see Status-Snapshot History), and an optional
`$sslMode` override: unset, the MySQL ssl-mode is derived from a real `MysqlDatabase` handle to feed the TLS gate (see MySQL SSL
above); the explicit string exists as a test seam because `MysqlDatabase` is final with a private constructor.

## Keys

| Key                | Action                                          |
|--------------------|-------------------------------------------------|
| `Tab`              | Cycle pane (Tables → Rows → Query → Admin)      |
| `↑/↓` or `j/k`     | Move cursor in active list pane                 |
| `↑/↓` (query pane) | Move the editor cursor between lines (chars reach the buffer, so this never scrolls a list here) |
| `←/→` or `h/l`     | Scroll the Rows pane's result columns when the table is wider than the pane (`App::handleRowsKey`, src/App.php:321) |
| `Enter` / `Space`  | Load the highlighted table into the rows pane   |
| `Enter` (query pane) | Insert a newline in the editor — run with `Ctrl+R`/`Ctrl+E` (`App::editQuery`, src/App.php:456) |
| `Ctrl+R`           | Run the SQL in the query pane                   |
| `Ctrl+E`           | Run the SQL in the query pane                   |
| `Ctrl+F`           | Save the query pane's text to the favorites list (session state — see disclosure below) |
| `Ctrl+Shift+F` | Remove the current query from favorites |
| `Backspace`        | Delete last character (query pane)              |
| `q` / `Esc`        | Quit from the Tables and Rows panes only; inside Admin, `q` returns to Tables and `Esc` cancels an open modal dialog; inside the query pane `q` types and `Esc` does nothing |

### Admin Dashboard Keys

| Key                | Action                                          |
|--------------------|-------------------------------------------------|
| `[p]`              | Pause/resume auto-refresh (global)              |
| `[r]`              | Reset counters (Dashboard) / Refresh processlist (Connections) |
| `[j/k]` `[↑/↓]`    | Navigate rows down/up on the row-navigable pages (Variables, Connections, Reports, PerfSchema, Debug) |
| `[e]`              | Edit selected variable — dynamic vars only (Variables page): type new value, [Enter] confirm → [Enter] execute or [Esc] cancel. The dialog spells out the three scopes `[p]` cycles: GLOBAL = runtime only (lost on restart), PERSIST = runtime + config file, PERSIST_ONLY = config file only (applies at next restart) |
| `[w]`              | Toggle read/write filter (Variables page)       |
| `[s]`              | Focus search input (Variables page)              |
| `[f]`              | Toggle hide-sleeping filter (Connections page)  |
| `K` / `X`          | Connections page: arm `KILL CONNECTION` / `KILL QUERY` for the selected thread (background/system threads are refused outright) |
| `y`                | Fire the armed kill / staged PerfSchema commit — any other key cancels (fail-safe: a stray keystroke never kills a session) |
| `[c]`              | Stage commit of pending changes (PerfSchema page); `[y]` fires, any other key cancels |
| `[c]`              | Toggle column unit formatting (Reports page)    |
| `[x]`              | Build CSV of the current report into page state — not written to disk (Reports page; see disclosure below) |
| `[a]`              | Dismiss all pending alerts (Dashboard)          |
| `1` – `8`          | Switch admin page (see digit map below)         |

**Session-state-only surfaces (disclosed):** `Ctrl+F`/`Ctrl+Shift+F` add and remove
the current editor text in a session-scoped favorites list inside `QueryState`, but no
pane renders that list yet and `↑`/`↓` do **not** recall favorites or past queries —
closing the app drops them. Likewise `[x]` on the Reports page regenerates
`ReportsPage::exportToCsv()` into the page object (readable via `lastExportCsv()`);
it never touches the filesystem — writing `.csv` files is the separate
`Db\Export\CsvExporter` API described under Exporters.

The admin pages' own `Tab`/`Shift+Tab` tab-cycling handlers (Variables, Connections detail tabs, PerfSchema) and ConnectionsPage's `[1]`/`[2]`/`[3]` detail-tab shortcuts exist in the page code but are unreachable during normal app operation:
`Tab` cycles the focus pane and the digits select admin pages, both at the app level — the pages' private handlers for those keys are therefore never reached except while a modal dialog is open, where App hands every key to the page instead (the dialog consumes or cancels it) rather than cycling panes or switching pages. Outside that window they are kept only for standalone (non-app) use. The live in-app substitutes are page-local keys App delegates: `h`/`l` (or the arrows) cycle PerfSchema tabs, and the PerfSchema Easy Setup actions run on the `[` / `]` / `.` bracket pair — the same convention the Reports page uses for its own navigation. Connections detail tabs currently have no in-app chord; open the process row's details with `K`/`X`-adjacent selection keys as rendered.

> **Key routing** — `App::update()` consumes, in order: `Ctrl+C` (unconditional quit); on the Admin pane, every key goes to the page while it is modal (`PageBase::isModal()`: the variable-editor dialog, the GTID dialog, a pending kill confirmation, or a staged PerfSchema commit) and `Esc` goes to the page whenever it is present; `q`/`Esc` quit **only from the Tables and Rows browser panes** (in the Query pane `Esc` no longer discards a drafted query); `Tab` cycles the focus pane. In the Query pane, `Ctrl+R`/`Ctrl+E` run the query (both also accept `Ctrl+Enter`) and every other key edits the buffer — except destructive statements (`DROP`/`TRUNCATE`/`DELETE`/`ALTER`/`RENAME`/`GRANT`/`REVOKE`), whose first press only arms a warning; a second consecutive run of the byte-identical SQL confirms, and any edit or other query disarms. On the Admin pane, `App::handleAdminKey()` then intercepts the page picker (`1`–`{n}`, `p`, `r`, `q`→return to Tables); `r` additionally forwards to the active page after dropping the shared `AdminQueryCache`. Pages listed in `RowNavigable` (Variables, Connections, Reports, PerfSchema, Debug) keep their own `j`/`k`/`↑`/`↓` row cursors; on non-row-navigable pages (ServerStatus, Dashboard) those same keys walk the sidebar. All remaining keys are delegated to the active page's `update()` method.

> **MySQL / PostgreSQL only** — the Admin pane needs server introspection data. On SQLite, `App::createContext()` falls back to `EmptyServerContext`, whose variable/status/processlist accessors all return empty sets (`connection()` throws and the async fetch path swallows it in a `Throwable` guard); admin pages render empty rather than crashing — run the admin panes against MySQL or PostgreSQL to see live content.

### Admin Page Digit Map

The admin sidebar is split into two sections (Management, then Performance). The admin digit handler accepts `1`–`9`; digits `1`–`8` select pages in display order, and any accepted digit with no page at that position (`9`) is ignored:

| Digit | Management Section | Digit | Performance Section |
|-------|--------------------|-------|----------------------|
| `1`   | Process List       | `5`   | Query Stats          |
| `2`   | Variables          | `6`   | Dashboard            |
| `3`   | Status             | `7`   | Table Stats          |
| `4`   | Debug              | `8`   | Performance Schema   |

Digit `4` selects **Debug** (a Management-section page); digit `8` selects **Performance Schema**. Display order and digit selection are both derived from `AdminPane::orderedCases()` — any code needing the mapping should call that method rather than hard-coding indices.

> **Page state survival** — Admin pages (Variables, Dashboard, Connections, etc.) preserve their in-memory state (active tab, cursor position, pending edits) across the admin poll cycle (1.0s tick, 1.0s fetch window — `CacheTtl::DASHBOARD`). Fresh server data is read from the shared `AdminQueryCache` on each render; only switching panes (digits `1`–`8`) rebuilds the page from scratch.

## Architecture

Names in the `File` column below are resolved against the `SugarCraft\Sql\` root namespace:
prepend `SugarCraft\Sql\` to any entry for its exact import (e.g. `Db\Version` is
`SugarCraft\Sql\Db\Version`). The DB server-version parser is `Db\Version`; the app's
`--version` resolver lives in `src/Version.php` (`SugarCraft\Sql\Version`) and is not in
these tables.

| File              | Role                                                                                          |
|-------------------|----------------------------------------------------------------------------------------------|
| `Db\ConnectionConfig`| Readonly value object: driver, host, port, user, pass, dbname, sslMode, dsn. Pass never echoed. |
| `Db\ConnectionFactory`| Static factory — four entries: `fromDsn()` parses a DSN into a `ConnectionConfig` without connecting; `fromConfig()` and `fromArgv()` connect and return a `DatabaseInterface`; `fromPath()` opens a bare SQLite file path.    |
| `Db\Flavor`          | Enum: `MySQL`, `MariaDB`, `Percona`, `Postgres`, `Sqlite`. Used to identify database flavor. `Flavor::detectFromDriver(string $driverName, string $version = '', string $versionComment = '')` detects flavor from a PDO driver name plus an optional version string and version comment — the comment carries the MariaDB/Percona discriminator that a bare `5.5.5-`-prefixed version string hides. |
| `Db\Version`         | Parser for server version strings. Handles MariaDB `5.5.5-` prefix. `isAtLeast(Version)` compares versions. |
| `Database`        | ⚠️ Deprecated thin alias to `SqliteDatabase`. Use `DatabaseInterface` for driver-agnostic code.   |
| `Db\MysqlDatabase`   | `DatabaseInterface` implementation via PDO `mysql`. Implements `serverVersion()`, `driverName()`, `ping()`, `databases()`. |
| `Db\PostgresDatabase`| `DatabaseInterface` implementation via PDO `pgsql`. Implements `serverVersion()`, `driverName()`, `ping()`, `databases()`. |
| `Db\PreparedStatementInterface` | Driver-neutral prepared statement interface: `execute()`, `fetch()`, `fetchAll()`, `rowCount()`, `closeCursor()`. Abstracts PDOStatement so callers work with a uniform type across drivers. |
| `Db\PdoPreparedStatement` | `PreparedStatementInterface` via PDOStatement. Wraps any PDO driver statement in the neutral interface. All implementations (`MysqlDatabase`, `PostgresDatabase`, `SqliteDatabase`) return this wrapper from `prepare()`. |
| `Pane`            | Enum for pane focus + `next()`.                                                              |
| `App` (Model)      | Tables list, rows pane, in-progress SQL editor buffer, error string, status string.         |
| `Renderer`        | Three rounded-border panes — tables, rows, query — with the focused pane getting a brighter accent. |
| `SchemaBrowser`   | Schema introspection via strategy pattern — delegates to driver-specific `SchemaProviderInterface` implementation based on `Flavor`. |
| `Schema\SchemaProviderInterface` | Interface for driver-specific schema introspection. Implement `tables()`, `columns()`, `indexes()`, `foreignKeys()`. |
| `Schema\SqliteSchemaProvider` | `SchemaProviderInterface` via PRAGMA queries (tables, columns, indexes, foreign keys). |
| `Schema\MysqlSchemaProvider` | `SchemaProviderInterface` via `INFORMATION_SCHEMA` queries. |
| `Schema\PostgresSchemaProvider` | `SchemaProviderInterface` via `pg_catalog` + `information_schema` queries. |

| File              | Role                                                                                          |
|-------------------|----------------------------------------------------------------------------------------------|
| `ExplainView`     | Renders `EXPLAIN` output as a colour-coded ANSI tree. Uses strategy pattern — delegates to driver-specific `ExplainProviderInterface` based on `Flavor`. |
| `Explain\ExplainProviderInterface` | Interface for driver-specific EXPLAIN parsing. Implement `explain(string $sql): array` returning a list of explain rows; the connection handle is constructor-injected on the provider, never a parameter. |
| `Explain\SqliteExplainProvider` | `ExplainProviderInterface` via `EXPLAIN QUERY PLAN`. Returns raw rows; tree-prefix (`|--`, `` `-- ``) depth parsing lives in `ExplainView::depthFromDetail()`. |
| `Explain\MysqlExplainProvider` | `ExplainProviderInterface` via `EXPLAIN`. Returns `EXPLAIN` formatted rows with tag/parent/detail/indent. |
| `Explain\PostgresExplainProvider` | `ExplainProviderInterface` via `EXPLAIN (ANALYZE, FORMAT JSON)`. Parses JSON structure for tree hierarchy. |
| `Admin\AdminProviderInterface` | Flavor-agnostic admin-data port: `flavor()`, `fetchStatusVariables()`, `fetchServerVariables()`, `fetchProcesslist()`, `maxConnections()`, `statusVariablesTs()`, `wasReset()`, `refresh()`. Bridges to `ServerContextInterface` and `ServerContext`. |
| `Admin\Providers\MysqlAdminProvider` | `AdminProviderInterface` via MySQL `SHOW GLOBAL STATUS/VARIABLES`, `SHOW ENGINE INNODB STATUS`, `SHOW REPLICA STATUS`, and `fetchProcesslist()` (prefers Performance Schema `performance_schema.threads` with graceful fallback to `SHOW FULL PROCESSLIST` on permission errors). |
| `Admin\Providers\PostgresAdminProvider` | `AdminProviderInterface` via `pg_stat_database`, `pg_settings`, `pg_stat_activity`. Dashboard/connections implemented via `PostgresWidgetCatalog`. |
| `Admin\Dashboard\PostgresWidgetCatalog` | Provides `io()` (10 widgets: tuple metrics) and `cache()` (4 widgets: Shared Buffers) panels. The Shared Buffers byte conversion `parseSharedBuffers()` is a private method of `Admin\Providers\PostgresAdminProvider`, not of this catalog. |
| `ResultTable`    | Renders SQL result sets with horizontal scrolling, JSON pretty-print (2-space indent), styled NULL token, and column auto-sizing. `scrollLeft()`/`scrollRight()` builders. |
| `Admin\ServerStatus\ServerStatusPage` | 2-column admin page: info/features/directories/SSL/replication/firewall panels on left, `SidebarGaugeSet` on right. Gauges poll ServerContext and optional Sampler for per-second rate calculations. `r` refresh; `q` returns to the Tables browser (as on every admin page). |
| `Admin\ServerStatus\ServerInfoCard`    | Info card with host, socket, port, version, uptime (computed to running-since). |
| `Admin\ServerStatusSnapshotAdapter` | Adapter wrapping `ServerContextInterface` to satisfy `StatusSnapshotProviderInterface`, enabling `Sampler` to compute per-second rate deltas across poll cycles. Stores snapshots internally so two-sample rate logic can operate on the same context without the context needing to implement the interface directly. |
| `Admin\Sampler` | Rate computer service: takes two status snapshots and elapsed time, computes per-second deltas (Bytes_received, Bytes_sent, etc.) with negative-delta clamping. Restart detection is consumed from `provider->wasReset()` via `StatusSnapshotProviderInterface` — delegating to `ServerContext::detectReset()` as the single owner. Advance via `sample()` on each gauge refresh cycle. |
| `Admin\Variables\VariablesPage`     | Dual-tab (Status/System) variable browser with category tree, search filtering, keyboard nav (j/k/w/s/e/q), and inline edit via VariableEditor. The `[e]` key opens a two-phase dialog: type the new value (DLG_INPUT), press [Enter] to review the SET statement (DLG_CONFIRM), then [Enter] again to execute or [Esc] to cancel. Self-write is guarded: setting a variable to its current value is a no-op. Static (non-dynamic) variables show error 1238 with a clear message rather than silently refusing. The `[tab]` Status/System toggle is unreachable while the app runs: `App::update()` consumes `Tab` for global pane cycling before admin delegation, so the page's handler (VariablesPage.php:207-213) is kept only for standalone use — same fate as the ConnectionsPage detail-tab handlers below. Mirrors charmbracelet/lazysql's VariablesPage (src/Admin/Variables/VariablesPage.php:42). |
| `Admin\Variables\VariableEditor`    | Inline editor for MySQL variables via `SET GLOBAL` / `SET PERSIST` / `SET PERSIST_ONLY` / `RESET PERSIST` (8.0+). Value binding rides PDO_MYSQL's emulated prepares (the default): MySQL has no native placeholder in a SET-value position, so PDO quotes the value client-side into the statement text before the server sees it — the `?` is real quoting by PDO, not a server-side prepared statement. Backtick-escapes variable names (from catalog, not user input). Error handling: 1142 (no privilege), 1227 (access denied), 1238 (variable is not dynamic — requires restart or use PERSIST_ONLY), 3680 (persisted_variables restriction). Press [p] in the edit dialog to cycle through GLOBAL → PERSIST → PERSIST_ONLY modes. Mirrors `mysql-workbench wb_admin_variable_editor`. |
| `Admin\Variables\VariableMetadata` | Immutable descriptor: name, description, editable flag, **dynamic flag**, group memberships. Single MySQL system variable. `editable` = can be set at all (SET GLOBAL / SET PERSIST); `dynamic` = can be changed at runtime without restart (static vars like `innodb_log_file_size` accept SET GLOBAL but error 1238). Constructed from `data/variable_metadata.json` (1563 entries). |
| `Admin\Variables\Catalog` | Loads `data/variable_metadata.json` (1563 variables, 67 groups). Provides `get()`, `all()`, `byGroup()`, `groups()`, `isEditable()`, `isDynamic()`. `isDynamic()` returns false for static variables (e.g. `innodb_log_file_size`) — used by VariablesPage to gate inline editing. Missing metadata is non-fatal — the page renders without categories or `[rw]` indicators. |
| `Admin\Connections\ConnectionsPage`  | Processlist browser with selection navigation (j/k/↑/↓), hide-sleeping filter (f), and async refresh (r) via `Cmd::send`. The detail-tab cycling handlers (Tab / 1 / 2 / 3) are unreachable today: Tab cycles panes globally and digits 1–8 select admin panes first. Mirrors charmbracelet/lazysql's connections page (src/Admin/Connections/ConnectionsPage.php:28). |
| `Admin\Connections\ConnectionFilters` | Immutable filter config: hide-sleeping, hide-background, skip-full-info, refresh-rate. All fields are readonly with paired `$Set` sentinels. |
| `Admin\Connections\ConnectionCounters` | Connection metrics from `SHOW GLOBAL STATUS`: threads-connected/running/cached, connections, aborted-connects, connection-errors. Computes `connectionUsageRatio()` lazily (0.0–1.0). |
| `Admin\Connections\ConnectionDetailTabs` | Three detail tabs (Details/Attributes/MDL) per processlist thread. Details from `performance_schema.threads`; Attributes from `session_connect_attrs`; MDL from `performance_schema.metadata_locks` with graceful fallback to `information_schema.metadata_lock_info`. Gracefully returns `null` on permission errors (1142/1146/1227/42000). |
| `Admin\Connections\ProcesslistProvider` | Fetches processlist via PS (`performance_schema.threads` + `session_connect_attrs`) with fallback to `SHOW FULL PROCESSLIST` on permission errors. Memoized via `cachedFilteredProcesslist` in `ConnectionsPage` to avoid 2–3× fetch per render. |
| `Admin\ServerStatus\ReplicaStatusProvider` | Fetches replica status via `SHOW REPLICA STATUS` (MySQL 8+) or `SHOW SLAVE STATUS` (MySQL 5.x/MariaDB), graceful 1227 handling. |
| `Admin\ServerStatus\SidebarGauge` | Single metric gauge with threshold coloring (green/yellow/red). Uses horizontal Gauge for all metric types. |
| `Admin\ServerStatus\SidebarGaugeSet` | Collection of 5 gauges: Connections, Traffic, Key Efficiency, QPS, InnoDB. Polls ServerContext and optional `Sampler` for per-second rate calculations. Traffic gauge uses Sampler delta (Bytes_received/Bytes_sent) for baseline-corrected per-second ratio; key-efficiency uses `Key_reads / (Key_reads + Key_read_requests)`. Sampler advances on each `withRefresh()` call via `ServerStatusSnapshotAdapter` bridging `ServerContextInterface` to `StatusSnapshotProviderInterface`. |
| `Admin\Reports\Catalog` | Loads `data/sys_reports.json` (report widget definitions). Provides `get()`, `all()`, `byCategory()`, `categories()`. Categories are sorted by a curated `CATEGORY_ORDER` constant (problems first, matching MySQL Workbench Appendix B) rather than alphabetically. Unknown categories fall through to alphabetical ordering after the curated set. Uses `ColumnType::tryFrom()` to gracefully handle unknown column type strings rather than throwing a fatal. |
| `Admin\Reports\ReportsPage` | Performance Reports admin page: left category/report tree + right sortable/exportable grid. `validate()` acquires the `$this->context->connection()` handle and loads `Catalog` (file I/O) — no DB queries on the render path (ReportsPage.php:134-140). Navigation methods `withSelectPrevCategory()` / `withSelectNextCategory()` / `withSelectPrevReport()` / `withSelectNextReport()` cycle through the catalog with wrap-around. `selectedColumnIndex` tracks the focused column for unit display targeting (future work). Footer shows keybindings `[j/k] nav rows  [h/l] category  [[]/[]] report  [,/.] column  [c] unit  [r] refresh  [x] export  [q] back`. |
| `Admin\Reports\ReportRunner` | Executes `SELECT * FROM sys.<view>` for report views. MySQL cannot parameterise table names, so the view name rides literal SQL escaped through `Identifier::quote()`'s MySQL arm (backticks and backslashes doubled) with the limit cast to `int` — the safety mechanism is escaping, not preparation. `run()` applies time/byte unit formatting; `runRaw()` returns unformatted values. |
| `Admin\Reports\AvailabilityChecker` | Checks which sys schema views are available via `SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'`. Caches results in-memory. `discoverViews()` catches `\Throwable` (not just `\PDOException`) because React/cached connections can surface non-PDO errors. |
| `Core\Msg\ReloadReportMsg` | Message dispatched by `App` after `AdminDataLoadedMsg` to trigger async report loading. `ReportsPage::update()` handles this by calling `loadCurrentReport()` which queues the query via `AsyncCachedConnection` for the next admin tick. |
| `Admin\Calc\InnoDBBufferPoolUsageBytes` | Computes buffer pool usage percentage using bytes-based formula: `(Innodb_buffer_pool_bytes_data / Innodb_page_size) / Innodb_buffer_pool_pages_total * 100` (the MySQL Workbench dashboard appendix formula). This replaces the older `(total - free) / total` page-count approximation and correctly handles partially-filled pages. |
| `Admin\Dashboard\TimeSeriesCell` | Renders a timeline sparkline using Streamline. For tuple-valued widgets (e.g. `MakeTuple` with SELECT/INSERT/UPDATE/DELETE rates), uses `max()` to show the dominant series rather than summing unrelated counters. Sliding window of `$windowSize` (default 160) data points with auto-scale "nice ceiling". |
| `Admin\Dashboard\CounterCell` | Renders a K/M/G-scaled counter widget. Used for timeline companion counters and standalone rate widgets (SELECT/s, INSERT/s, etc.). Uses `array_sum()` for tuple-valued widgets since counters are additive by design. |
| `Admin\Dashboard\MeterCell` | Renders round (GaugeCircle) and level (Gauge) meters. Tracks `$value` and `$max` separately for level-meter readout display. `viewLevel()` appends `sprintf($format, (int)$value, (int)$max)` when the widget's format string is non-trivial (e.g. `'%d / %d'` for Connections). |
| `Admin\Dashboard\WidgetCatalog` | Declarative widget tables mirroring MySQL Workbench Appendix A dashboard definition. `network()`, `mysqlPre80()`, `mysqlPost80()`, `innodb()` return widget entry arrays. `innodb()` now includes 13 widgets: 8 new InnoDB metrics (Row Lock Waits/Time, Pages Flushed/Created/Read, Insert Buffer, Read Ahead) plus the original 5 plus Redo Log/Doublewrite/Disk R/W metrics. |
| `Admin\Dashboard\WidgetRegistry` | Widget kind constants (`KIND_TIMELINE`, `KIND_COUNTER`, `KIND_ROUND`, `KIND_LEVEL`) and version-gated factory (`build()`) assembling Network + MySQL + InnoDB panels. `network()`/`mysql()`/`innodb()` provide per-panel access for `buildSectionWidgetCache()` in `DashboardPage`. |
| `Admin\Calc\TableOpenCacheHitRate` | Computes Table Open Cache hit ratio: `hits / (hits + misses) * 100` from `Table_open_cache_hits/misses`. Mirrors MySQL Workbench dashboard expression. |
| `Admin\Resilience\ReconnectManager` | Detects MySQL errors 2002/2003/2013 (connection lost), stores `ConnectionConfig`, and retries via `attemptReconnect()`. Throws `ReconnectException` on failure. |
| `Db\ReconnectException` | Exception thrown when reconnection fails after a MySQL connection error. |
| `Admin\Resilience\StatementTimeout` | Wraps `PDOStatement::execute()` with a wall-clock timeout via `pcntl_alarm()`. When pcntl is unavailable the wrapper is transparent: the statement executes with no enforcement and no diagnostic. Throws `StatementTimeoutException`. |
| `Admin\Resilience\StatementTimeoutException` | Thrown when a statement exceeds its wall-clock timeout and is cancelled via `KILL CONNECTION_ID()`. |
| `Admin\Alerts\Severity` | Enum: `Info`, `Warning`, `Critical`. Maps to `ToastType` via `toToastType()` — `Info→Info`, `Warning→Warning`, `Critical→Error`. |
| `Admin\Alerts\Alert` | Immutable alert value object: severity, metric, message, value, threshold, firedAt, metricKind. Factory helpers: `::warning()`, `::critical()`, `::info()`. `toToastMessage()` formats based on `MetricKind` — `Ratio` multiplies by 100 (percentage), `Seconds` appends "s", `Count` renders as integer. |
| `Admin\Alerts\AlertThresholds` | Immutable threshold configuration with fluent `with*()` builders. Presets: `::new()` (bare), `::default()` (60%/80%), `::strict()` (50%/70%). Watches connection usage, aborted rate, slow query time, thread running, connection errors. |
| `Admin\Alerts\AlertNotifier` | Toast notification dispatcher. Mute-safe by default (no factory = all calls no-op). `::withDefaults()` bootstraps a standard factory. `notify(Alert)`, `notifyWarning()`, `notifyCritical()`, `notifyError()`, `notifyInfo()`, `view()` composites toast over a background viewport. |
| `Admin\Alerts\AlertManager` | Stateless alert checker. `checkConnectionUsage(ConnectionCounters)` and `checkAllMetrics(statusVariables, serverVariables)` return `array<string, Alert>`. Each alert carries a `MetricKind` (`Ratio`, `Seconds`, `Count`) that controls how its value is formatted in toast messages. `checkAndDispatch()` combines check + notify in one call. No state held between calls. |
| `Admin\PerfSchema\PerfSchemaPage` | Performance Schema configuration page with 7 tabs (Easy Setup, Instruments, Consumers, Actors, Objects, Threads, Options). Version-gated loading: `setup_actors` table skipped on <5.6; `setup_objects.ENABLED` column omitted on <5.6.3; `setup_timers` (mutable) used on <8.0 vs `performance_timers` (read-only) on >=8.0. Threads tab shows `INSTRUMENTED` column from `performance_schema.threads`. `[c]` stages a commit — the page lists the prepared statements and waits for `[y]`; any other key cancels (destructive-confirmation parity with the kill and SET GLOBAL prompts). `[r]` reverts. |
| `Admin\PerfSchema\SetupTimers` | Mutable model for a PS timer entry. On MySQL <8.0, loaded from `performance_schema.setup_timers` and can be modified via `withTimerName()`. `isDirty()` tracks unsaved changes; `commitStatements()` returns a fully-literal statement — e.g. `UPDATE performance_schema.setup_timers SET TIMER_NAME = 'MICROSECOND' WHERE NAME = 'wait/cpu'` — with identifiers backtick-quoted and values interpolated as escaped quoted literals (no `?` placeholders: MySQL has no native placeholder in the SET-value position, and PDO_MYSQL's emulation does the client-side quoting). On MySQL >=8.0, loaded from `performance_schema.performance_timers` as clean (read-only) instances. |
| `Admin\PerfSchema\SetupThreads` | Mutable model for a PS thread entry carrying `INSTRUMENTED` flag. `withInstrumented(bool)` toggles; `isDirty()` tracks changes; `instrumentedFragment()` generates a `THREAD_ID=N AND INSTRUMENTED='YES'/'NO'` SQL fragment for CommitPlanner's batch UPDATE (wiring deferred to a planned follow-up). |
| `Admin\PerfSchema\CommitPlanner` | Generates SQL statements to commit PS configuration changes. All statements are fully parameterized — returns `list<array{sql:string, params:list<mixed>}>` tuples with all values bound as `?` placeholders, not interpolated. Produces anchored `UPDATE ... RLIKE '^name$'` for instruments (regex-escaped via `preg_quote()`), `UPDATE ... IN(?)` for consumers, and `INSERT/UPDATE/DELETE` for actors and objects. SetupTimers/SetupThreads wiring deferred to a planned follow-up. |
| `Admin\History\HistoryStoreInterface` | Persistence interface for admin status history: `save(StatusSnapshot)`, `query(DateTimeImmutable $since, DateTimeImmutable $until): list<StatusSnapshot>`, `count()`, `prune(DateTimeImmutable $before): int`, `close()`. |
| `Admin\History\SqliteHistoryStore` | `HistoryStoreInterface` via SQLite with WAL mode. Schema: `history(id INTEGER PRIMARY KEY, ts REAL, recorded_at TEXT, variables TEXT)` — `variables` is the JSON-encoded status-variable map. A freshly created store is owner-only (dir 0700 / file 0600). |
| `Admin\History\HistoryRecorder` | Passive recorder implementing `StatusSnapshotProviderInterface`. `record(StatusSnapshot)` persists one snapshot; `recordFromProvider()` pulls from an attached provider; `pruneOlderThan($cutoff)` delegates to the store. It writes only when told — no active recording, no coupling to the UI. Records server metrics (`StatusSnapshot`), not SQL query text. |
| `Admin\History\HistoryQuery` | Read-side helpers over a store: `query(float $sinceTs, float $untilTs): list<StatusSnapshot>`, `querySince(float $sinceTs)`, `rate(string $variable, float $sinceTs, float $untilTs): ?float` (per-second delta of one status variable across two snapshots; `null` when fewer than two rows are available). |

The PDO connection is the only stateful dependency; tests use a `:memory:` SQLite to exercise the full transition surface (load tables, switch panes, run query, error handling) without fixture files.

Multi-driver support is now available via `DatabaseInterface`. `CsvExporter` and `SqlExporter` provide driver-agnostic export.

## Connection factory

`ConnectionFactory` provides four static methods for turning connection parameters into a usable connection — or into a value object that describes one:

```php
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\ConnectionConfig;

// From a DSN string -> a ConnectionConfig value object (parsing only; nothing is dialed)
$config = ConnectionFactory::fromDsn('mysql://user:pass@localhost:3306/dbname?ssl-mode=REQUIRED');

// From a ConnectionConfig (the constructor requires all eight fields; ::new() takes seven and computes the dsn itself)
$config = ConnectionConfig::new(
    driver: 'mysql',
    host: 'localhost',
    port: 3306,
    user: 'user',
    pass: 'secret',
    dbname: 'dbname',
    sslMode: 'REQUIRED',
);
$db = ConnectionFactory::fromConfig($config);   // -> DatabaseInterface

// From command-line arguments (--driver, --host, --port, --user, --pass, --db, --ssl-mode, --dsn; --key=value form only)
$db = ConnectionFactory::fromArgv($argv);        // -> DatabaseInterface

// From a bare SQLite file path (the CLI's default argument shape) -> connects immediately
$db = ConnectionFactory::fromPath('app.sqlite');   // -> SqliteDatabase
```

### DSN format

```
driver://[user][:pass]@host[:port]/dbname[?query]
```

| Part     | Description                                                           |
|----------|-----------------------------------------------------------------------|
| driver   | `mysql`, `pgsql`, `sqlite`                                            |
| user     | Database username (URL-encoded via `rawurlencode()` if contains `@` or `:`) |
| pass     | Database password — URL-encoded if it contains special chars; never echoed |
| host     | Server hostname or IP; IPv6 addresses use brackets: `[::1]`           |
| port     | Server port (default varies by driver)                                |
| dbname   | Database name                                                         |
| query    | Optional query string — `ssl-mode=MODE` is parsed and stored in `ConnectionConfig.sslMode`, then applied as PDO driver options at connect time |

> **MySQL SSL** — `ssl-mode` is parsed from the query string (e.g. `?ssl-mode=require`) and stored in `ConnectionConfig.sslMode`; matching is case-insensitive and accepts the hyphenated/underscored spellings (`verify-ca` == `verify_ca`). It is **never** embedded in the MySQL DSN string (PDO mysql does not support `ssl-mode` as a DSN parameter). `MysqlDatabase::connect()`/`reconnect()` apply it via `sslDriverOptions()`:
>
> | Mode | Effect |
> |------|--------|
> | unconfigured | The effective default is `prefer` — `ConnectionFactory::fromDsn()` seeds `sslMode = 'prefer'` when the query string names no mode (src/Db/ConnectionFactory.php:151) and `fromArgv()`/`fromArgs()` apply `?? 'prefer'` (:238). So a plain `mysql://` DSN or flag set already rides the opportunistic-TLS row below; the empty cell applies only to a hand-built `ConnectionConfig`. |
> | `` (empty) / `disable` | Plain TCP, no TLS options. |
> | `allow` / `prefer` / `preferred` | Opportunistic TLS: `MYSQL_ATTR_SSL_CA=null`, `SSL_VERIFY_SERVER_CERT=false` — encrypts when the server supports it, verifies nothing (a downgraded connection is not reported). |
> | `require` / `required` / `verify_ca` / `verify_identity` | TLS enforced with `SSL_VERIFY_SERVER_CERT=true`. Because no client CA is configured, verification runs against the server's system CA trust store. |
> | anything else | Rejected with `InvalidArgumentException: Unknown MySQL ssl-mode` (from `MysqlDatabase::sslDriverOptions()`). |
>
> **Async policy (TLS gate)** — the ReactPHP transport (`ReactMysqlConnection`) builds a `mysql://` URI that carries no TLS material. Rather than let `--ssl-mode=require`/`verify_*` silently run background status/processlist fetches over plaintext, the app **refuses that transport** in any TLS-verifying mode (one predicate, `MysqlDatabase::requiresVerifiedTls()`, mirroring the sync-path table above): queries and table loads fall back to the synchronous TLS-speaking PDO connection, the admin live-refresh tick stands down, and both a one-time launcher notice (stderr, before the alt screen) and a standing in-app status line say so. The plaintext-by-agreement modes (empty / `disable` / `allow` / `prefer` / `preferred`) keep the async path exactly as before — opportunistic TLS there is the mode the caller chose.

> **PostgreSQL SSL** — the `sslmode=` DSN parameter is appended for pgsql connections when `sslMode` is set (`disable`, `allow`, `prefer`, `require`, `verify-ca`, `verify-full`; MySQL-flavoured spellings `required`/`verify_identity`/`verify_full` map onto it). Unknown or empty values omit the parameter and inherit libpq's default (`prefer`) — though via the factory paths an empty `sslMode` never arises anyway: `fromDsn()`/`fromArgv()` seed `prefer` (src/Db/ConnectionFactory.php:151, :238), so the parameter reaches the DSN explicitly. The async admin transport honours the same setting: `ReactPostgresConnection` threads the DSN's `sslmode` into PgAsync's `tls` parameter, and PgAsync refuses the connection (rather than downgrading to plaintext) when `require`/`verify-ca`/`verify-full` cannot negotiate TLS — so unlike the MySQL gate above, PostgreSQL needs no sync-only stand-down. Two parity notes: pgsql `require` means "encrypted, certificate unchecked" (libpq semantics), where MySQL `require` escalates to system-store verification; and pgasync's `verify-ca` does not actually verify the CA chain (only `verify-full` enables peer verification) — see `ReactPostgresConnection`'s class docblock.

### DSN parsing: `parse_url()` over manual `explode()`

`ConnectionFactory::fromDsn()` uses `parse_url()` (with a SQLite-specific regex fallback) instead of manual `explode('@'|':')` splitting. This correctly handles:
- Passwords containing `@` or `:` — URL-encode them (`p%40ss%3Dword` → `p@ss:word` via `rawurldecode()`)
- Passwordless users — `mysql://user@host/db` (no `:` required, user without password)
- IPv6 hosts — `mysql://u:p@[::1]:3306/db` (brackets stripped from host, port preserved)
- SQLite — handled via direct regex since `parse_url()` returns `false` for `sqlite:///path`


## Schema introspection

`SchemaBrowser` uses a strategy pattern based on `Flavor` to delegate schema introspection to the appropriate `SchemaProviderInterface` implementation:

```php
use SugarCraft\Sql\SchemaBrowser;
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\Flavor;

// The browser needs a DatabaseInterface (any driver impl) and the flavor
$config = ConnectionFactory::fromDsn('sqlite:///var/data/app.sqlite');  // -> ConnectionConfig (no connect)
$db = ConnectionFactory::fromConfig($config);                           // -> DatabaseInterface

$browser = SchemaBrowser::new($db, Flavor::Sqlite)->refresh();

foreach ($browser->tables as $table) {
    echo $table->name;
    foreach ($table->columns as $col) {
        echo $col->name, ' ', $col->type, $col->primaryKey ? ' PK' : '';
    }
}
```

Available providers:
- **`SqliteSchemaProvider`** — PRAGMA queries (`table_info`, `index_list`, `foreign_key_list`)
- **`MysqlSchemaProvider`** — `INFORMATION_SCHEMA` queries
- **`PostgresSchemaProvider`** — `pg_catalog` + `information_schema` queries

Schema value objects (`SchemaTable`, `SchemaColumn`, `SchemaIndex`, `SchemaForeignKey`) are all `readonly` classes with bare accessors.

## Query plan viewer

`ExplainView` uses a strategy pattern based on `Flavor` to delegate EXPLAIN parsing to the appropriate `ExplainProviderInterface` implementation:

```php
use SugarCraft\Sql\ExplainView;
use SugarCraft\Sql\Db\DatabaseInterface;

// run() takes a DatabaseInterface and detects the flavor/provider itself
$view = ExplainView::run($db, 'SELECT * FROM users JOIN orders ON users.id = orders.user_id');
echo $view->render();        // ANSI coloured tree
print_r($view->toArray());   // JSON-serialisable structure
```

Each detail line is classified by op type: `SEARCH` (cyan), `SCAN` (yellow), `USING` (green), `JOIN` (purple), `SUBQUERY` (pink), `COMPOUND` (orange).

Available providers:
- **`SqliteExplainProvider`** — `EXPLAIN QUERY PLAN`; raw rows only — the tree-prefix (`|--`, `` `-- ``) depth parsing happens in `ExplainView::depthFromDetail()`
- **`MysqlExplainProvider`** — `EXPLAIN` formatted rows with tag/parent/detail/indent
- **`PostgresExplainProvider`** — `EXPLAIN (ANALYZE, FORMAT JSON)`, parses JSON structure for tree hierarchy

> **EXPLAIN safety guard** — `ConnectionDetailTabs::explain()` (Connections page, MDL detail tab — the `[3]` shortcut that opens it is standalone-only; see the unreachable-handler note above) will not run `EXPLAIN` on a thread's query unless it is a single `SELECT` statement. Multi-statement queries (containing `;`) and any non-SELECT statement (INSERT/UPDATE/DELETE/DROP/etc.) are rejected before execution. This prevents accidental execution of write/cleanup statements when inspecting a thread's current query. Returns `null` when the query is ineligible or explain fails.

## Admin providers

`AdminProviderInterface` is the flavor-agnostic port for admin panel data. It exposes eight methods —
`flavor()`, `fetchStatusVariables()`, `fetchServerVariables()`, `fetchProcesslist()`, `maxConnections()`,
`statusVariablesTs()`, `wasReset()`, `refresh()` — returning structured data the admin UI renders for either flavor.
Providers are not built through a static factory: the app's `ServerContext` (MySQL) and `PostgresServerContext`
(PostgreSQL) construct and drive them.

```php
use SugarCraft\Sql\Admin\ServerContext;

$context = new ServerContext($db);   // picks the MySQL admin provider internally
$status  = $context->statusVariables();
$rows    = $context->processlist();
```

Available providers:
- **`MysqlAdminProvider`** — `SHOW GLOBAL STATUS`, `SHOW GLOBAL VARIABLES`, `SHOW ENGINE INNODB STATUS`, `SHOW PROCESSLIST`, `SHOW REPLICA STATUS` (graceful 1227 handling)
- **`PostgresAdminProvider`** — `pg_stat_database`, `pg_settings`, `pg_stat_activity`. Implements `checkAllMetrics()` with computed PostgreSQL metrics (connection usage, cache hit rate, transaction rates, throughput) and `checkConnectionUsage()` with threshold alerts. `serverInfo()` maps `pg_stat_database` fields (`numbackends`, `xact_commit`, `xact_rollback`, `blks_read`, `blks_hit`, `tup_returned`, `tup_fetched`, `tup_inserted`, `tup_updated`, `tup_deleted`, `max_connections`, `shared_buffers`).

## Result table

`ResultTable` renders a SQL result set with horizontal scrolling, JSON pretty-print, and a styled NULL token
(the horizontal-scroll viewer is the EXECUTED-query path — a table *browsed* from the sidebar renders through the sugar-table
grid, whose columns share a fixed per-pane budget and truncate instead of scrolling; run `SELECT * FROM …` for the scrollable
view):

```php
$table = ResultTable::fromRows($rows)
    ->withVisibleWidth(80)
    ->withJsonPretty(true)
    ->withNullToken('∅');

echo $table->render();       // ANSI coloured
echo $table->renderPlain();  // plain text for copy/export

if ($table->canScrollRight()) {
    $table = $table->scrollRight();
}
```

Columns auto-size to the widest value; cells exceeding `maxCellWidth` (default 40) are truncated with `…`. Array/object values are JSON-encoded with 2-space indent when `visibleWidth >= 80`, collapsed to a single line otherwise.

## Server Status page

`ServerStatusPage` displays a comprehensive overview of the MySQL/MariaDB server:

| Panel | Content |
|-------|---------|
| **Info Card** | Host, socket, port, MySQL version, uptime (formatted as duration + running-since timestamp) |
| **Features** | InnoDB, SSL, Fulltext, Events, Stored Programs, Partitioning, X Plugin — tristate: Yes/No/Unknown |
| **Directories** | datadir, tmpdir, log_error, pid_file |
| **SSL** | have_ssl, ssl_cipher, tls_version, ssl_ca, ssl_cert, ssl_key |
| **Replication** | Per-channel cards: Master Host/Port, IO/SQL Running state, Seconds Behind, Relay Log File/Pos. Gracefully shows distinct panels for each state: configured (channels returned), not configured (empty), permission denied (error 1227), or error. Multi-channel replication supported (MariaDB `SHOW ALL SLAVES STATUS` returns all channels as separate cards; MySQL 8+ uses `SHOW REPLICA STATUS`; MySQL 5.x uses `SHOW SLAVE STATUS`). |
| **Firewall** | MySQL Enterprise Firewall via `mysql_firewall_mode` status variable (ON/OFF); falls back to AUDIT plugin detection on managed/cloud instances. AWS RDS firewall is no longer detected via Aurora_lwm sentinel. |

```php
use SugarCraft\Sql\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Sql\Admin\ServerContextInterface;

$page = ServerStatusPage::new($context);
echo $page->render();
```

`ReplicaStatusProvider::fetchStatus()` returns `list<array<string, scalar>>` — all replica channels. `ReplicaStatusProvider::lastFetchKind()` returns a `ReplicaStatusKind` enum value (`Configured`, `NotConfigured`, `PermissionDenied`, `Error`) to distinguish the specific condition. Flavor-aware queries: MariaDB uses `SHOW ALL SLAVES STATUS` (multi-channel); MySQL 8+ uses `SHOW REPLICA STATUS`; MySQL 5.x uses `SHOW SLAVE STATUS`. Error 1227 (REPLICATION CLIENT privilege denied) is detected by both PDO error code and message pattern matching.

**Key bindings:**

| Key | Action |
|-----|--------|
| `[r]` | Refresh data |
| `[q]` | Return to the Tables browser |
| `[g]` | Open GTID mode selector dialog (MySQL ≥ 5.7.6 only) |

GTID dialog: press `[c]` to cycle through `OFF → OFF_PERMISSIVE → OFF_SECURE → ON_PERMISSIVE → ON`, then `[Enter]` to execute `SET @@GLOBAL.GTID_MODE = <mode>` or `[Esc]` to cancel. The dialog is only active when the server version is at least MySQL 5.7.6.

**Sidebar gauges:** The right-hand gauge panel displays five metrics — Connections (`threads_connected/max_connections`), Traffic (per-second bytes rate via Sampler delta), Key Efficiency (`Key_reads / (Key_reads + Key_read_requests)`), QPS (`Questions/Uptime`), and InnoDB Buffer Pool utilization. The Traffic gauge uses sampled per-second deltas from `ServerStatusSnapshotAdapter` bridging `ServerContextInterface` to `StatusSnapshotProviderInterface`, so the displayed ratio reflects actual throughput changes between poll cycles (baseline-corrected) rather than cumulative byte counts. Key-efficiency ratio of 0.0 means all key reads were served from memory; higher values indicate cache misses requiring disk I/O. There is no CPU gauge — MySQL exposes no CPU status variable; the former "CPU" gauge was a mislabeled connections ratio and has been removed.

## Performance Reports

The Performance Reports pages (`[5]` Query Stats / `[7]` Table Stats in admin) displays data from MySQL's `sys` schema views — grouped by category (problems, schema, IO, memory, etc.). Report queries are fully asynchronous: `validate()` acquires the connection handle and calls `Catalog::load()` (file I/O) but issues no query, then `App` dispatches `ReloadReportMsg` after the admin data tick, queuing the report query via `AdminQueryCache` for the next event-loop tick. This means the page never blocks on a slow `SELECT * FROM sys.x$*` query, matching the non-blocking behaviour of processlist and replica pages.

**Async flow:**
1. `ReportsPage::validate()` — acquires the `$context->connection()` handle (handle only, no query issued) and loads `Catalog` from `data/sys_reports.json` (file I/O, always sync, never blocks)
2. After admin status/variables data lands, `App` sends `ReloadReportMsg` to `ReportsPage`
3. `ReportsPage::update(ReloadReportMsg)` calls `loadCurrentReport()` — queries are issued through `AdminQueryCache` via `AsyncCachedConnection`, returning `null` immediately
4. `view()` sees `currentResult === null` and renders a loading spinner
5. On the next admin tick the cached result is available and `view()` renders the report grid

**Availability:** `AvailabilityChecker::discoverViews()` runs `SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'` asynchronously and caches the result. Reports whose views are missing on the server are filtered out with graceful degradation. Error handling catches `\Throwable` (not just `\PDOException`) because React/cached connections can surface non-PDO error types.

**Key bindings:**

| Key | Action |
|-----|--------|
| `j/k` or `↑/↓` | Navigate rows down/up |
| `h/l` or `←/→` | Previous/next category (wraps; triggers async load) |
| `[` / `]` | Previous/next report within the current category (wraps) |
| `,` / `.` | Previous/next column index (for future per-column unit cycling; `[c]` is the global unit toggle) |
| `r` | Refresh current report (async — queues a new query) |
| `x` | Build the current report as CSV (RFC-4180, formula-safe) into page state — no file is written |
| `c` | Toggle unit display for time/byte columns (global; `selectedColumnIndex` is tracked for future per-column targeting) |
| `q` | Return to the Tables browser |

> **Category/report navigation** — `h`/`l` and `[`/`]` keys traverse the category tree and the report list within each category respectively. Both wrap around at boundaries. Selecting a category or report triggers `loadCurrentReport()` asynchronously via `AdminQueryCache`, exactly as if the user clicked it in the tree.

> **No DB query in `validate()`** — The requirement for reports is that `validate()`/`view()`/`update()` never issue a synchronous `db->query()`. All DB access flows through `AdminQueryCache` → `Cmd::promise` → async drain tick. If the sys schema is unavailable, `Catalog::load()` still succeeds (it's file I/O); `AvailabilityChecker::discoverViews()` returns `[]` and `view()` shows the empty category tree with no blocking error.

## Performance Schema Setup

The Performance Schema Setup page (`[8]` in admin) provides a tabbed interface for configuring MySQL's `performance_schema`. It is version-aware and adapts its queries and editable fields to the detected MySQL version.

**EasySetupDetector:** The page uses `EasySetupDetector` (created via `EasySetupDetector::fromContext($context)` when not explicitly provided) to detect the current PS setup state as one of four values shown in the header badge:

| State | Badge | Meaning |
|-------|-------|---------|
| `fully` | green FULLY | All consumers enabled AND all instruments enabled+timed (TIMED-off → `custom`) |
| `default` | yellow DEFAULT | Setup matches MySQL default profile for the detected version |
| `custom` | blue CUSTOM | User has customized the PS configuration |
| `disabled` | red DISABLED | Performance Schema is inaccessible |

The detector is wired from `App::adminPage()` into the `PerfSchemaPage` constructor. If no detector is provided, `detectSetupState()` (which uses loaded instrument data only, without checking TIMED or consumers) serves as a fallback — less accurate but sufficient for backward compatibility.

**Version-gated default profiles (Appendix C):**

| | MySQL 5.6 | MySQL 5.7+ |
|---|---|---|
| Instruments | `wait/io/file/%`, `wait/io/table/%`, `wait/lock/table/sql/handler`, `statement/%`, `idle` | Same five (stage/% removed in 5.7) |
| Consumers | `events_statements_current`, `events_transactions_current`, `global_instrumentation`, `thread_instrumentation` | Same four + `statements_digest` |

**Version gating:**
- `setup_actors` (Actors tab) is skipped entirely on MySQL <5.6 — the table did not exist until 5.6
- `setup_objects` has an `ENABLED` column only on MySQL ≥5.6.3; on older versions the tab shows `TIMED` only
- Timer configuration (Options tab): on MySQL <8.0, timers are loaded from `setup_timers` and are editable via a fully-literal `UPDATE` generated by `SetupTimers::commitStatements()` (`SET TIMER_NAME = '<timer>' WHERE NAME = '<row>'` with values interpolated as escaped quoted literals — no `?` placeholders, unlike the `CommitPlanner` batch path); on MySQL ≥8.0, timers are loaded from `performance_timers` and are read-only (timer selection is fixed at server build time)

**Tabs:**
| Tab | Content |
|-----|---------|
| Easy Setup | Preset PS configurations — enable full PS, disable PS, reset to defaults (version-appropriate default sets) |
| Instruments | Collapsible tree of all PS instruments with indented display and tri-state badges on group nodes (`[x]` all-enabled, `[~]` mixed, `[ ]` all-disabled) |
| Consumers | List of event consumers with tri-state toggles |
| Actors | Host/user/role filter rules (not available on MySQL <5.6) |
| Objects | Object-level instrumentation rules (ENABLED column only ≥5.6.3) |
| Threads | List of server threads with INSTRUMENTED status (from `performance_schema.threads`) |
| Options | Timer assignments (mutable on <8.0, read-only on ≥8.0) |

**Key bindings:**

| Key | Action |
|-----|--------|
| `j/k` or `↑/↓` | Navigate rows up/down |
| `h/l` or `←/→` | Previous/next tab |
| `Space` or `Enter` | Toggle/select item (Instruments/Consumers/Actors/Objects tabs) |
| `[` / `]` / `.` | Easy Setup actions (enable full / disable / reset to defaults) — on the Easy Setup tab |
| `Tab` / `Shift+Tab` | Next/previous tab — standalone only: `App` consumes `Tab` for the pane cycle |
| `c` | Stage commit of pending changes (when dirty) — `y` fires the prepared statements, any other key cancels |
| `r` | Revert pending changes (when dirty) — `App` additionally drops the shared `AdminQueryCache` and re-fetches |
| `q` | Return to the Tables browser (`App` intercepts `q` before the page) |

> **Timer mutation safety** — on MySQL ≥8.0 the Options tab renders `performance_timers` as read-only instances. `SetupTimers::commitStatements()` returns `[]` for these because they carry `dirty=false`. Only timers loaded from `setup_timers` on <8.0 generate `UPDATE` statements.

> **Threads INSTRUMENTED** — the Threads tab reads the `INSTRUMENTED` column from `performance_schema.threads`. Per-thread toggling via `withInstrumented()` is tracked but the batch `UPDATE ... WHERE THREAD_ID IN (...)` commit is a planned follow-up.

> **Instrument RLIKE anchoring** — `UPDATE ... WHERE NAME RLIKE '...'` patterns are now anchored (`^name$`) and regex-escaped via `preg_quote()` so metacharacters like `.` `/` `(` `)` are matched literally. Backtick-wrapping (which caused the pattern to match literal backtick chars) is removed. The CommitPlanner generates fully parameterized statements (`sql` + `params` tuple structure) — no value string-interpolation.

> **Tree rendering** — the Instruments tab now renders an indented tree with group nodes (path prefixes like `wait/`) showing tri-state badges (`[x]`/`[~]`/`[ ]`) representing the aggregate enabled state of their subtrees. `InstrumentTree::flattenTree()` returns `[nodeOrInstrument, depth, isGroup]` triples consumed by the renderer. Group toggle cascade methods `setChildrenEnabled()`/`setChildrenTimed()` exist on `InstrumentTree` but are not yet wired to keyboard input (planned follow-up).

> **Hub-admin privileges** — modifying PS setup tables requires the `PROCESS` privilege on MySQL. `PerfSchemaPage::hasUpdatePrivilege()` tests this with a no-op `UPDATE ... SET ENABLED=ENABLED WHERE 1=0`. When the privilege is absent the page renders in read-only mode and all write actions are disabled.

## Alerting

`AlertManager` evaluates metrics against configurable thresholds and fires `Alert` value objects, which `AlertNotifier` renders as toast notifications via `sugar-toast`:

```php
use SugarCraft\Sql\Admin\Alerts\AlertManager;
use SugarCraft\Sql\Admin\Alerts\AlertThresholds;
use SugarCraft\Sql\Admin\Alerts\AlertNotifier;

$manager = AlertManager::new()
    ->withThresholds(AlertThresholds::default())
    ->withNotifier(AlertNotifier::withDefaults());

// Check connection counters and dispatch alerts
$alerts = $manager->checkConnectionUsage($counters);
foreach ($alerts as $alert) {
    $notifier = $notifier->notify($alert);
}

// Or combine check + dispatch in one call
['alerts' => $alerts, 'notifier' => $notifier] = $manager->checkAndDispatch($counters);
```

### Severity levels

`Severity` enum maps to `ToastType` for display:

| Severity | ToastType | Use case |
|----------|-----------|----------|
| `Info` | `ToastType::Info` | Informational notices |
| `Warning` | `ToastType::Warning` | Elevated metrics, non-critical |
| `Critical` | `ToastType::Error` | Threshold exceeded, action needed |

### Threshold presets

- **`AlertThresholds::new()`** — bare instance, all defaults
- **`AlertThresholds::default()`** — 60% warning / 80% critical / 5% aborted rate / 5s slow query / 50% thread running
- **`AlertThresholds::strict()`** — 50% warning / 70% critical / 1% aborted rate / 1s slow query / 30% thread running (production-sensitive)

### Watched metrics

| Metric | What it checks |
|--------|----------------|
| `connection_usage` | `threads_connected / max_connections` |
| `aborted_rate` | `aborted_connects / total_connections` |
| `thread_running` | `threads_running / max_connections` |
| `slow_query` | `long_query_time` server variable |
| `connection_errors` | `Connection_errors_total` status variable |
| `max_connections` | `threads_connected / max_connections` (alias for connection_usage) |

### DashboardPage integration

`AlertManager` integrates into the `DashboardPage` polling loop via `checkAlerts()`:

```php
// Inside DashboardPage's poll (cadence: the 1s admin tick, throttled to
// CacheTtl::DASHBOARD = 1.0s in handleAdminTick). checkAndDispatch returns
// ['alerts' => array<string,Alert>, 'notifier' => AlertNotifier]; the page
// folds the alerts into its own state — there is no footer badge, the
// toast surface IS the notification channel.
['alerts' => $alerts, 'notifier' => $notifier] = $manager->checkAndDispatch($counters);
```

**Alert dedup (state-transition only):** `checkAlerts()` maintains a `$breachedAlertKeys` array tracking which alert keys were breached at the last poll. It computes `array_diff_key` between current and previous breach keys and only fires a toast for newly-breached entries — a threshold that persists across poll ticks does **not** re-fire the toast. This prevents alert storms for continuously-breached thresholds (e.g., a stuck connection at 90% utilization). The `[a]` key dismisses all pending alerts and resets the breach-tracking state so the same alert can re-fire if the threshold is cleared and breached again. `AlertNotifier` is mute-safe by default — running without a toast factory is a silent no-op. The live predicate is `hasActiveAlert()` (there is no `hasAlerts()`).

### Performance Dashboard

`DashboardPage` renders a 3-column live monitoring view (Network / MySQL / InnoDB panels) updated every second via `ServerContext` polling (`AdminTickMsg` at 1.0s, throttled by `CacheTtl::DASHBOARD`). Each panel shows timeline sparklines, counter values, and meters.

**Architecture:**
- the rate denominator in `pollAndUpdateCells()` is true wall-clock since the last **committed snapshot** (`$elapsed = max(0.001, $now - $lastSnapshotAt)`), never the gate stamp — the `ReloadReportMsg` arm nulls only `lastPollAt` (a throttle bypass), so a poll arriving long after the last frame divides by the real gap. Only the very first poll (no previous snapshot) falls back, to `CacheTtl::DASHBOARD` (1.0s).
- Per-section widget lists are built once in the constructor via `buildSectionWidgetCache()` and cached in `$this->sectionWidgetCache`. Previously, `getWidgetsForSection()` called `WidgetRegistry::*()` on every render frame, causing repeated version checks.
- Three cell types render widgets: `TimeSeriesCell` (sparkline), `CounterCell` (K/M/G-scaled counter), `MeterCell` (round/level gauge with optional value readout).

**Tuple timeline rendering:** When a `Widget::compute()` returns an associative per-series array (e.g. `['Com_select' => 10.0, 'Com_insert' => 5.0]` from `MakeTuple`), `TimeSeriesCell::ingest()` uses `max($array)` to show the dominant series rather than summing unrelated counters. `CounterCell` still uses `array_sum()` since counters are additive by design.

**Meter readout:** Level-kind `MeterCell` widgets with a non-trivial format string (e.g. `'%d / %d'`) render a value/max readout after the gauge bar via `sprintf($format, (int)$value, (int)$max)`. The `Connections` level meter uses this to show `X / Y` connections.

**InnoDB buffer pool formula:** `InnoDBBufferPoolUsageBytes` uses the MySQL Workbench Appendix A formula: `(Innodb_buffer_pool_bytes_data / Innodb_page_size) / Innodb_buffer_pool_pages_total * 100`. This is more accurate than the older `(total - free) / total` page-count approximation and correctly handles partially-filled pages.

**InnoDB panel widgets (13 total):** Buffer Pool Read/Write Requests, Buffer Pool Usage (bytes-based), Disk Reads (not from pool), Row Lock Waits, Row Lock Time, Pages Flushed/Created/Read, Insert Buffer, Read Ahead, Redo Log Bytes Written, Redo Log Writes, Doublewrite Writes, InnoDB Disk Writes/Reads.

**Dashboard keys:**

| Key | Action |
|-----|--------|
| `p` | Pause/resume the 1-second auto-refresh |
| `r` | Reset all timeline/counter/meter history |
| `a` | Dismiss all pending alert toasts |

### Toast degradation

`AlertNotifier` is mute-safe by default. When no toast factory is provided, all `notify*()` calls are no-ops. This allows the alerting system to run in non-TUI contexts without errors:

```php
// Safe to call even without sugar-toast available
$notifier = AlertNotifier::new();  // muted by default
$notifier->notifyWarning('High memory');  // no-op

// Enable with a factory
$notifier = AlertNotifier::withDefaults(
    toastFactory: fn(): Toast => Toast::new(50)->withPosition(Position::TopRight)->withDuration(5.0),
    muted: false,
);
```

## Resilience

`MysqlDatabase` integrates resilience primitives to handle transient MySQL failures gracefully:

### Reconnect on connection loss

`ReconnectManager` detects MySQL error codes 2002 (Can't connect to local MySQL server), 2003 (Can't connect to MySQL server), and 2013 (Lost connection during query). When `MysqlDatabase::query()` encounters one of these, it returns `null` to signal the caller that a reconnect is needed:

```php
use SugarCraft\Sql\Db\ConnectionFactory;
use SugarCraft\Sql\Db\MysqlDatabase;
use SugarCraft\Sql\Admin\Resilience\ReconnectManager;

$config = ConnectionFactory::fromDsn('mysql://app:secret@db.internal:3306/shop');
$db = MysqlDatabase::connect($config);
$db->setReconnectManager(new ReconnectManager());
// On error 2002/2003/2013 the driver detaches the PDO handle, lets the
// manager retry the connect, and returns null — the caller should re-run
// the statement against the (possibly restored) connection, never treat
// null as an empty result set.
```

### Restart detection

`ServerContext::detectReset()` is the single authoritative owner of restart detection. It compares the current `Uptime` status variable against the last observed value after each **successful** `statusVariables()` fetch (a failed fetch keeps the previous verdict rather than inventing one); a lower uptime indicates a restart and sets `wasResetCache = true`. `Sampler` consumes this via `StatusSnapshotProviderInterface::wasReset()` (delegating to `ServerContext::wasReset()`), keeping restart detection logic centralized in one place.

`App::subscriptions()` declares a plain 1s admin tick (it also gates on the active pane and the TLS transport refusal — facts immutable per decision, safe to test at declaration time). The refresh throttle is applied **per fire** in `App::handleAdminTick()`: the elapsed window is measured against `AdminState::$lastFetchAt`, a public readonly property on the per-App state value object (carried via `withLastFetchAt()` — no static, so concurrent App instances cannot stomp each other's cadence), and compared to `CacheTtl::DASHBOARD` (1.0s, the Workbench dashboard rate). The guard is per-fire rather than declaration-time because `Program::reconcileSubscriptions()` diffs subscriptions by tick id and would lock whichever closure installed first; `AsyncOps::throttle()` returns a void callable that cannot be awaited as a Promise in the `Cmd::promise` flow, so an elapsed-time guard is used instead.

### Statement timeout

`StatementTimeout` enforces a wall-clock timeout on heavy report queries using `pcntl_alarm()`. If the timeout fires, it cancels the query via `KILL CONNECTION_ID()` and throws `StatementTimeoutException`:

```php
use SugarCraft\Sql\Admin\Resilience\StatementTimeout;
use SugarCraft\Sql\Admin\Resilience\StatementTimeoutException;

$timeout = new StatementTimeout(timeoutSeconds: 60);
try {
    $stmt = $pdo->prepare('SELECT * FROM big_table WHERE conditions');
    $timeout->execute($stmt);
} catch (StatementTimeoutException) {
    echo 'Query exceeded 60s timeout and was cancelled';
}
```

When `pcntl` is unavailable, `StatementTimeout::execute()` runs the statement with no enforcement — silently. The degraded state is decided once in the constructor (`function_exists` probes), and neither construction nor execution logs anything; a host without pcntl has simply-uncapped queries, and the caller cannot tell the difference from the return value.

## Status-Snapshot History (default-on in the launcher)

The launcher records the admin poll snapshots to SQLite — on by default, opt out with `--no-history`. Default location is
`$HOME/.candy-sql/history.db` (created with dir 0700 / file 0600; if `HOME` is unset the recorder stays disabled);
`--history=<path>` relocates it. The recorded unit is a `StatusSnapshot` (a timestamped map of server status variables) —
SQL statement text is never persisted. The in-memory query history stores SQL statement TEXT — trimmed, with consecutive
repeats de-duped at the record site (`App::runQuery()`, src/App.php:479-483; the second record site at :575-578 shares the law) —
never result rows, and it is session-scoped only.
The store speaks through the core `SQLite3` class, so `ext-sqlite3` is a hard requirement alongside `ext-pdo_sqlite`. Faults
degrade through two channels, never a crash: at launch a corrupt or unwritable file warns loudly on stderr (`history recording
DISABLED`, bin/candy-sql:86-101) and the session runs history-less; a persist that fails mid-session drops to the in-app query
log instead (`HistoryRecorder::record()` catches the store failure and logs it via `QueryLogger`, so the live dashboard and
in-memory snapshots stay authoritative — src/Admin/History/HistoryRecorder.php:43-46).

```php
use SugarCraft\Sql\Admin\History\SqliteHistoryStore;
use SugarCraft\Sql\Admin\History\HistoryRecorder;
use SugarCraft\Sql\Admin\History\HistoryQuery;

$historyPath = sys_get_temp_dir() . '/candy-sql-doc-history-' . getmypid() . '.sqlite';
$store    = new SqliteHistoryStore($historyPath);  // WAL, busy timeout 5s
$recorder = new HistoryRecorder($store);

// One snapshot per admin poll tick; the recorder never writes on its own initiative
$recorder->record(new \SugarCraft\Sql\Admin\StatusSnapshot(['Queries' => '4242'], microtime(true)));

// Read historical patterns
$query     = new HistoryQuery($store);
$snapshots = $query->querySince(microtime(true) - 3600.0);
$qps        = $query->rate('Queries', microtime(true) - 3600.0, microtime(true)); // ?float per-second

// Pruning is manual
$removed = $store->prune(new DateTimeImmutable('-30 days'));

$store->close();
foreach (glob($historyPath . '*') ?: [] as $residue) {  // db + WAL/SHM files
    unlink($residue);
}
unset($snapshots, $qps, $removed);
```

## Accessor deprecation (2026-10-24)

The house convention is bare accessors (`$user->name()`, never `$user->getName()`).
The public `get`-prefixed accessors below were renamed to their bare twins and the
old names remain available as one-line BC forwarders, each carrying a
`@deprecated` docblock marked `REMOVED-IN: 2.0.0`. New code must call the bare
name; the forwarders exist so pre-2.0 callers keep working unchanged.

| Namespace | Class | Deprecated | Use instead |
| --- | --- | --- | --- |
| `Admin` | `AdminQueryCache` | `getStatusVariables()` | `statusVariables()` |
| `Admin` | `AdminQueryCache` | `getServerVariables()` | `serverVariables()` |
| `Admin` | `QueryLogger` | `getEntries()` | `entries()` |
| `Admin` | `QueryLogger` | `getDisplayLines()` | `displayLines()` |
| `Admin` | `StatusSnapshot` | `getInt()` | `int()` |
| `Admin` | `StatusSnapshot` | `getFloat()` | `float()` |
| `Admin\Connections` | `ConnectionsPage` | `getTable()` | `table()` |
| `Admin\Connections` | `ConnectionDetailTabs` | `getDetails()` | `details()` |
| `Admin\Connections` | `ConnectionDetailTabs` | `getAttributes()` | `attributes()` |
| `Admin\Connections` | `ConnectionDetailTabs` | `getMdlLocks()` | `mdlLocks()` |
| `Admin\Connections` | `ConnectionDetailTabs` | `getThreadStack()` | `threadStack()` |
| `Admin\Connections` | `ConnectionDetailTabs` | `getExplain()` | `explain()` |
| `Admin\History` | `HistoryQuery` | `getRate()` | `rate()` |
| `Admin\PerfSchema` | `SetupActors` | `getChangeType()` | `changeType()` |
| `Admin\PerfSchema` | `SetupObjects` | `getChangeType()` | `changeType()` |
| `Admin\PerfSchema` | `SetupTimers` | `getChangeType()` | `changeType()` |
| `Admin\Variables` | `VariableEditor` | `getEditPreview()` | `editPreview()` |
| `Db` | `ReconnectException` | `getOriginal()` | `original()` |
| `Explain` | `MysqlExplainProvider` | `getDriverName()` | `driverName()` |
| `Explain` | `PostgresExplainProvider` | `getDriverName()` | `driverName()` |
| `Explain` | `SqliteExplainProvider` | `getDriverName()` | `driverName()` |
| `(root)` | `Renderer` | `getTerminalSize()` | `terminalSize()` |

The 20th census member, `Explain\ExplainProviderInterface::getDriverName()`, is an
interface declaration, not a class accessor: it was renamed to `driverName()`
outright (no forwarder is possible on an interface, and the repo-wide consumer
census found no implementations outside the lib — the three providers above are
its only implementors). `tests/AccessorDeprecationTest.php` pins every row, the
src census, and the table's agreement with it.

## Internationalization

`src/Lang.php` is a thin facade over the shared sugar-core translation registry with the `sql` namespace baked in, and `lang/` ships 16 locales — but the translated surface is currently one message: the `database.no_file` SQLite refusal (`src/Database.php:34`, `src/Db/SqliteDatabase.php:32`); everything else the UI prints is untranslated English.

## Known limitations

Pre-1.0 gaps to be aware of, mostly on the async (ReactPHP) query path:

- **No connection pooling.** One async connection per flavor+DSN+user is
  cached and reused (`AdminQueryCache::connection()`); concurrent admin
  queries are serialized onto that single connection rather than spread
  across a pool.
- **No transaction support on async connections.** `ReactMysqlConnection` /
  `ReactPostgresConnection` execute single statements only —
  `BEGIN`/`COMMIT` spanning multiple `query()` calls is not coordinated, and
  the synchronous PDO path does not expose a transaction API either.
- **No prepared-statement cache.** Every query is sent as raw SQL text;
  repeated statements are re-parsed by the server each time.
- **Async cancellation is cooperative and driver-dependent.**
  `AsyncConnection::query()` accepts an optional sugar-async
  `CancellationToken`; cancelling it (or hitting the configurable per-query
  timeout, default 30s, see `QueryTimeout`) rejects the caller's promise
  immediately and drops a late result. What actually stops **server-side**
  differs per driver:
  - *MySQL* (`ReactMysqlConnection`): the wrapper learns its connection's
    thread id via `SELECT CONNECTION_ID()` (dispatched as the connection's
    first command) and, on cancel or timeout, issues `KILL QUERY <id>` on a
    separate short-lived connection. Until the thread id has resolved — or if
    the `PROCESS`/owner privilege for `KILL` is missing — cancellation is
    client-side only and the server finishes the statement.
  - *PostgreSQL* (`ReactPostgresConnection`): cancellation disposes the
    underlying PgAsync subscription, which sends a wire-protocol
    `CancelRequest` (the `pg_cancel_backend()` equivalent) on a separate
    socket. This is genuinely server-side for both explicit cancels and
    timeouts, subject to the usual PostgreSQL caveat that a cancel request is
    advisory — the backend interrupts the statement at its next safe point.
- **Cancellation is library-level, not a UI key.** All of the above describes
  the `AsyncConnection` API. The TUI's query pane does not currently thread a
  token into its dispatch: an in-flight query runs to completion or until the
  per-query timeout (`QueryTimeout`, default 30 s) rejects its promise, and
  `Ctrl+C` quits the app rather than cancelling the statement.
- **Query-pane results are buffered in full.** A `SELECT` executed from the
  editor materialises every returned row into the in-process result table;
  only the schema-browse paths cap themselves (`PreviewQuery` appends
  `LIMIT 100`). Project what you need on huge tables.
- **The EXPLAIN guard restricts shape, not side effects.** The Connections
  MDL-tab guard (see *EXPLAIN safety guard* above) only runs `EXPLAIN` on
  single `SELECT` statements, which stops accidental writes — but MySQL can
  still const-evaluate what the statement contains: `EXPLAIN SELECT
  SLEEP(60)` sleeps, a user-defined function in the projection runs, and
  `INTO OUTFILE`/`INTO DUMPFILE` clauses reach the filesystem. Inspecting a
  hostile thread's query text is not a neutral act.
- **`--history=<path>` writes wherever you point it.** The flag accepts any
  path and overwrites an existing file at that path; the owner-only modes
  (dir 0700 / file 0600) apply to what the recorder creates, not to what it
  replaces. The flag is a user-owned CLI choice — do not pass a path you do
  not own the contents of.
- **Reconnect automation is MySQL-only.** `ReconnectManager` folds the
  2002/2003/2013 error codes for MySQL sessions; a PostgreSQL or SQLite
  handle that drops stays dropped until the process relaunches.
- **One connection per process.** The TUI runs on a single pooled
  connection; `DatabaseInterface::databases()` has no UI caller, and admin
  fetches, queries, and page drains all serialise onto that one handle.
- **Sync and async runs behave differently on success.** A synchronous
  (SQLite/TLS-verified MySQL) run clears the editor once results land, while
  an async run preserves the drafted SQL so it can be edited and re-run.
- **There is no in-app help pane.** The help surfaces are `--help` and this
  README; nothing inside the TUI renders a key reference beyond the per-pane
  footer legends.

The `HistoryRecorder` implements `StatusSnapshotProviderInterface`, so it slots into the existing polling loop without coupling to the UI. The `SqliteHistoryStore` uses WAL mode for safe concurrent reads during writes.

## Demos

### SQL query execution

![play](.vhs/play.gif)

## DatabaseInterface

`App` depends on `DatabaseInterface` rather than a concrete PDO/SQLite implementation. This decouples the UI from the database driver, enabling MySQL and Postgres support without changing application logic.

The interface defines 14 methods:

| Method | Description |
|--------|-------------|
| `tables()` | List all tables/views |
| `rows()` | Fetch rows from a table |
| `query()` | Execute a SQL query; returns `list<array<string,mixed>>|null` — `null` signals a reconnectable connection error (caller should retry) |
| `lastInsertId()` | Return the last insert ID |
| `quote()` | Quote a string for safe SQL |
| `exec()` | Execute SQL without results |
| `close()` | Close the connection |
| `serverVersion()` | Get database server version |
| `driverName()` | Get the driver name (e.g., `sqlite`, `mysql`) |
| `ping()` | Check connection is alive |
| `databases()` | List available databases |
| `prepare()` | Prepare a SQL statement; returns `PreparedStatementInterface|null` — a driver-neutral wrapper around PDOStatement |
| `dsn()` | The DSN string this connection was opened with |
| `username()` | The user the connection authenticated as |

### Deprecated: `Database` class

`src/Database.php` is deprecated — it is a thin alias to `SqliteDatabase`. New code should use `DatabaseInterface` directly:

```php
use SugarCraft\Sql\Db\DatabaseInterface;

// Type-hint against the interface for driver-agnostic code
function processDb(DatabaseInterface $db): void { /* works with any driver */ }
```

### PreparedStatementInterface

`DatabaseInterface::prepare()` returns a `PreparedStatementInterface` — a driver-neutral wrapper around the native PDOStatement. This keeps caller code free of driver-specific statement types:

```php
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\PreparedStatementInterface;

function runQuery(DatabaseInterface $db, string $sql, array $params = []): array
{
    $stmt = $db->prepare($sql);
    if ($stmt === null) {
        return [];  // connection error — caller should reconnect and retry
    }
    $stmt->execute($params);
    return $stmt->fetchAll();
}
```

The interface exposes five methods:

| Method | Description |
|--------|-------------|
| `execute(?array $params)` | Execute with bound parameters; returns `bool` |
| `fetch()` | Fetch next row; returns `array<string,mixed>|false` |
| `fetchAll()` | Fetch all rows; returns `list<array<string,mixed>>` |
| `rowCount()` | Rows affected by last query |
| `closeCursor()` | Close cursor, enabling re-execution |

All three database implementations (`MysqlDatabase`, `PostgresDatabase`, `SqliteDatabase`) now wrap their PDOStatement in `PdoPreparedStatement` before returning from `prepare()`.

### Exporters

`Db\Export\CsvExporter` and `Db\Export\SqlExporter` provide driver-agnostic export to file or string:

```php
use SugarCraft\Sql\Db\Export\CsvExporter;
use SugarCraft\Sql\Db\Export\SqlExporter;

(new CsvExporter($db))->exportCsv('/tmp/users.csv', 'users');   // returns void, writes the file
(new SqlExporter($db))->exportSql('/tmp/dump.sql');              // returns void; dumps ALL tables, not just one
$csvString = (new CsvExporter($db))->exportReportResultsToString($columns, $rows);
```

#### CsvExporter

- **`exportCsv(path, table)`** — writes table rows to a CSV file (RFC-4180 compliant)
- **`exportReportResults(path, columns, rows)`** — writes arbitrary result set to a CSV file
- **`exportReportResultsToString(columns, rows)`** — returns CSV as a string (used by ReportsPage)
- **`importCsv(path, table)`** — imports a CSV file into a table (backtick-quoted column names)

**Formula injection guard** — headers and cell values starting with `=`, `+`, `-`, `@`, tab (`\t`), or carriage return (`\r`) are prefixed with `'` to prevent spreadsheet applications from interpreting them as formulas. Leading spaces are trimmed before the guard check so `  =SUM(...)` is also protected.

**Column detection** — uses `SELECT * FROM table LIMIT 0` followed by `SELECT * FROM table LIMIT 1` to extract column names driver-neutrally. Does not use SQLite PRAGMA queries.

**Limitation** — empty tables (0 rows) cannot have their columns detected driver-neutrally; exporting an empty table produces a blank file.

#### SqlExporter

- **`exportSql(path)`** — writes all tables as INSERT statements to a SQL dump file

**No CREATE TABLE** — the full CREATE TABLE statement requires driver-specific queries (`sqlite_master` for SQLite, `SHOW CREATE TABLE` for MySQL), which are not driver-neutral. The INSERT data is the primary value for data portability.

**Quoting** — uses `$db->quote()` for strings (returns a complete quoted literal); numbers are cast to string unquoted. No double-quoting: `db::quote()` already returns a complete quoted literal and must not be wrapped in extra quotes.

**Column detection** — uses `SELECT * FROM table LIMIT 1` to extract column names driver-neutrally.

To add MySQL or Postgres support, implement `DatabaseInterface` and pass your implementation to `App::builder()->withDb($yourImpl)->build()`.

## Test

```bash
composer install
vendor/bin/phpunit
```

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

Design antecedent: [`jorgerojas26/lazysql`](https://github.com/jorgerojas26/lazysql); dashboard pages follow the MySQL Workbench status/dashboard spec.
