<img src=".assets/icon.png" alt="candy-query" width="160" align="right">

# CandyQuery

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-query)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-query)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-query?label=packagist)](https://packagist.org/packages/sugarcraft/candy-query)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/play.gif)

Terminal SQLite browser on the SugarCraft stack — port of [`jorgerojas26/lazysql`](https://github.com/jorgerojas26/lazysql), SQLite-only at v1.

```bash
composer require sugarcraft/candy-query
```

## CLI usage

```bash
# SQLite via bare path (inferred as Flavor::Sqlite)
bin/candy-query app.sqlite

# Any DSN — MySQL, PostgreSQL, SQLite, SQLSRV
bin/candy-query --dsn mysql://user:pass@localhost:3306/dbname
bin/candy-query --dsn pgsql://user:pass@localhost:5432/dbname
bin/candy-query --dsn sqlsrv://localhost/dbname

# Explicit SQLite path via DSN
bin/candy-query --dsn sqlite:///absolute/path/to/db.sqlite
```

`App::start(database, ?Flavor $flavor = Flavor::Sqlite)` accepts an optional `$flavor` to override auto-detection.

## Keys

| Key                | Action                                          |
|--------------------|-------------------------------------------------|
| `Tab`              | Switch pane (Tables → Rows → Query)             |
| `↑/↓` or `j/k`     | Move cursor in active list pane                 |
| `Enter` / `Space`  | Load the highlighted table into the rows pane   |
| `Ctrl+R`           | Run the SQL in the query pane                   |
| `Backspace`        | Delete last character (query pane)              |
| `q` / `Esc`        | Quit (except inside the query pane — `q` types) |

### Admin Dashboard Keys

| Key                | Action                                          |
|--------------------|-------------------------------------------------|
| `[p]`              | Pause/resume auto-refresh (Dashboard)           |
| `[r]`              | Reset counters (Dashboard) / Refresh processlist (Connections) |
| `[j/k]`            | Navigate rows down/up (Variables, Connections, Reports) |
| `[↑/↓]`            | Navigate rows down/up — alias for j/k on all list pages |
| `[e]`              | Edit selected variable (Variables page)         |
| `[w]`              | Toggle read/write filter (Variables page)       |
| `[s]`              | Focus search input (Variables page)              |
| `[tab]`            | Toggle Status/System tab (Variables page) / Cycle detail tabs (Connections) |
| `[f]`              | Toggle hide-sleeping filter (Connections page)  |
| `[1]`              | Switch to Details detail tab (Connections page) |
| `[2]`              | Switch to Attributes detail tab (Connections page) |
| `[3]`              | Switch to MDL detail tab (Connections page)    |
| `[c]`              | Commit pending changes (PerfSchema page)        |
| `[x]`              | Export report to CSV (Reports page)             |
| `[t]`              | Toggle column unit formatting (Reports page)    |
| `[a]`              | Dismiss all pending alerts (Dashboard)          |
| `1` – `8`          | Switch admin page (see digit map below)         |

> **Key routing** — Admin keys use a two-tier model. App-level keys (`1–9`, `q`, `j/k`, `p`, `r`) are intercepted by `App::handleAdminKey()` first and do **not** reach the page. All other keys (Tab, Space, `a`, `w`, `s`, `e`, etc.) are delegated to the active page's `update()` method, so each page can respond to its own navigation and editing keys independently.

### Admin Page Digit Map

The admin sidebar is split into two sections (Management, then Performance). Digits `1`–`8` select pages in display order:

| Digit | Management Section | Digit | Performance Section |
|-------|--------------------|-------|----------------------|
| `1`   | Process List       | `5`   | Dashboard            |
| `2`   | Variables          | `6`   | Table Stats          |
| `3`   | Status             | `7`   | Performance Schema   |
| `4`   | Query Stats        | `8`   | Debug                |

Digit `4` selects **Query Stats** (not Dashboard); digit `7` selects **Performance Schema** (not Table Stats). Display order and digit selection are both derived from `AdminPane::orderedCases()` — any code needing the mapping should call that method rather than hard-coding indices.
>
> **Page state survival** — Admin pages (Variables, Dashboard, Connections, etc.) preserve their in-memory state (active tab, cursor position, pending edits) across the 3-second poll-tick refresh cycle. Fresh server data is read from the shared `AdminQueryCache` on each render; only switching panes (`1–9`, `q`, `j/k`) rebuilds the page from scratch.

## Architecture

| File              | Role                                                                                          |
|-------------------|----------------------------------------------------------------------------------------------|
| `ConnectionConfig`| Readonly value object: driver, host, port, user, pass, dbname, sslMode, dsn. Pass never echoed. |
| `ConnectionFactory`| Static factory: `fromDsn()`, `fromConfig()`, `fromArgv()`. Builds configured connections.    |
| `Flavor`          | Enum: `MySQL`, `MariaDB`, `Percona`, `Postgres`, `Sqlite`. Used to identify database flavor. `Flavor::detectFromDriver()` detects flavor from a PDO driver name + optional version string. |
| `Version`         | Parser for server version strings. Handles MariaDB `5.5.5-` prefix. `isAtLeast(Version)` compares versions. |
| `Database`        | ⚠️ Deprecated thin alias to `SqliteDatabase`. Use `DatabaseInterface` for driver-agnostic code.   |
| `MysqlDatabase`   | `DatabaseInterface` implementation via PDO `mysql`. Implements `serverVersion()`, `driverName()`, `ping()`, `databases()`. |
| `PostgresDatabase`| `DatabaseInterface` implementation via PDO `pgsql`. Implements `serverVersion()`, `driverName()`, `ping()`, `databases()`. |
| `PreparedStatementInterface` | Driver-neutral prepared statement interface: `execute()`, `fetch()`, `fetchAll()`, `rowCount()`, `closeCursor()`. Abstracts PDOStatement so callers work with a uniform type across drivers. |
| `PdoPreparedStatement` | `PreparedStatementInterface` via PDOStatement. Wraps any PDO driver statement in the neutral interface. All implementations (`MysqlDatabase`, `PostgresDatabase`, `SqliteDatabase`) return this wrapper from `prepare()`. |
| `Pane`            | Enum for pane focus + `next()`.                                                              |
| `App` (Model)      | Tables list, rows pane, in-progress SQL editor buffer, error string, status string.         |
| `Renderer`        | Three rounded-border panes — tables, rows, query — with the focused pane getting a brighter accent. |
| `SchemaBrowser`   | Schema introspection via strategy pattern — delegates to driver-specific `SchemaProviderInterface` implementation based on `Flavor`. |
| `SchemaProviderInterface` | Interface for driver-specific schema introspection. Implement `tables()`, `columns()`, `indexes()`, `foreignKeys()`. |
| `SqliteSchemaProvider` | `SchemaProviderInterface` via PRAGMA queries (tables, columns, indexes, foreign keys). |
| `MysqlSchemaProvider` | `SchemaProviderInterface` via `INFORMATION_SCHEMA` queries. |
| `PostgresSchemaProvider` | `SchemaProviderInterface` via `pg_catalog` + `information_schema` queries. |
| `ResultPager`     | Cursor-based pagination for SQL result sets. Immutable + fluent `nextPage()`/`prevPage()`. |
| `CellEditor`       | Cell-level UPDATE by primary-key identity. `updateCell()`, `updateRow()`, `readCell()`.     |
| `SnippetStore`   | File-backed JSON store for named SQL snippets. Immutable + fluent `add()`/`delete()`/`find()`/`search()`. Persists to `/tmp/candy-query-snippets.json`. |
| `ExplainView`     | Renders `EXPLAIN` output as a colour-coded ANSI tree. Uses strategy pattern — delegates to driver-specific `ExplainProviderInterface` based on `Flavor`. |
| `ExplainProviderInterface` | Interface for driver-specific EXPLAIN parsing. Implement `explain(pdo, sql)` returning a list of explain rows. |
| `SqliteExplainProvider` | `ExplainProviderInterface` via `EXPLAIN QUERY PLAN`. Parses tree prefixes (`|--`, `` `-- ``) for depth. |
| `MysqlExplainProvider` | `ExplainProviderInterface` via `EXPLAIN`. Returns `EXPLAIN` formatted rows with tag/parent/detail/indent. |
| `PostgresExplainProvider` | `ExplainProviderInterface` via `EXPLAIN (ANALYZE, FORMAT JSON)`. Parses JSON structure for tree hierarchy. |
| `AdminProviderInterface` | Flavor-agnostic interface for admin operations: `dashboard()`, `connections()`, `serverInfo()`. Bridges to `ServerContextInterface` and `ServerContext`. |
| `MysqlAdminProvider` | `AdminProviderInterface` via MySQL `SHOW GLOBAL STATUS/VARIABLES`, `SHOW ENGINE INNODB STATUS`, `SHOW REPLICA STATUS`, and `fetchProcesslist()` (prefers Performance Schema `performance_schema.threads` with graceful fallback to `SHOW FULL PROCESSLIST` on permission errors). |
| `PostgresAdminProvider` | `AdminProviderInterface` via `pg_stat_database`, `pg_settings`, `pg_stat_activity`. Dashboard/connections implemented via `PostgresWidgetCatalog`. |
| `PostgresWidgetCatalog` | Provides `io()` (10 widgets: tuple metrics) and `cache()` (4 widgets: Shared Buffers) panels. Includes `parseSharedBuffers()` for byte conversion. |
| `ResultTable`    | Renders SQL result sets with horizontal scrolling, JSON pretty-print (2-space indent), styled NULL token, and column auto-sizing. `scrollLeft()`/`scrollRight()` builders. |
| `ServerStatusPage` | 2-column admin page: info/features/directories/SSL/replication/firewall panels on left, `SidebarGaugeSet` on right. Gauges poll ServerContext and optional Sampler for rate calculations. `r` refresh, `q` quit. |
| `ServerInfoCard`    | Info card with host, socket, port, version, uptime (computed to running-since). |
| `VariablesPage`     | Dual-tab (Status/System) variable browser with category tree, search filtering, keyboard nav (j/k/w/s/tab/e/q), and inline edit via VariableEditor. Mirrors `charmbracelet/lazysql` VariablesPage. |
| `VariableEditor`    | Inline editor for MySQL variables via `SET GLOBAL` / `SET PERSIST` / `SET GLOBAL PERSIST`. Uses prepared statements, handles errors 1142/1227/3680. Mirrors `mysql-workbench wb_admin_variable_editor`. |
| `ConnectionsPage`  | Processlist browser with selection navigation (j/k/↑/↓), detail tab cycling (Tab/1/2/3), hide-sleeping filter (f), and async refresh (r) via `Cmd::send`. Mirrors `charmbracelet/lazysql` connections page. |
| `ConnectionFilters` | Immutable filter config: hide-sleeping, hide-background, skip-full-info, refresh-rate. All fields are readonly with paired `$Set` sentinels. |
| `ConnectionCounters` | Connection metrics from `SHOW GLOBAL STATUS`: threads-connected/running/cached, connections, aborted-connects, connection-errors. Computes `connectionUsageRatio()` lazily (0.0–1.0). |
| `ConnectionDetailTabs` | Three detail tabs (Details/Attributes/MDL) per processlist thread. Details from `performance_schema.threads`; Attributes from `session_connect_attrs`; MDL from `performance_schema.metadata_locks` with graceful fallback to `information_schema.metadata_lock_info`. Gracefully returns `null` on permission errors (1142/1146/1227). |
| `ProcesslistProvider` | Fetches processlist via PS (`performance_schema.threads` + `session_connect_attrs`) with fallback to `SHOW FULL PROCESSLIST` on permission errors. Memoized via `cachedFilteredProcesslist` in `ConnectionsPage` to avoid 2–3× fetch per render. |
| `ReplicaStatusProvider` | Fetches replica status via `SHOW REPLICA STATUS` (MySQL 8+) or `SHOW SLAVE STATUS` (MySQL 5.x/MariaDB), graceful 1227 handling. |
| `SidebarGauge` | Single metric gauge with threshold coloring (green/yellow/red). CPU uses circular GaugeCircle; others use horizontal Gauge. |
| `SidebarGaugeSet` | Collection of 6 gauges: CPU (optional), Connections, Traffic, Key Efficiency, QPS, InnoDB. Polls ServerContext and optional `Sampler` for rate calculations. Traffic gauge uses Sampler delta for baseline-corrected ratio. |
| `VariableMetadata` | Immutable descriptor: name, description, editable flag, group memberships. Single MySQL system variable. |
| `Catalog` | Loads `data/variable_metadata.json` (73 variables, 16 groups). Provides `get()`, `all()`, `byGroup()`, `groups()`, `isEditable()`. |
| `Reports\Catalog` | Loads `data/sys_reports.json` (report widget definitions). Provides `get()`, `all()`, `byCategory()`, `categories()`. Categories are sorted by a curated `CATEGORY_ORDER` constant (problems first, matching MySQL Workbench Appendix B) rather than alphabetically. Unknown categories fall through to alphabetical ordering after the curated set. Uses `ColumnType::tryFrom()` to gracefully handle unknown column type strings rather than throwing a fatal. |
| `ReportsPage` | Performance Reports admin page: left category/report tree + right sortable/exportable grid. `validate()` only loads `Catalog` (file I/O) — no DB queries on the render path. Navigation methods `withSelectPrevCategory()` / `withSelectNextCategory()` / `withSelectPrevReport()` / `withSelectNextReport()` cycle through the catalog with wrap-around. `selectedColumnIndex` tracks the focused column for unit display targeting (future work). Footer shows keybindings `[j/k] nav rows  [h/l] category  [/] report  [c] unit toggle  [q] quit`. |
| `ReportRunner` | Executes `SELECT * FROM sys.<view>` for report views. Uses prepared statements with backtick-quoted view names. `run()` applies time/byte unit formatting; `runRaw()` returns unformatted values. |
| `AvailabilityChecker` | Checks which sys schema views are available via `SHOW FULL TABLES FROM sys WHERE Table_type='VIEW'`. Caches results in-memory. `discoverViews()` catches `\Throwable` (not just `\PDOException`) because React/cached connections can surface non-PDO errors. |
| `ReloadReportMsg` | Message dispatched by `App` after `AdminDataLoadedMsg` to trigger async report loading. `ReportsPage::update()` handles this by calling `loadCurrentReport()` which queues the query via `CachedConnection` for the next admin tick. |
| `Calc\InnoDBBufferPoolUsage` | Computes buffer pool usage percentage: `(total - free) / total * 100` from `Innodb_buffer_pool_pages_total/free`. Mirrors MySQL Workbench sidebar gauge formula. |
| `Calc\TableOpenCacheHitRate` | Computes Table Open Cache hit ratio: `hits / (hits + misses) * 100` from `Table_open_cache_hits/misses`. Mirrors MySQL Workbench dashboard expression. |
| `ReconnectManager` | Detects MySQL errors 2002/2003/2013 (connection lost), stores `ConnectionConfig`, and retries via `attemptReconnect()`. Throws `ReconnectException` on failure. |
| `ReconnectException` | Exception thrown when reconnection fails after a MySQL connection error. |
| `StatementTimeout` | Wraps `PDOStatement::execute()` with a wall-clock timeout via `pcntl_alarm()`. Degrades gracefully (logs warning, no timeout) when pcntl is unavailable. Throws `StatementTimeoutException`. |
| `StatementTimeoutException` | Thrown when a statement exceeds its wall-clock timeout and is cancelled via `KILL CONNECTION_ID()`. |
| `Severity` | Enum: `Info`, `Warning`, `Critical`. Maps to `ToastType` via `toToastType()` — `Info→Info`, `Warning→Warning`, `Critical→Error`. |
| `Alert` | Immutable alert value object: severity, metric, message, value, threshold, firedAt. Factory helpers: `::warning()`, `::critical()`, `::info()`. `toToastMessage()` formats as `"metric: message (X% > Y%)"`. |
| `AlertThresholds` | Immutable threshold configuration with fluent `with*()` builders. Presets: `::new()` (bare), `::default()` (60%/80%), `::strict()` (50%/70%). Watches connection usage, aborted rate, slow query time, thread running, connection errors. |
| `AlertNotifier` | Toast notification dispatcher. Mute-safe by default (no factory = all calls no-op). `::withDefaults()` bootstraps a standard factory. `notify(Alert)`, `notifyWarning()`, `notifyCritical()`, `notifyInfo()`, `view()` composites toast over a background viewport. |
| `AlertManager` | Stateless alert checker. `checkConnectionUsage(ConnectionCounters)` and `checkAllMetrics(statusVariables, serverVariables)` return `array<string, Alert>`. `checkAndDispatch()` combines check + notify in one call. No state held between calls. |
| `HistoryStoreInterface` | Persistence interface for query history. Implement `save(entry)`, `query(from, to, limit)`, `prune(before)`, and `count()` to plug in any storage backend. |
| `SqliteHistoryStore` | `HistoryStoreInterface` via SQLite with WAL mode. Schema: `id INTEGER PRIMARY KEY`, `query TEXT`, `duration_ms INTEGER`, `rows_affected INTEGER`, `error TEXT`, `ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP`. |
| `HistoryRecorder` | Passive recorder implementing `StatusSnapshotProviderInterface`. Accepts a `HistoryStoreInterface` and calls `save()` only when `provideStatusSnapshot()` is invoked by the polling loop — no active recording, no coupling to the UI. Records StatusSnapshot metrics (not SQL query text — see Query History section for SQL text storage). |
| `HistoryQuery` | Historical query helpers: `queriesPerSecond(from, to)`, `averageDuration(from, to)`, `errorRate(from, to)`, `topQueries(limit)`. All accept an optional `limit` to bound results. |

The PDO connection is the only stateful dependency; tests use a `:memory:` SQLite to exercise the full transition surface (load tables, switch panes, run query, error handling) without fixture files.

Multi-driver support is now available via `DatabaseInterface`. `CsvExporter` and `SqlExporter` provide driver-agnostic export.

## Connection factory

`ConnectionFactory` provides three static methods to build a configured `PDO` connection:

```php
use SugarCraft\Query\Db\ConnectionFactory;
use SugarCraft\Query\Db\ConnectionConfig;

// From a DSN string
$pdo = ConnectionFactory::fromDsn('mysql://user:pass@localhost:3306/dbname?ssl-mode=REQUIRED');

// From a ConnectionConfig value object
$config = new ConnectionConfig(
    driver: 'mysql',
    host: 'localhost',
    port: 3306,
    user: 'user',
    pass: 'secret',
    dbname: 'dbname',
    sslMode: 'REQUIRED',
);
$pdo = ConnectionFactory::fromConfig($config);

// From command-line arguments (--db-driver, --db-host, --db-port, --db-user, --db-pass, --db-name, --db-ssl-mode)
$pdo = ConnectionFactory::fromArgv();
```

### DSN format

```
driver://[user][:pass]@host[:port]/dbname[?query]
```

| Part     | Description                                                           |
|----------|-----------------------------------------------------------------------|
| driver   | `mysql`, `pgsql`, `sqlite`, `sqlsrv`                                 |
| user     | Database username (URL-encoded via `rawurlencode()` if contains `@` or `:`) |
| pass     | Database password — URL-encoded if it contains special chars; never echoed |
| host     | Server hostname or IP; IPv6 addresses use brackets: `[::1]`           |
| port     | Server port (default varies by driver)                                |
| dbname   | Database name                                                         |
| query    | Optional query string — `ssl-mode=MODE` is parsed and stored in `ConnectionConfig.sslMode`, then applied as PDO driver options at connect time |

> **MySQL SSL** — `ssl-mode` is parsed from the query string (e.g. `?ssl-mode=require`) and stored in `ConnectionConfig.sslMode`. It is **never** embedded in the MySQL DSN string (PDO mysql does not support `ssl-mode` as a DSN parameter). SSL is applied as `PDO::MYSQL_ATTR_SSL_CA` / `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` driver options in `MysqlDatabase::connect()`.

### DSN parsing: `parse_url()` over manual `explode()`

`ConnectionFactory::fromDsn()` uses `parse_url()` (with a SQLite-specific regex fallback) instead of manual `explode('@'|':')` splitting. This correctly handles:
- Passwords containing `@` or `:` — URL-encode them (`p%40ss%3Dword` → `p@ss:word` via `rawurldecode()`)
- Passwordless users — `mysql://user@host/db` (no `:` required, user without password)
- IPv6 hosts — `mysql://u:p@[::1]:3306/db` (brackets stripped from host, port preserved)
- SQLite — handled via direct regex since `parse_url()` returns `false` for `sqlite:///path`

Source: step 2.1 ai/candy-query-dsn-and-factory

## Schema introspection

`SchemaBrowser` uses a strategy pattern based on `Flavor` to delegate schema introspection to the appropriate `SchemaProviderInterface` implementation:

```php
use SugarCraft\Query\Schema\SchemaBrowser;
use SugarCraft\Query\Db\Flavor;

// Auto-detect flavor from PDO driver and use correct provider
$browser = (new SchemaBrowser($pdo))->refresh();

// Or specify explicitly
$browser = SchemaBrowser::forFlavor(Flavor::Sqlite, $pdo);
$browser = SchemaBrowser::forFlavor(Flavor::MySQL, $pdo);
$browser = SchemaBrowser::forFlavor(Flavor::Postgres, $pdo);

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

## Pagination

`ResultPager` wraps a full result-set and provides cursor-based navigation:

```php
$pager = new ResultPager($allRows, pageSize: 25);
while ($pager->hasNextPage()) {
    $pageRows = $pager->page();   // list of row arrays
    // process $pageRows
    $pager = $pager->nextPage();  // new immutable instance
}
```

`goToPage(1-based-int)`, `prevPage()`, and `withPageSize(int)` are also available. Page size defaults to 25 rows.

## Cell editing

`CellEditor` targets a table + primary-key column at construction, then performs single-cell or multi-cell updates by row identity:

```php
$editor = new CellEditor($pdo, 'users', 'id');
$editor->updateCell(rowId: 42, column: 'email', newValue: 'new@example.com');
$editor->updateRow(rowId: 42, cells: ['name' => 'Alice', 'email' => 'alice@example.com']);
$current = $editor->readCell(rowId: 42, column: 'email');
```

All identifiers are safely quoted via `str_replace('"', '""', $name)`. `updateCell` and `updateRow` return rows affected (0 or 1).

## Snippet store

`SnippetStore` persists named SQL snippets to a JSON file, surviving process restarts:

```php
$store = SnippetStore::load();                    // load from /tmp/candy-query-snippets.json
$store = $store->add('active-users', 'SELECT * FROM users WHERE active = 1', 'Fetch active users');
$store->flush();                                   // persist to disk

$store->find('active-users');                     // → Snippet|null
$store->search('users');                           // → list<Snippet> (name or SQL match)
$store->delete('active-users')->flush();          // remove and persist
```

`Snippet(name, description, sql, createdAt)` is a plain readonly value object. The store is immutable — every mutation returns a new instance.

## Query plan viewer

`ExplainView` uses a strategy pattern based on `Flavor` to delegate EXPLAIN parsing to the appropriate `ExplainProviderInterface` implementation:

```php
use SugarCraft\Query\QueryPlan\ExplainView;
use SugarCraft\Query\Db\Flavor;

// Auto-detect driver from PDO and use correct provider
$view = ExplainView::run($pdo, 'SELECT * FROM users JOIN orders ON users.id = orders.user_id');
echo $view->render();   // ANSI coloured tree
print_r($view->toArray());  // JSON-serialisable structure

// Or specify flavor explicitly
$view = ExplainView::forFlavor(Flavor::MySQL, $pdo);
$view = ExplainView::forFlavor(Flavor::Postgres, $pdo);
$view = ExplainView::forFlavor(Flavor::Sqlite, $pdo);
```

Each detail line is classified by op type: `SEARCH` (cyan), `SCAN` (yellow), `USING` (green), `JOIN` (purple), `SUBQUERY` (pink), `COMPOUND` (orange).

Available providers:
- **`SqliteExplainProvider`** — `EXPLAIN QUERY PLAN`, parses tree prefixes (`|--`, `` `-- ``) for depth
- **`MysqlExplainProvider`** — `EXPLAIN` formatted rows with tag/parent/detail/indent
- **`PostgresExplainProvider`** — `EXPLAIN (ANALYZE, FORMAT JSON)`, parses JSON structure for tree hierarchy

> **EXPLAIN safety guard** — `ConnectionDetailTabs::getExplain()` (Connections page, MDL detail tab, key `[3]`) will not run `EXPLAIN` on a thread's query unless it is a single `SELECT` statement. Multi-statement queries (containing `;`) and any non-SELECT statement (INSERT/UPDATE/DELETE/DROP/etc.) are rejected before execution. This prevents accidental execution of write/cleanup statements when inspecting a thread's current query. Returns `null` when the query is ineligible or explain fails.

## Admin providers

`AdminProviderInterface` is the flavor-agnostic entry point for admin panel data, exposing three methods: `dashboard()`, `connections()`, and `serverInfo()`. Each method returns structured data that the admin UI renders — whether that UI targets MySQL or Postgres.

```php
use SugarCraft\Query\Admin\AdminProviderInterface;
use SugarCraft\Query\Db\Flavor;

// Auto-detect flavor from PDO and use correct provider
$provider = AdminProviderInterface::forFlavor(Flavor::MySQL, $serverContext);
$provider = AdminProviderInterface::forFlavor(Flavor::Postgres, $serverContext);

$dashboard = $provider->dashboard();
$connections = $provider->connections();
$serverInfo = $provider->serverInfo();
```

Available providers:
- **`MysqlAdminProvider`** — `SHOW GLOBAL STATUS`, `SHOW GLOBAL VARIABLES`, `SHOW ENGINE INNODB STATUS`, `SHOW PROCESSLIST`, `SHOW REPLICA STATUS` (graceful 1227 handling)
- **`PostgresAdminProvider`** — `pg_stat_database`, `pg_settings`, `pg_stat_activity`. Implements `checkAllMetrics()` with computed PostgreSQL metrics (connection usage, cache hit rate, transaction rates, throughput) and `checkConnectionUsage()` with threshold alerts. `serverInfo()` maps `pg_stat_database` fields (`numbackends`, `xact_commit`, `xact_rollback`, `blks_read`, `blks_hit`, `tup_returned`, `tup_fetched`, `tup_inserted`, `tup_updated`, `tup_deleted`, `max_connections`, `shared_buffers`).

## Result table

`ResultTable` renders a SQL result set with horizontal scrolling, JSON pretty-print, and a styled NULL token:

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
| **Replication** | Master Host/Port, IO/SQL Running state, Seconds Behind, Relay Log File/Pos — via `ReplicaStatusProvider` |
| **Firewall** | AWS RDS firewall status (Aurora_lwm sentinel) |

```php
use SugarCraft\Query\Admin\ServerStatus\ServerStatusPage;
use SugarCraft\Query\Admin\ServerContextInterface;

$page = ServerStatusPage::new($context);
echo $page->render();
```

`ReplicaStatusProvider` uses `SHOW REPLICA STATUS` on MySQL 8+ and `SHOW SLAVE STATUS` on MySQL 5.x/MariaDB, gracefully handling error 1227 (REPLICATION CLIENT privilege denied).

## Performance Reports

The Performance Reports page (`[8]` in admin) displays data from MySQL's `sys` schema views — grouped by category (problems, schema, IO, memory, etc.). Report queries are fully asynchronous: `validate()` calls only `Catalog::load()` (file I/O), then `App` dispatches `ReloadReportMsg` after the admin data tick, queuing the report query via `AdminQueryCache` for the next event-loop tick. This means the page never blocks on a slow `SELECT * FROM sys.x$*` query, matching the non-blocking behaviour of processlist and replica pages.

**Async flow:**
1. `ReportsPage::validate()` — loads `Catalog` from `data/sys_reports.json` (file I/O, always sync, never blocks)
2. After admin status/variables data lands, `App` sends `ReloadReportMsg` to `ReportsPage`
3. `ReportsPage::update(ReloadReportMsg)` calls `loadCurrentReport()` — queries are issued through `AdminQueryCache` via `CachedConnection`, returning `null` immediately
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
| `x` | Export current report to CSV (RFC-4180, formula-safe) |
| `c` | Toggle unit display for time/byte columns (global; `selectedColumnIndex` is tracked for future per-column targeting) |
| `q` | Quit to previous view |

> **Category/report navigation** — `h`/`l` and `[`/`]` keys traverse the category tree and the report list within each category respectively. Both wrap around at boundaries. Selecting a category or report triggers `loadCurrentReport()` asynchronously via `AdminQueryCache`, exactly as if the user clicked it in the tree.

> **No DB query in `validate()`** — The requirement for reports is that `validate()`/`view()`/`update()` never issue a synchronous `db->query()`. All DB access flows through `AdminQueryCache` → `Cmd::promise` → async drain tick. If the sys schema is unavailable, `Catalog::load()` still succeeds (it's file I/O); `AvailabilityChecker::discoverViews()` returns `[]` and `view()` shows the empty category tree with no blocking error.

## Alerting

`AlertManager` evaluates metrics against configurable thresholds and fires `Alert` value objects, which `AlertNotifier` renders as toast notifications via `sugar-toast`:

```php
use SugarCraft\Query\Admin\Alerts\AlertManager;
use SugarCraft\Query\Admin\Alerts\AlertThresholds;
use SugarCraft\Query\Admin\Alerts\AlertNotifier;

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
// In DashboardPage::update() — called every 3s
['alerts' => $alerts, 'notifier' => $notifier] = $manager->checkAndDispatch($counters);
if ($notifier->hasAlerts()) {
    $this->showAlertBadge = true;  // displayed in footer
}
```

The `[a]` key dismisses all pending alerts and clears the badge. `AlertNotifier` is mute-safe by default — running without a toast factory is a silent no-op.

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
use SugarCraft\Query\Db\MysqlDatabase;
use SugarCraft\Query\Admin\Resilience\ReconnectManager;

$db = new MysqlDatabase($pdo, reconnectManager: new ReconnectManager());
// On error 2002/2003/2013, query() returns null instead of throwing
// Caller should re-fetch the connection and retry
```

### Restart detection

`Sampler::registerUptime()` records the server's uptime at construction time. `StatusPoller` compares uptime snapshots across polls to detect MySQL restarts, resetting cached state before it becomes stale.

### Statement timeout

`StatementTimeout` enforces a wall-clock timeout on heavy report queries using `pcntl_alarm()`. If the timeout fires, it cancels the query via `KILL CONNECTION_ID()` and throws `StatementTimeoutException`:

```php
use SugarCraft\Query\Admin\Resilience\StatementTimeout;
use SugarCraft\Query\Admin\Resilience\StatementTimeoutException;

$timeout = new StatementTimeout(timeoutSeconds: 60);
try {
    $stmt = $pdo->prepare('SELECT * FROM big_table WHERE conditions');
    $timeout->execute($stmt);
} catch (StatementTimeoutException) {
    echo 'Query exceeded 60s timeout and was cancelled';
}
```

When `pcntl` is unavailable, `StatementTimeout::execute()` degrades gracefully and runs without enforcement (logs a warning at construction time).

## Query History (optional)

Query history is an opt-in SQLite-backed layer with two separate concerns:

1. **StatusSnapshot metrics** — `HistoryRecorder` captures server metrics (connections, QPS, cache hit rates, etc.) via `StatusSnapshotProviderInterface` on each poll cycle and persists them to `SqliteHistoryStore`.
2. **SQL query text** — Stored via `SqliteHistoryStore::save()` (schema includes `query TEXT`, `duration_ms`, `rows_affected`, `error`). This integration point is `App::runQuery()` — see future work in `CALIBER_LEARNINGS.md`.

Both share the same `SqliteHistoryStore` (WAL mode) and `HistoryQuery` helpers.

```php
use SugarCraft\Query\Admin\History\SqliteHistoryStore;
use SugarCraft\Query\Admin\History\HistoryRecorder;
use SugarCraft\Query\Admin\History\HistoryQuery;

// Persist to a SQLite file (WAL mode, auto-pruned at 1000 entries)
$store = SqliteHistoryStore::open('/tmp/candy-query-history.sqlite');
$recorder = new HistoryRecorder($store);

// Attach to the polling loop via StatusSnapshotProviderInterface
// The recorder only writes when provideStatusSnapshot() is called
$status = $recorder->provideStatusSnapshot($previousStatus);

// Query historical patterns
$q = new HistoryQuery($store);
$qps = $q->queriesPerSecond(from: new DateTimeImmutable('-1 hour'), to: new DateTimeImmutable());
$avgDuration = $q->averageDuration(from: new DateTimeImmutable('-1 day'), to: new DateTimeImmutable());
$errorRate = $q->errorRate(from: new DateTimeImmutable('-1 hour'), to: new DateTimeImmutable());

// Prune entries older than 30 days
$store->prune(before: new DateTimeImmutable('-30 days'));
```

The `HistoryRecorder` implements `StatusSnapshotProviderInterface`, so it slots into the existing polling loop without coupling to the UI. The `SqliteHistoryStore` uses WAL mode for safe concurrent reads during writes.

## Demos

### SQL query execution

![play](.vhs/play.gif)

### Query history cycling

![query-history](.vhs/query-history.gif)

## DatabaseInterface

`App` depends on `DatabaseInterface` rather than a concrete PDO/SQLite implementation. This decouples the UI from the database driver, enabling MySQL and Postgres support without changing application logic.

The interface defines 12 methods:

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

### Deprecated: `Database` class

`src/Database.php` is deprecated — it is a thin alias to `SqliteDatabase`. New code should use `DatabaseInterface` directly:

```php
use SugarCraft\Query\Db\DatabaseInterface;

// Type-hint against the interface for driver-agnostic code
function processDb(DatabaseInterface $db): void { ... }
```

### PreparedStatementInterface

`DatabaseInterface::prepare()` returns a `PreparedStatementInterface` — a driver-neutral wrapper around the native PDOStatement. This keeps caller code free of driver-specific statement types:

```php
use SugarCraft\Query\Db\DatabaseInterface;
use SugarCraft\Query\Db\PreparedStatementInterface;

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
use SugarCraft\Query\Db\Export\CsvExporter;
use SugarCraft\Query\Db\Export\SqlExporter;

$csv = (new CsvExporter($db))->exportCsv('/tmp/users.csv', 'users');
$sql = (new SqlExporter($db))->exportSql('/tmp/users.sql');
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
