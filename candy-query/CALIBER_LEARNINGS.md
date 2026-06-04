# Caliber Learnings — candy-query

Accumulated patterns and anti-patterns specific to this library.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:sqlite-pragma-schema]** — SQLite PRAGMA results (`table_info`, `index_list`, `index_info`, `foreign_key_list`) return untyped scalar arrays. Wrap each in a dedicated private method that returns typed `SchemaColumn`/`SchemaIndex`/`SchemaForeignKey` value objects — the types catch mis-indexed row access at construction time rather than at call site. Canonical: `SchemaBrowser::loadColumns()` / `loadIndexes()` / `loadForeignKeys()`.

- **[pattern:immutable-cursor-pager]** — Cursor-based pagination over an in-memory result-set is naturally immutable: `nextPage()` / `prevPage()` return `new self(...)` with a shifted offset rather than mutating `$this`. Storing `$rows` (the full set) as a constructor arg keeps the pager stateless between navigation calls and enables `withPageSize()` to recompute offset clamps without re-querying. Canonical: `ResultPager`.

- **[pattern:file-backed-json-store]** — A file-backed JSON store for named snippets works best as an immutable value object with a separate `flush()` call: the in-memory state is always a value (safe to copy, thread-free to read), and `flush()` is an explicit side-effect that serialises to disk. Guarding against corrupt files at `load()` with a no-op fallback keeps the store resilient without polluting call sites with try/catch. Canonical: `SnippetStore::load()` / `flush()`.

- **[pattern:horizontal-scroll-table]** — Horizontal scrolling for wide result sets uses a computed `$offset` (first visible column index) and `$visibleWidth` (character budget per render) to derive the visible column slice. Auto-sizing columns to the widest value in the full set requires a full pass at construction time — worth it because the layout is stable across scrolls. Canonical: `ResultTable::visibleColumns()` / `scrollLeft()` / `scrollRight()`.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-05-31 — god-class App needs a builder
Pattern: A fluent builder relieves a long parameter list and makes dependency injection explicit. App had 14 params; the builder names each one so call sites are self-documenting.
Anti-pattern: Constructing App with 14 positional args — parameter-order mistakes are silent and the code is unreadable.
Source: step-25 ai/god-class-builders

### 2026-06-02 — MySQL connection resilience
Pattern: MySQL error codes 2002 (Can't connect to local MySQL server), 2003 (Can't connect to MySQL server), and 2013 (Lost connection during query) are transient. A `ReconnectManager` stores the last known `ConnectionConfig` and `attemptReconnect()` lets callers retry without re-prompting for credentials. The manager extracts the numeric code from the PDOException message when the code is 0 (SQLSTATE-based).
Source: step-7.1 ai/resilience

### 2026-06-02 — pcntl_alarm wall-clock timeout
Pattern: Use `pcntl_alarm()` for statement-level wall-clock timeouts — it fires a `SIGALRM` asynchronously and works across blocking I/O that `set_time_limit()` cannot interrupt. The handler saves the previous signal handler, arms `pcntl_alarm(seconds)`, executes the statement, then disarm with `pcntl_alarm(0)` in `finally`. On timeout, `KILL CONNECTION_ID()` cancels the query before re-throwing.
Graceful degradation: When `pcntl_alarm()` / `pcntl_signal()` / `pcntl_async_signals()` are unavailable, log a warning at construction and execute without timeout enforcement.
Source: step-7.1 ai/resilience

### 2026-06-02 — null return for reconnectable query failure
Pattern: `MysqlDatabase::query()` returns `array|null` — on reconnectable errors (2002/2003/2013) it returns `null`, signaling the caller to re-fetch the connection and retry. This avoids throwing an exception when the connection is legitimately being re-established.
Source: step-7.1 ai/resilience

### 2026-06-02 — restart detection via uptime comparison (STEP 7.1)
Pattern: `ServerContext::detectReset()` is the single authoritative owner of restart detection. It is called by `statusVariables()` after fetching fresh `SHOW GLOBAL STATUS` data, compares the current `Uptime` value against `$this->lastUptime`, and sets `wasResetCache = true` when the new value is lower (wraps or restarts). `Sampler` and `StatusPoller` consume this via `StatusSnapshotProviderInterface::wasReset()` (delegating to `ServerContext::wasReset()`), keeping restart detection logic centralized. Previously, uptime tracking was spread across `Sampler::registerUptime()` and `StatusPoller`; these were unified into `ServerContext` as the single source of truth.
Source: step-7.1 ai/resilience

### 2026-06-04 — AsyncOps::throttle() returns void callable, not a promise (STEP 7.1)
Pattern: `AsyncOps::throttle()` returns `callable(mixed...): void` — a synchronous gating wrapper that sets a `$cooldown` flag and fires the wrapped function immediately, scheduling a timer to reset the flag. It cannot be awaited as a Promise in the `Cmd::promise` flow used by `App::subscriptions()`. A manual time-based cooldown (static `$lastFetchAt`, 3.0s elapsed guard) was used instead in `App::subscriptions()`, which keeps the tick firing (for `AdminQueryCache` queue draining) while skipping the fetch during cooldown. This is a deliberate deviation: `AsyncOps::throttle()` is useful for fire-and-forget event handlers but incompatible with promise-based async flows that need to preserve the `Cmd` chain.
Canonical: `App::subscriptions()` — `static $lastFetchAt = 0.0; $elapsed = $now - $lastFetchAt; if ($elapsed < 3.0) { return Cmd::none(); } $lastFetchAt = $now;` instead of `AsyncOps::throttle()`.
Source: step-7.1 ai/candy-query-async-throttle-restart

### 2026-06-02 — stateless AlertManager pattern
Pattern: An alert checker should hold no state between calls — each `check*()` invocation is independent and returns fresh `Alert` value objects. This makes the checker safe for both polling loops (3s DashboardPage cycle) and event-driven contexts without needing to reset state. The manager is constructed once with thresholds and notifier, then queried repeatedly.
Canonical: `AlertManager::new()->withThresholds($t)->withNotifier($n)` — `checkConnectionUsage()` / `checkAllMetrics()` are pure functions over their inputs.
Source: step-7.2 ai/alerting

### 2026-06-02 — toast degradation and mute-safe AlertNotifier
Pattern: A notifier that wraps an optional toast factory should default to muted when no factory is provided — every `notify*()` call becomes a no-op, making the system safe to use in non-TUI contexts without errors. The mute state is explicit (`isMuted()` / `withMuted()`) and all `notify*()` methods return new instances for immutability.
Canonical: `AlertNotifier::new()` (muted by default) → `AlertNotifier::withDefaults($factory, muted: false)` to enable.
Source: step-7.2 ai/alerting

### 2026-06-02 — Severity → ToastType mapping
Pattern: Map a local `Severity` enum to an external `ToastType` using a `toToastType()` method on the enum. This keeps the local domain model independent of sugar-toast internals. The mapping is semantic: `Critical` maps to `ToastType::Error` (not `ToastType::Critical`) because critical is more severe than error in the toast taxonomy and gets the most prominent display treatment.
Canonical: `Severity::toToastType()` — `Info→Info`, `Warning→Warning`, `Critical→Error`.
Source: step-7.2 ai/alerting

### 2026-06-02 — passive recorder pattern (StatusSnapshotProviderInterface delegation)
Pattern: A recorder that implements `StatusSnapshotProviderInterface` only writes when `provideStatusSnapshot()` is called by the polling loop — it never主动 records on its own. This decoupling means the recorder has no dependency on the UI, making it safe for both TUI and headless contexts.
Canonical: `HistoryRecorder` accepts a `HistoryStoreInterface` in the constructor and calls `$store->save(...)` only when invoked by the polling cycle.
Source: step-7.3 ai/history

### 2026-06-02 — SQLite WAL mode for concurrent read/write
Pattern: Enable WAL mode (`PRAGMA journal_mode=WAL`) when a SQLite DB is accessed by both a polling loop writer and a query reader. WAL allows concurrent readers without blocking the writer, and writers don't block readers either.
Canonical: `SqliteHistoryStore::open()` issues `PRAGMA journal_mode=WAL` after opening.
Source: step-7.3 ai/history

### 2026-06-02 — StatusSnapshotProviderInterface as composable decoration
Pattern: Components that need to participate in the status polling loop implement `StatusSnapshotProviderInterface` without extending a base class. The interface has a single method `provideStatusSnapshot(?StatusSnapshot $previous): StatusSnapshot`, making it a pure decoration that can be composed onto any object. History, alerts, and gauges all implement the same interface and are composed in the poll loop.
Canonical: `HistoryRecorder implements StatusSnapshotProviderInterface` — same contract as `AlertManager`, `Sampler`, and all other poll participants.
Source: step-7.3 ai/history

### 2026-06-02 — flavor-agnostic AdminProviderInterface
Pattern: An `AdminProviderInterface` with a static `forFlavor(Flavor, ServerContext)` factory abstracts the MySQL vs. Postgres distinction behind a common API. Callers never reference the concrete provider class — they call `dashboard()` / `connections()` / `serverInfo()` and get back flavor-native data shaped into a shared format. This keeps admin UI code free of conditional branching on Flavor.
Canonical: `AdminProviderInterface::forFlavor(Flavor::Postgres, $ctx)->serverInfo()` returns a `PostgresServerInfo` value object regardless of the call site.
Source: step-7.4 ai/postgres-admin

### 2026-06-02 — Postgres pg_stat_database mapping in PostgresAdminProvider
Pattern: `pg_stat_database` returns a row-per-database with counters (`numbackends`, `xact_commit`, `xact_rollback`, `blks_read`, `blks_hit`, `tup_returned`, `tup_fetched`, `tup_inserted`, `tup_updated`, `tup_deleted`). Map these into the same `PostgresServerInfo` fields that MySQL's `SHOW GLOBAL STATUS` produces, so the same admin rendering code works for both flavors without modification.
Canonical: `PostgresAdminProvider::serverInfo()` queries `pg_stat_database WHERE datname = current_database()` and maps the counters.
Source: step-7.4 ai/postgres-admin

### 2026-06-02 — graceful degradation on Postgres permission errors
Pattern: `pg_stat_database`, `pg_stat_activity`, and `pg_settings` require varying privilege levels. When a query fails due to insufficient permissions, catch the PDOException and return a safe stub (`null` or an empty array) rather than propagating the error. This allows the admin UI to render the panels it can access even when others are restricted.
Canonical: `PostgresAdminProvider` wraps each stat query in try/catch and falls back to `null` for the affected panel, preserving `serverInfo()` availability for the broader admin flow.
Source: step-7.4 ai/postgres-admin

### 2026-06-03 — PostgresWidgetCatalog panel expansion (Step B3)
Pattern: Postgres admin panels grew from stub to full implementation: `io()` expanded 6→10 widgets (tuple metrics: returned/fetched/inserted/updated/deleted), `cache()` expanded 3→4 widgets (added Shared Buffers). A `parseSharedBuffers()` helper converts byte strings (e.g. `"8GB"`) to numeric bytes for display.
Canonical: `PostgresWidgetCatalog::io()` / `cache()` / `parseSharedBuffers()`.
Source: step-b3 ai/postgres-widget-catalog

### 2026-06-03 — PostgreSQL computed metrics and connection alerts (Step C1)
Pattern: `PostgresAdminProvider` implements `checkAllMetrics()` returning computed PostgreSQL metrics (connection_usage, cache_hit_rate, xact_rate, tup_rate) and `checkConnectionUsage()` with threshold alerts. A `computeRate()` helper calculates per-second rates from cumulative counters using elapsed time, avoiding division-by-zero with a minimum time denominator.
Canonical: `PostgresAdminProvider::checkAllMetrics()` / `checkConnectionUsage()` / `computeRate()`.
Source: step-c1 ai/postgres-metrics

### 2026-06-03 — Performance Schema processlist with SHOW fallback (Step E1)
Pattern: `fetchProcesslist()` checks `performance_schema` server variable first and calls `fetchProcesslistFromPs()` when enabled, falling back to `fetchProcesslistFromShow()` on permission errors (1142/1143). The PS query joins `performance_schema.threads` with `performance_schema.session_connect_attrs` matching MySQL Workbench §5.5. This gives richer data (PROCESSLIST_ID, connection attributes) than `SHOW FULL PROCESSLIST` while remaining resilient to restricted users.
Canonical: `MysqlAdminProvider::fetchProcesslist()` → `fetchProcesslistFromPs()` / `fetchProcesslistFromShow()`.
Source: step-e1 ai/ps-processlist

### 2026-06-03 — CSV formula injection mitigation in ReportsPage (Step D1)
Pattern: CSV export must escape formula-injection characters (`=`, `+`, `-`, `@`) by prefixing them with a single quote. This prevents malicious data in cells from being interpreted as formulas when the CSV is opened in spreadsheet applications like Excel. Also escape values containing commas, quotes, or newlines by wrapping in double-quotes and doubling internal quotes.
Canonical: `ReportsPage::exportToCsv()` — checks `$value[0]` for dangerous prefixes and prepends `'` before the value, then wraps in quotes if needed.
Source: step-d1 ai/csv-export

### 2026-06-03 — DashboardPage AlertManager polling integration (Step F1)
Pattern: `AlertManager` is composed into the `DashboardPage` poll loop via `StatusSnapshotProviderInterface` — `checkAlerts()` is called on each 3s cycle, dispatching toasts for threshold breaches and setting a `$showAlertBadge` flag for the footer indicator. The `[a]` key handler dismisses all alerts and clears the badge. This keeps alerting orthogonal to the gauge/update rendering with no shared mutable state.
Canonical: `DashboardPage::checkAlerts()` → `AlertManager::checkAndDispatch()` → `$this->showAlertBadge = $notifier->hasAlerts()`.
Source: step-f1 ai/alert-manager

### 2026-06-03 — ServerStatusPage 2-column layout with SidebarGaugeSet (Step I1)
Pattern: `ServerStatusPage` uses a 2-column layout — info panels (server info, features, directories, SSL, replication, firewall) on the left, `SidebarGaugeSet` on the right. Gauges poll `ServerContext` and an optional `Sampler` for rate calculations. The traffic gauge uses Sampler delta for a baseline-corrected ratio, fixing cases where cumulative counters reset or wrap.
Canonical: `ServerStatusPage::render()` composes left panel stack + right `SidebarGaugeSet::view()`.
Source: step-i1 ai/sidebar-gauges

### 2026-06-03 — Admin page state survival + key delegation (STEP 1.1)
Pattern: `handleAdminKey()` delegates unhandled keys to the active page's `update()` so pages can respond to Tab/Space/'a'/'w'/'s' without App intercepting them first. Precedence is deliberate: app-level keys (digits, q, j/k, p, r) are handled before delegation. Page state survives the poll-tick refresh cycle because `withAdminLoading()` no longer nulls `adminPage`; only `withAdminPane()` resets it when the pane changes. Pages read fresh data from the shared `AdminQueryCache` on each render, so in-memory state (cursor, tab, pending edits) is preserved while server data stays current.
Canonical: `App::handleAdminKey()` → `[$newPage, $cmd] = $page->update($msg)` at end of method; `withAdminLoading()` uses `mutate(['adminLoading' => $loading])` without touching `adminPage`.
Source: step 1.1 ai/candy-query-admin-key-routing

### 2026-06-03 — VariablesPage collaborator injection (STEP 1.2)
Pattern: `VariablesPage` is constructed with an optional `Catalog` (eagerly loaded) and an optional `VariableEditor`. The `Catalog` is loaded eagerly in `App::buildVariablesPage()` so that `loadCategories()` and `isEditable()` are available immediately. A missing metadata file is non-fatal — the page renders with an empty category tree and no `[rw]` indicator. `VariableEditor` is created with the catalog so it can validate editability per variable.
Canonical: `App::buildVariablesPage()` → `Catalog::new()->load()` + `VariableEditor::new($context, $catalog)` → `VariablesPage::new($context, $catalog, $editor)`.
Source: step 1.2 ai/candy-query-page-collaborators

### 2026-06-03 — AdminPane::orderedCases() as single source of truth (STEP 1.2)
Pattern: `AdminPane::orderedCases()` groups enum cases by section (Management first: ProcessList, Variables, Status, Debug; then Performance: QueryStats, Dashboard, TableStats, PerfSchema) and is the single source of truth for both the sidebar renderer and the digit-key handler. Code that needs display order MUST use `orderedCases()` — `cases()` returns declaration order and differs from display order. The digit keys map as: 1=ProcessList, 2=Variables, 3=Status, 4=QueryStats, 5=Dashboard, 6=TableStats, 7=PerfSchema, 8=Debug.
Canonical: `AdminPane::orderedCases()` used in `App::handleAdminKey()` for digit dispatch and in the sidebar render loop for display.
Source: step 1.2 ai/candy-query-page-collaborators

### 2026-06-03 — ReportsPage db injection overwrite on validate() (STEP 1.2 note)
Pattern: `ReportsPage` accepts an optional `?DatabaseInterface $db` in its constructor but `validate()` unconditionally sets `$this->db = $this->context->connection()`. This means any db passed via the constructor is overwritten on first `validate()`. This is pre-existing behaviour but important for anyone trying to inject a test double — inject the mock in `validate()` or use a test double of `ServerContextInterface` instead.
Canonical: `ReportsPage` constructor `$db` param is unused after first `validate()` call.
Source: step 1.2 ai/candy-query-page-collaborators

### 2026-06-03 — ConnectionsPage::update() + selection/index memoization (STEP 1.3)
Pattern: `ConnectionsPage::update(Msg)` handles keyboard input for the connections/admin page: j/k/↑/↓ for selection navigation, Tab/1/2/3 for detail tab cycling, f for hide-sleeping filter toggle, r for async refresh via `Cmd::send(new AdminFetchStartedMsg())`. The `cachedFilteredProcesslist` memoization is invalidated on every state-changing operation (`withFilters()`, `withSelectedIndex()`, `handleRefresh()`) so the next render always gets fresh data without a synchronous DB query on the keystroke path.
Canonical: `ConnectionsPage::update()` → `withNavigateDown()` / `withNavigateUp()` → `withSelectedIndex()` → `filteredProcesslist()` (lazy, cached); `handleRefresh()` → `Cmd::send(new AdminFetchStartedMsg())` (async, not blocking).
Source: step 1.3 ai/candy-query-connections-update

### 2026-06-03 — MDL join correction: OWNER_THREAD_ID vs THREAD_ID (STEP 1.4)
Pattern: `performance_schema.metadata_locks` has no `THREAD_ID` column — the correct join to `performance_schema.threads` is `metadata_locks.OWNER_THREAD_ID = threads.THREAD_ID`. Using `metadata_locks.THREAD_ID` silently returns zero rows. This was the pre-existing (broken) join; the fix uses `OWNER_THREAD_ID`. The PS `metadata_locks` table also lacks PROCESSLIST_ID — processlist ID must be retrieved via the `threads` table join, matching on `t.PROCESSLIST_ID = ?`.
Canonical: `ConnectionDetailTabs::fetchMdlFromPslocks()` — `JOIN performance_schema.threads t ON ml.OWNER_THREAD_ID = t.THREAD_ID WHERE t.PROCESSLIST_ID = ?`.
Source: step 1.4 ai/candy-query-connections-actions

### 2026-06-03 — MySQL KILL不接受placeholders + KILL QUERY vs KILL CONNECTION (STEP 1.4)
Pattern: MySQL's `KILL` and `KILL QUERY` statements do not accept `?` placeholders — the ID must be interpolated directly into the SQL string. An `int` cast makes this injection-safe. `KILL CONNECTION` disconnects the client entirely; `KILL QUERY` cancels the running statement while keeping the connection alive.
Canonical: `ConnectionActions::executeKill()` — `"KILL CONNECTION {$id}"` or `"KILL QUERY {$id}"` via `exec()` (no result set returned).
Source: step 1.4 ai/candy-query-connections-actions

### 2026-06-03 — MySQL SSL via PDO driver options, not DSN (STEP 2.1)
Pattern: PDO mysql does not support `ssl-mode` as a DSN parameter — the MySQL DSN must be just `mysql:host=%s;port=%d;dbname=%s`. SSL is configured instead as PDO driver options (`PDO::MYSQL_ATTR_SSL_CA`, `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`) applied at connect time in `MysqlDatabase::connect()` and `reconnect()`. The `sslMode` string is stored in `ConnectionConfig` and translated to driver options: `disable`/`''` means no SSL; `prefer`/`require` sets `VERIFY_SERVER_CERT=false`; `verify_ca`/`verify_identity` sets `VERIFY_SERVER_CERT=true`.
Canonical: `MysqlDatabase::connect()` — SSL driver options set based on `$config->sslMode`.
Source: step 2.1 ai/candy-query-dsn-and-factory

### 2026-06-03 — DSN parsing via `parse_url()` + SQLite regex fallback (STEP 2.1)
Pattern: `ConnectionFactory::fromDsn()` uses `parse_url()` for non-SQLite drivers, which correctly handles URL-encoded special chars in passwords (`rawurldecode()`), passwordless users (no `:` required), and IPv6 hosts (brackets stripped). SQLite uses a direct regex because `parse_url()` returns `false` for `sqlite:///path` and parses `:memory:` as `host=':memory'`. The old hand-rolled `explode('@'|':')` parser broke on any of these cases.
Canonical: `ConnectionFactory::fromDsn()` — `parse_url()` for mysql/pgsql, regex for sqlite.
Source: step 2.1 ai/candy-query-dsn-and-factory

### 2026-06-03 — query() returns null on disconnectable error (STEP 2.2)
Pattern: `MysqlDatabase::query()` and `PostgresDatabase::query()` now return `array|null` — on errors 2002/2003/2013 (connection lost) they return `null` instead of `[]`. Callers that iterate the result directly (e.g., `foreach ($db->query($sql) as $row)`) must guard against null. This is a deliberate contract change to signal reconnectable failures distinctly from empty results.
Canonical: `if (($rows = $db->query($sql)) === null) { /* reconnect and retry */ }`.
Source: step 2.2 ai/candy-query-query-contract-and-flavor

### 2026-06-03 — PreparedStatementInterface as driver-neutral statement contract (STEP 2.2)
Pattern: `DatabaseInterface::prepare()` now returns `PreparedStatementInterface|null` instead of `mixed`. All three database implementations wrap their PDOStatement in `PdoPreparedStatement` before returning. This gives callers a uniform type (`execute()`/`fetch()`/`fetchAll()`/`rowCount()`/`closeCursor()`) without depending on the raw PDOStatement type, making it possible to mock statements in tests or swap drivers without changing call sites.
Canonical: `PdoPreparedStatement` wraps `$pdo->prepare($sql)` and delegates all five interface methods; `SqlitePreparedStatement` does the same for the sqlite-specific path.
Source: step 2.2 ai/candy-query-query-contract-and-flavor

### 2026-06-03 — Flavor::detectFromDriver() companion to detectFromVersionString() (STEP 2.2)
Pattern: `Flavor::detectFromDriver()` uses the PDO driver name ('mysql', 'pgsql', 'sqlite') as the primary signal, then calls `detectFromVersionString()` only for the mysql driver when a version string is also provided. This ensures a mysql/pgsql driver never accidentally falls back to SQLite for an unparseable version string.
Canonical: `Flavor::detectFromDriver($driverName, $version, $versionComment)` — mysql + version → `detectFromVersionString()`, pgsql → `Postgres`, sqlite → `Sqlite`, default → `Sqlite`.
Source: step 2.2 ai/candy-query-query-contract-and-flavor

### 2026-06-04 — CsvExporter: driver-neutral column detection + RFC-4180 + formula guard (STEP 2.3)
Pattern: CsvExporter column detection uses `SELECT * FROM table LIMIT 0` followed by `SELECT * FROM table LIMIT 1` (both driver-neutral) instead of SQLite-specific PRAGMA queries or sqlite_master queries. Output is proper RFC-4180 CSV via `fputcsv()` with no trailing space padding. Formula injection guard prefixes values starting with `=`, `+`, `-`, `@`, `\t`, or `\r` with `'` before writing; leading spaces are trimmed before the check so `  =SUM(...)` is also protected. The guard applies to both headers and data cells.
Limitation: empty tables (0 rows) cannot have their columns detected driver-neutrally; exporting an empty table produces a blank file.
Canonical: `CsvExporter::writeCsv()` — `guardFormula()` check on every header and cell value; `getColumnNames()` — LIMIT 0 then LIMIT 1 fallback.
Source: step 2.3 ai/candy-query-exporters

### 2026-06-04 — SqlExporter: no double-quoting, no CREATE TABLE, driver-neutral columns (STEP 2.3)
Pattern: `SqlExporter::quoteValue()` passes values directly to `$db->quote()` which returns a complete quoted literal — it must NOT be wrapped in extra quotes. Numbers are cast to string unquoted. CREATE TABLE generation is intentionally omitted: the full CREATE statement requires driver-specific queries (`SHOW CREATE TABLE` for MySQL, `sqlite_master`/`PRAGMA table_info` for SQLite) which are not driver-neutral; the INSERT data is the primary value for data portability. Column detection uses `SELECT * FROM table LIMIT 1` driver-neutrally; tables with zero rows cannot have their columns determined.
Canonical: `SqlExporter::quoteValue()` — `null`→`NULL`, int/float→unquoted string, string→`$this->db->quote()` (no extra wrapping); `getColumnNames()` — LIMIT 1 query.
Source: step 2.3 ai/candy-query-exporters

### 2026-06-04 — Reports async: no blocking queries on the render path (STEP 3.1)
Pattern: `ReportsPage::validate()` must never call `db->query()`. Instead it calls only `Catalog::load()` (file I/O) and creates `AvailabilityChecker`/`ReportRunner` without querying them. The App-level async flow (admin fetch tick via `createAdminFetchPromise`) triggers `ReloadReportMsg` after admin data loads; `ReportsPage::update()` handles this by issuing the report query through `AdminQueryCache`. `view()` shows a loading spinner when `currentResult === null` (no cached result yet) and renders the grid once the result lands on the next tick. This pattern matches the processlist/replica async flow: `lookup()` returns `null` immediately on a cache miss, the query is drained async, and `view()` stays non-blocking throughout.
Canonical: `ReportsPage::validate()` — only `Catalog::load()` (no DB); `App::update(AdminDataLoadedMsg)` → sends `ReloadReportMsg`; `ReportsPage::update(ReloadReportMsg)` → `loadCurrentReport()` via `AdminQueryCache::lookup()` returning `null` → `view()` shows spinner; next tick fills cache and re-renders.
Caveat: `AvailabilityChecker::discoverViews()` catches `\Throwable`, not just `\PDOException` — React/cached connections can surface non-PDO error types.
Source: step 3.1 ai/candy-query-reports-async

### 2026-06-04 — Reports catalog navigation + curated category ordering (STEP 3.2)
Pattern: `ReportsPage` now has keyboard navigation: `h`/`l` (prev/next category with wrap), `[`/`]` (prev/next report within category with wrap), `,`/`.` (prev/next column index for future per-column unit targeting). All navigation methods return a new `self` instance and reset `selectedColumnIndex` to 0. Navigation is wired through `App::handleAdminKey()` → `ReportsPage::update()` and triggers async report loading via `loadCurrentReport()`. `Catalog::categories()` sorts by a `CATEGORY_ORDER` constant (problems first, matching MySQL Workbench Appendix B) rather than alphabetically; unknown categories fall through to alphabetical ordering after the curated set.
Column type parsing uses `ColumnType::tryFrom()` instead of `ColumnType::from()` — `tryFrom()` returns `null` for unknown types (preventing a ValueError fatal) while `from()` throws. Unknown types fall back to `ColumnType::String`.
`selectedColumnIndex` tracks the focused column for unit display but is not yet consumed in the render path — the `[c]` key remains a global unit toggle. Future per-column unit cycling would need to re-architect `ReportRunner::formatRows()` to store both raw and formatted values, or re-query on toggle.
Canonical: `ReportsPage::update()` — `h`/`l`/`[/]`,/`., `[`/`]` key handlers; `Catalog::categories()` — `CATEGORY_ORDER` usort; `ColumnType::tryFrom()` fallback.
Source: step 3.2 ai/candy-query-reports-navigation-catalog

### 2026-06-04 — VariablesPage edit dialog: two-phase state machine + self-write guard + error 1238 (STEP 4.1)
Pattern: The edit dialog is a two-phase state machine (`DLG_INPUT` → `DLG_CONFIRM`) implemented via `withEditDialog()` — a private wither that clones the page and returns a new instance with dialog fields set. The `handleEdit()` method gates on `isDynamic()` (not `isEditable()`) so that static (non-dynamic) variables reach error 1238 at the confirm phase with a user-facing message rather than silently no-opping at the entry point. The self-write guard in `updateDialogInput()` compares `editNewValue` to `editCurrentValue` — if they match, the dialog stays in input phase with the original value shown, preventing a no-op SET GLOBAL. All `with*()` methods use `clone $this` and return a new instance, keeping `update()` and `updateDialog()` pure.
Canonical: `VariablesPage::updateDialog()` → `updateDialogInput()` / `updateDialogConfirm()` → `executeEdit()` → `editor->edit()` → `withEditDialog()` (immutable state transition).
Source: step 4.1 ai/candy-query-variables-edit-dialog

### 2026-06-04 — VariableEditor persist methods: SET PERSIST / SET PERSIST_ONLY / RESET PERSIST (STEP 4.2)
Pattern: `VariableEditor` now provides three MySQL 8.0+ persist methods gated by `$this->context->version()->isAtLeast(8, 0)`: `persist()` emits `SET PERSIST x = ?` (global + persisted), `persistOnly()` emits `SET PERSIST_ONLY x = ?` (persisted only, no runtime effect — for static vars that hit error 1238), and `resetPersist(?x)` emits `RESET PERSIST [x]` (no isEditable() check since it removes persisted state). All three use prepared statements with backtick-escaped variable names. The 'p' key in the VariablesPage edit dialog cycles GLOBAL → PERSIST → PERSIST_ONLY mode; the current mode is stored in `editPersistMode` and reflected in the prompt and SQL preview. Error 1238 message suggests pressing [p] to use PERSIST_ONLY. `getEditPreview()` signature changed from `bool $persistent` to `string $mode` ('global'|'persist'|'persist_only').
Canonical: `VariablesPage::updateDialog('p')` cycles `editPersistMode` via MODE_GLOBAL → MODE_PERSIST → MODE_PERSIST_ONLY; `executeEdit()` routes via `match`; `VariableEditor::persist()/persistOnly()/resetPersist()` each gated on `isAtLeast(8, 0)`.
Source: step 4.2 ai/candy-query-variables-persist

### 2026-06-04 — `dynamic` vs `editable`: two-field variable metadata + JSON expansion to 1563 entries (STEP 4.3)
Pattern: `VariableMetadata` now carries two distinct boolean flags:
- **`editable`** = the variable can be set at all via `SET GLOBAL` or `SET PERSIST`. Read-only vars (e.g. `version`, `system_time_zone`) have `editable: false`.
- **`dynamic`** = the variable can be changed at runtime without a server restart. Static vars (e.g. `innodb_log_file_size`) accept `SET GLOBAL` but fail with MySQL error 1238 and require a restart. These have `editable: true, dynamic: false`.

The edit dialog in `VariablesPage` gates on `isDynamic()` (not `isEditable()`) so that static variables reach the confirm phase and get a clear error 1238 message rather than silently refusing at the entry point. `Catalog::isDynamic()` delegates to `VariableMetadata::isDynamic()` and falls back to `true` for entries missing the `dynamic` key (19 vars: `binlog_format`, `transaction_isolation`, `sql_mode`, `server_id`, `ssl_*`, etc. — most are genuinely dynamic in MySQL).

`data/variable_metadata.json` expanded from ~43 to 1563 entries using `wb_admin_variable_list.py` as the canonical upstream source (1544 system vars scraped). Strategy: `editable = name NOT IN ro_persistable_set` (360 read-only-persistable vars); `dynamic` from upstream Python tuple bool. Result: 1376 editable, 187 read-only, 628 dynamic, 935 static.

Spot-check verified: `max_connections` (editable+dynamic ✓), `innodb_log_file_size` (editable=true, dynamic=false ✓), `version` (editable=false ✓), `wait_timeout` (editable+dynamic ✓), `audit_log_buffer_size` (ro_persistable, editable=false ✓).
Canonical: `VariableMetadata::__construct()` — `dynamic` defaults to `true` for backward compat with existing JSON entries lacking the field; `Catalog::isDynamic()` — `get()` then `isDynamic()`.
Source: step 4.3 ai/candy-query-variables-metadata-catalog

### 2026-06-04 — PerfSchema version gating + SetupTimers mutable + SetupThreads INSTRUMENTED (STEP 5.1)
Pattern: `PerfSchemaPage` applies MySQL version gating at load time for three tables:
- `loadActors()` returns `[]` on MySQL <5.6 (`setup_actors` was introduced in 5.6)
- `loadObjects()` omits the `ENABLED` column on MySQL <5.6.3 (the column was added in 5.6.3; older versions only have `TIMED`)
- `loadTimers()` loads `setup_timers` on MySQL <8.0 (mutable — `UPDATE setup_timers SET timer_name=? WHERE name=?` via `SetupTimers::commitStatements()`) and `performance_timers` on MySQL >=8.0 (read-only, fixed at server build time)

`SetupTimers` is now mutable via the `Mutable` trait: `withTimerName(string)` returns a new instance with `dirty=true` and `changeType=CHANGE_UPDATE`; `commitStatements()` emits the `UPDATE` SQL when dirty and an update type. On >=8.0 the timer list is loaded from `performance_timers` as clean (non-dirty) instances — `commitStatements()` returns `[]` and no write occurs.

`SetupThreads` carries the `INSTRUMENTED` column from `performance_schema.threads` and exposes `withInstrumented(bool)` + `isDirty()` for tracking per-thread changes, plus `instrumentedFragment()` which generates a `THREAD_ID = N AND INSTRUMENTED = 'YES'/'NO'` SQL fragment. Batch UPDATE wiring to `CommitPlanner` is deferred to STEP 5.2 — currently `CommitPlanner::commitAll()` does not include SetupThreads/SetupTimers statements; the class-level docblock was updated to reflect the deferred-wiring reality.

Version gating is asserted via `FakeDatabase` doubles in CI (testMySQL56 checks 5.5.62 → actors return `[]`; testMySQL562 checks 5.6.2 → ENABLED column omitted; 8.0 → timers read-only). Live-server smoke testing on real MySQL versions deferred to STEP 8.1.
Canonical: `PerfSchemaPage::loadTimers()` — `if ($version->isAtLeast(8, 0)) { return $this->loadPerformanceTimers($db); }` else load from `setup_timers`; `SetupTimers::commitStatements()` — `UPDATE performance_schema.setup_timers SET TIMER_NAME = 'NANOSECOND' WHERE NAME = 'wait'`.
Source: step 5.1 ai/candy-query-perfschema-gating-models

### 2026-06-04 — PerfSchema RLIKE fix: anchored patterns + regex escaping + parameterized SQL (STEP 5.2)
Pattern: `SetupInstruments::commitStatements()` previously wrapped the instrument name in backticks for the RLIKE pattern (`\`name\``), which caused the regex to match literal backtick characters in the name. The fix uses `preg_quote()` with an anchor: `^` + escaped name + `$`. This correctly handles instrument names containing metacharacters like `.`, `/`, `(`, `)` — e.g. `statement/sql/abstract.test(group)` becomes `^statement/sql/abstract\\.test\\(group\\)$`. `CommitPlanner` generates parameterized `UPDATE` statements with `?` placeholders and returns `list<array{sql:string, params:list<mixed>}>` — all values bound, none interpolated. Instruments are bucketed by `(enabled, timed)` pair; a single `UPDATE ... RLIKE '^(name1|name2)$'` covers all instruments in each bucket.
Canonical: `CommitPlanner::commitInstruments()` — buckets dirty instruments by (enabled, timed), builds alternation pattern `^(name1|name2|...)$`, returns `['sql' => 'UPDATE ... SET ENABLED = ?, TIMED = ? WHERE NAME RLIKE ?', 'params' => ['YES', 'YES', '^name1$|^name2$']]`.
Source: step 5.2 ai/candy-query-perfschema-commit-tree

### 2026-06-04 — InstrumentTree cascade methods: setChildrenEnabled/setChildrenTimed (STEP 5.2)
Pattern: `InstrumentTree` now exposes `setChildrenEnabled(bool)` and `setChildrenTimed(bool)` which recursively mark all instruments at or below a node with the given state. They walk the tree, call `->withEnabled(bool)` or `->withTimed(bool)` (which return new immutable instances) on each leaf instrument, collect all modified copies, then call `invalidateCache()` to reset cached tri-state values. They return `list<SetupInstruments>` of all modified instruments — the caller (PerfSchemaPage) uses this to rebuild the flat list for the next render. The methods exist but are not yet wired to keyboard input (group rows are displayed with tri-state badges but SPACE/Enter on a group row has no effect yet — DEFERRED to STEP 5.3 for keyboard wiring).
Canonical: `InstrumentTree::setChildrenEnabled(bool $enabled): list<SetupInstruments>` — traverses `->children[]`, calls `$child->setChildrenEnabled()` recursively, merges modified lists.
Source: step 5.2 ai/candy-query-perfschema-commit-tree

### 2026-06-04 — InstrumentTree flattening for indented tri-state tree render (STEP 5.2)
Pattern: `PerfSchemaPage::flattenTree(InstrumentTree): array` returns `list<array{0:InstrumentTree|null, 1:int, 2:bool}>` — a flat list of `[nodeOrInstrument, depth, isGroup]` triples. Group nodes (intermediate path nodes with `instrument === null`) and instrument leaf nodes are interleaved in tree order. `renderInstrumentsTab()` uses depth for indentation and calls `Badge::tristate()` for group nodes (where null means "mixed" state = `[~]`). Instrument leaf nodes render with individual `[x]`/`[ ]` badges. `pathDepth()` on each `InstrumentTree` node returns the number of path segments (root=0, `wait`=1, `wait/io`=2, etc.) for indent calculation.
Canonical: `flattenTree()` — `foreach ($tree->children() as $child) { $results[] = [$child, $child->pathDepth(), $child->instrument() === null]; ... }`; `renderInstrumentsTab()` — indentation via `str_repeat('  ', $depth)`.
Source: step 5.2 ai/candy-query-perfschema-commit-tree

### 2026-06-04 — EasySetupDetector: four-state detection with version-gated defaults (STEP 5.3)
Pattern: `EasySetupDetector::detect()` returns one of four states: `fully` (all instruments enabled+timed AND all consumers enabled), `disabled` (no consumers enabled AND no instruments enabled/timed), `default` (matches Appendix C default profile for the detected version), `custom` (anything else). The detection uses `enabledPercentage() < 100` as a guard before detailed checks — a server with <100% instruments enabled cannot be `fully`, regardless of what the disabled/untimed counts show. The `isFullyDisabled()` method exists but is NOT wired to `detect()` — it is available for future use.

Version-gated default profiles (Appendix C):
- MySQL 5.6 instruments: `wait/io/file/%`, `wait/io/table/%`, `wait/lock/table/sql/handler`, `statement/%`, `idle`
- MySQL 5.7+ instruments: same five patterns (stage/% was removed in 5.7 but not re-added to defaults)
- MySQL 5.6 consumers: `events_statements_current`, `events_transactions_current`, `global_instrumentation`, `thread_instrumentation`
- MySQL 5.7+ consumers: adds `statements_digest`

Key subtlety: `isFullyEnabled()` requires all instruments to be both `enabled='YES'` AND `timed='YES'` — a server with all instruments enabled but any timed='NO' is `custom`, NOT `fully`. This matches the MySQL Workbench spec which defines "fully" as all consumers enabled AND all instruments enabled+timed. memory/% instruments are excluded from all calculations (they require special handling).

Detection is wired from `App::adminPage()` → `PerfSchemaPage` via `EasySetupDetector::fromContext($context)`. `detectSetupState()` (which uses loaded instrument data only, without TIMED or consumer checks) serves as a fallback when no detector is wired.

`DEFAULT_INSTRUMENTS_57` had a stale doc comment referencing `wait/sga/%` which was removed in the fix PR.
Canonical: `EasySetupDetector::detect()` → `isDisabled()` → `isFullyEnabled()` → `isDefaultSetup()` → `custom`; `DEFAULT_INSTRUMENTS_57` / `DEFAULT_CONSUMERS_57` version-gated via `$this->version->isAtLeast(5, 7)`.
Source: step 5.3 ai/candy-query-docs-5.3

### 2026-06-04 — EasySetup: Appendix C default sets with version-gated consumers (STEP 5.3)
Pattern: `EasySetup` provides SQL toggle statements for PS setup and defines the canonical Appendix C default sets. Default instruments (same for 5.6 and 5.7): `wait/io/file/%`, `wait/io/table/%`, `wait/lock/table/sql/handler`, `statement/%`, `idle`. Default consumers: 5.6 has four (`events_statements_current`, `events_transactions_current`, `global_instrumentation`, `thread_instrumentation`); 5.7+ adds `statements_digest`. `resetToDefaultStatements()` disables all non-default instruments, then re-enables default instruments with both ENABLED and TIMED set to YES, and resets consumers to the version-appropriate defaults. The class is version-aware — `Version` is passed at construction and `defaultInstruments()`/`defaultConsumers()` return the correct set. memory/% instruments are excluded from all toggle operations.
Canonical: `EasySetup::resetToDefaultStatements()` — builds `UPDATE ... WHERE NAME NOT LIKE 'wait/io/file/%' AND NAME NOT LIKE ... SET ENABLED='NO', TIMED='NO'` for non-defaults; `EasySetup::defaultConsumers()` / `defaultInstruments()` check `$version->isAtLeast(5, 7)`.
Source: step 5.3 ai/candy-query-docs-5.3

### 2026-06-04 — Hub-admin privileges for PS setup writes (STEP 5.3)
Pattern: Modifying Performance Schema setup tables (`setup_instruments`, `setup_consumers`, `setup_actors`, `setup_objects`) requires the `PROCESS` privilege. `PerfSchemaPage::hasUpdatePrivilege()` tests this by running a no-op `UPDATE ... SET ENABLED=ENABLED WHERE 1=0` — if it succeeds, the user can modify PS configuration. When the privilege is absent, the page renders in read-only mode (`readOnlyMode=true`) and all `[1]`/`[2]`/`[3]` Easy Setup actions and inline toggles are disabled. Error codes 1142 (SELECT/INSERT/UPDATE denied) and 1227 (DDL denied) trigger the read-only state. Operators provisioning PS monitoring should be granted `PROCESS` on the target MySQL instance.
Canonical: `PerfSchemaPage::hasUpdatePrivilege()` — `UPDATE performance_schema.setup_consumers SET ENABLED=ENABLED WHERE 1=0`; `hasUpdatePrivilege()` result stored in `$this->readOnlyMode`.
Source: step 5.3 ai/candy-query-docs-5.3

### 2026-06-04 — ServerStatusSnapshotAdapter: bridging ServerContext to StatusSnapshotProviderInterface (STEP 6.1)
Pattern: `Sampler` requires a `StatusSnapshotProviderInterface` to compute two-sample rate deltas. `ServerContextInterface` does not implement this interface — it only exposes `statusVariables()` and `statusVariablesTs()`. `ServerStatusSnapshotAdapter` wraps the context and caches the last snapshot internally, delegating `currentSnapshot()` to `$this->context->statusVariables()`. This allows the `Sampler` to call `sample()` and `poll()` on each gauge refresh cycle without modifying `ServerContextInterface`. The adapter is created in `App::adminPage()` and passed alongside the `Sampler` to `ServerStatusPage::new()`.
Canonical: `ServerStatusSnapshotAdapter` constructor takes `ServerContextInterface`; `currentSnapshot()` calls and caches `$this->context->statusVariables()`; `statusVariablesTs()` delegates to `$this->context->statusVariablesTs()`.
Source: step 6.1 ai/candy-query-sampler-gauges

### 2026-06-04 — SidebarGaugeSet: Sampler wiring + removal of mislabeled CPU gauge (STEP 6.1)
Pattern: `SidebarGaugeSet` now uses an optional `Sampler` to compute per-second rate deltas for the Traffic gauge. On first poll the sampler is null (no prior snapshot) — `computeTrafficRatio()` falls back to absolute bytes with 10MB/s baseline; from the second poll onward it uses sampler rates (`Bytes_received`/`Bytes_sent`). The `GaugeType::Cpu` case was removed — MySQL exposes no CPU status variable; the gauge was mislabeled as CPU but computed `threads_connected/max_connections` (a connections ratio, not CPU). Key-efficiency uses the single correct formula `Key_reads / (Key_reads + Key_read_requests)` — the earlier formula that computed `Key_writes / (Key_reads + Key_writes)` was incorrect and has been removed. `Sampler` advances via `poll()` called in `withRefresh()` (rebuilds gauge set + polls to get fresh sampler state).
Canonical: `SidebarGaugeSet::computeTrafficRatio()` — uses `$rates['Bytes_received'] + $rates['Bytes_sent']` when sampler is available, falls back to `$statusVars['Bytes_received'] + $statusVars['Bytes_sent']`; `computeKeyEfficiencyRatio()` — `Key_reads / (Key_reads + Key_read_requests)`.
Caveat: Sampler rates depend on `SHOW GLOBAL STATUS` polling cadence — `ServerStatusSnapshotAdapter` reads `$this->context->statusVariablesTs()` for the elapsed-time denominator, which reflects the actual poll interval.
Source: step 6.1 ai/candy-query-sampler-gauges

### 2026-06-04 — TimeSeriesCell: `max()` vs `array_sum()` for tuple timelines (STEP 6.3)
Pattern: When `Widget::compute()` returns an associative array of per-series rates (e.g. `['Com_select' => 10.0, 'Com_insert' => 5.0]` from `MakeTuple`/`TupleRatePerSecond`), `TimeSeriesCell::ingest()` now uses `max($value)` instead of `array_sum($value)`. Summing unrelated counter series produces meaningless totals — e.g. summing SELECT and INSERT rates gives 15, which corresponds to no real metric. Showing the dominant (max) series gives an informative lower bound on the most active series. Multi-series timeline rendering (separate polylines per series) requires broader `LineChart` changes and is deferred.
Canonical: `TimeSeriesCell::ingest()` — `is_array($value) ? max($value) : $value`; `CounterCell::ingest()` still uses `array_sum()` since counters are additive by design.
Source: step 6.3 ai/candy-query-docs-6.3

### 2026-06-04 — MeterCell: `$value`/`$max` tracking + `viewLevel()` value/max readout (STEP 6.3)
Pattern: `MeterCell` now tracks `$value` (raw computed value) and `$max` (resolved maximum from `max_connections` etc.) as separate fields alongside `$ratio`. `viewLevel()` uses `sprintf($widget->format, (int)$value, (int)$max)` to render a `Connections` style `X / Y` readout when the widget has a non-trivial format string (not `'%s'` or empty). This enables level meters to display actual values alongside the gauge bar.
Canonical: `MeterCell::ingest()` — stores `$this->value` and `$this->max` before ratio computation; `viewLevel()` — `sprintf($format, (int)$this->value, (int)$this->max)` when `$format !== '' && $format !== '%s'`.
Source: step 6.3 ai/candy-query-docs-6.3

### 2026-06-04 — DashboardPage: `elapsed` from `lastPollAt` + per-section widget cache (STEP 6.3)
Pattern: `pollAndUpdateCells()` now measures `elapsed` as the actual wall-clock delta from `$this->lastPollAt` (via `microtime(true)`) rather than using a fixed 3.0s assumption. On the very first poll (when `lastPollAt` is null), a 3.0s fallback is used — this is only hit once since `lastPollAt` is set after the first update. Per-section widget lists are built once in the constructor via `buildSectionWidgetCache()`, producing `$this->sectionWidgetCache['network']`, `['mysql']`, `['innodb']`. Previously, `getWidgetsForSection()` called `WidgetRegistry::*()` on every render frame, causing the catalog to re-detect version on every frame.
Canonical: `pollAndUpdateCells()` — `$elapsed = $this->lastPollAt !== null ? max(0.001, $now - $this->lastPollAt) : 3.0`; `buildSectionWidgetCache()` called from constructor; `getWidgetsForSection()` reads from `$this->sectionWidgetCache`.
Source: step 6.3 ai/candy-query-docs-6.3

### 2026-06-04 — InnoDB buffer pool: bytes-based formula (Appendix A) replaces page-count formula (STEP 6.3)
Pattern: `InnoDBBufferPoolUsageBytes` computes buffer pool usage as `(Innodb_buffer_pool_bytes_data / Innodb_page_size) / Innodb_buffer_pool_pages_total * 100` — the MySQL Workbench Appendix A formula for the sidebar gauge. This replaces `InnoDBBufferPoolUsage` which used the simpler `(total - free) / total * 100` on page counts. The old `InnoDBBufferPoolUsage` class was deleted in PR #1061 (dead after migration). The bytes-based formula is more accurate because it accounts for partially-filled pages.
Canonical: `InnoDBBufferPoolUsageBytes::compute()` — `$usedPages = $bytesData / $pageSize; return ($usedPages / $pagesTotal) * 100.0`.
Source: step 6.3 ai/candy-query-docs-6.3

### 2026-06-04 — WidgetCatalog: 8 new InnoDB widgets (STEP 6.3)
Pattern: `WidgetCatalog::innodb()` expanded from the previous 5 InnoDB widgets (Buffer Pool Read Reqs, Buffer Pool Write Reqs, Buffer Pool Usage, Disk Reads, Redo Log Bytes/Log Writes/Doublewrite/Disk Writes/Disk Reads) to 13 by adding: Row Lock Waits (`Innodb_row_lock_waits`), Row Lock Time (`Innodb_row_lock_time`), Pages Flushed (`Innodb_pages_flushed`), Pages Created (`Innodb_pages_created`), Pages Read (`Innodb_pages_read`), Insert Buffer (`Innodb_ibuf_size`), Read Ahead (`Innodb_buffer_pool_read_ahead`). Buffer Pool Usage switched to `InnoDBBufferPoolUsageBytes` (bytes-based, Appendix A). `WidgetRegistry::innodb()` and `build()` automatically include the new widgets.
Canonical: `WidgetCatalog::innodb()` — 13 entries, all using `RatePerSecond` except Buffer Pool Usage (bytes-based calc) and Insert Buffer (raw `Innodb_ibuf_size`).
Source: step 6.3 ai/candy-query-docs-6.3

### 2026-06-04 — ReplicaStatusProvider: flavor-aware queries + multi-channel support (STEP 6.4)
Pattern: `ReplicaStatusProvider::chooseQuery()` gates on `Flavor` and `Version` to select the correct SHOW command: MariaDB uses `SHOW ALL SLAVES STATUS` (supports multi-channel replication); MySQL 8+ uses `SHOW REPLICA STATUS`; MySQL 5.x uses `SHOW SLAVE STATUS`. `fetchStatus()` returns `list<array<string, scalar>>` (all channels); `lastFetchKind()` returns a `ReplicaStatusKind` enum (`Configured`, `NotConfigured`, `PermissionDenied`, `Error`) for UI branching. Error 1227 detection checks both `$e->getCode()` (string `'1227'` or `'42000'`) and message pattern (`str_contains('command denied') && str_contains('replica'|'slave')`) since PDO error code format varies by driver. `isReplicaCommandDenied()` handles the PDO mysql driver format vs. the sqlsrv driver format.
Canonical: `ReplicaStatusProvider::chooseQuery()` — `if ($flavor === Flavor::MariaDB) return 'SHOW ALL SLAVES STATUS'; if ($flavor === Flavor::MySQL && $version->major >= 8) return 'SHOW REPLICA STATUS'; return 'SHOW SLAVE STATUS'`.
Source: step 6.4 ai/candy-query-docs-6.4

### 2026-06-04 — GTID mode selector: [g] dialog + [c] cycling + GtidMode enum (STEP 6.4)
Pattern: `GtidMode` enum carries the five valid GTID_MODE values (`OFF`, `OFF_PERMISSIVE`, `OFF_SECURE`, `ON_PERMISSIVE`, `ON`) ordered for cycling. `GtidMode::values()` returns them in cycling order for the `[c]` key. `GtidMode::requiresGtidOn()` returns true for `ON` and `ON_PERMISSIVE`. The `[g]` key in `ServerStatusPage::update()` opens the GTID dialog only when `version->isAtLeast(5, 7, 6)` — GTID is not available before MySQL 5.7.6. The dialog initializes `gtidModeEdit` from `gtid_mode` server variable and `[c]` cycles through `GtidMode::values()` array modulo length. `[Enter]` executes `SET @@GLOBAL.GTID_MODE = <mode>` via `$connection->exec()` — GTID_MODE is always an identifier from the whitelist, not user free-text.
Canonical: `ServerStatusPage::updateGtidDialog()` — `c` key: `$modes = GtidMode::values(); $nextIdx = ($currentIdx + 1) % count($modes); $clone->gtidModeEdit = $modes[$nextIdx]->value`.
Source: step 6.4 ai/candy-query-docs-6.4

### 2026-06-04 — Stored programs detection via information_schema.ROUTINES (STEP 6.4)
Pattern: `ServerStatusPage::hasStoredPrograms()` detects stored procedures/functions by querying `SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN ('information_schema', 'performance_schema', 'mysql') LIMIT 1`. The exclusion list prevents counting built-in system routines as user-defined stored programs. The query uses `LIMIT 1` for efficiency — we only care whether count > 0. The query is wrapped in try/catch returning `false` on any throwable (permission errors, etc.). This pattern works across all MySQL and MariaDB versions since `information_schema.ROUTINES` has existed since MySQL 5.0.
Canonical: `ServerStatusPage::hasStoredPrograms()` — `COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA NOT IN ('information_schema', 'performance_schema', 'mysql')`.
Source: step 6.4 ai/candy-query-docs-6.4

### 2026-06-04 — Firewall detection: mysql_firewall_mode + AUDIT plugin fallback (STEP 6.4)
Pattern: `ServerStatusPage::hasFirewall()` first checks `mysql_firewall_mode` server status variable (MySQL Enterprise Firewall sets this to `ON`/`OFF` when the firewall plugin is installed). If absent, it iterates `plugins()` looking for `audit` or `firewall` (lowercased) — some managed/cloud MySQL instances expose firewall capability via AUDIT plugin presence rather than the dedicated firewall variable. The `mysql_firewall_mode` check is primary because it directly indicates firewall state, not just plugin presence.
Canonical: `ServerStatusPage::hasFirewall()` — primary: `$serverVars['mysql_firewall_mode'] !== null && strtoupper($fwMode) === 'ON'`; fallback: `foreach ($plugins as $plugin) if (in_array(strtolower($plugin['Name'] ?? ''), ['audit', 'firewall']))`.
Source: step 6.4 ai/candy-query-docs-6.4

### 2026-06-04 — MetricKind enum: per-metric toast formatting (STEP 7.2)
Pattern: `Alert::toToastMessage()` branches on `MetricKind` to format value/threshold for display rather than applying a blanket `* 100` conversion. `Ratio` (0.0–1.0) multiplies by 100 for percentage display; `Seconds` appends "s" suffix; `Count` renders as a plain integer. `AlertManager` passes the appropriate `MetricKind` to each alert factory call — `MetricKind::Seconds` for slow_query_time, `MetricKind::Count` for connection_errors, `MetricKind::Ratio` for everything else. This distinguishes true ratios (e.g. connection_usage at 0.75 → "75.0%") from raw durations (e.g. slow_query at 5.2 → "5.2s") and absolute counts (e.g. connection_errors at 150 → "150").
Canonical: `Alert::toToastMessage()` — `match ($this->metricKind) { MetricKind::Ratio => sprintf(..., $this->value * 100, ...), MetricKind::Seconds => sprintf(..., $this->value, ...), MetricKind::Count => sprintf(..., (int) $this->value, ...) }`.
Source: step 7.2 ai/candy-query-docs-7.2

### 2026-06-04 — Alert dedup via breached-key state tracking (STEP 7.2)
Pattern: `DashboardPage` maintains `$breachedAlertKeys` (array of currently-breached alert keys from the previous poll cycle). On each `checkAlerts()` call, `array_diff_key($currentKeys, $previousKeys)` isolates keys that are newly breached and only those trigger a toast notification via `AlertNotifier`. This means a threshold that remains breached across consecutive 3s polls fires the toast exactly once — on the transition from clean to breached — rather than on every tick. When the breach clears, the key drops out of `$alerts` naturally, so the next breach re-fires. Pressing `[a]` clears `$pendingAlerts` and resets `$breachedAlertKeys = []` to allow the same alert to re-fire if desired.
Canonical: `DashboardPage::checkAlerts()` — `$currentKeys = array_flip(array_keys($alerts)); $newKeys = array_diff_key($currentKeys, $this->breachedAlertKeys); ... foreach ($alerts as $key => $alert) { if (isset($newKeys[$key])) { $this->alertNotifier = $this->alertNotifier->notify($alert); } } $this->breachedAlertKeys = $currentKeys;`.
Source: step 7.2 ai/candy-query-docs-7.2

### 2026-06-04 — Float-epoch microsecond timestamps in history (STEP 7.2)
Pattern: `microtime(true)` returns a float with sub-second precision (e.g. `1717500000.123456`). SQLite stores these as REAL (float) in the `ts` column. To reconstruct an exact `DateTimeImmutable` from a stored float, extract integer seconds and microseconds separately via integer arithmetic before combining with `DateTimeImmutable::createFromFormat('U u', "{$sec} {$usec}")` — this avoids floating-point rounding that `setTimestamp()` would introduce. Conversely, when binding a `DateTimeImmutable` as a SQLite parameter, `->format('U.u')` serialises sub-second precision as a string that SQLite compares correctly against the stored REAL.
Canonical: `floatToDateTimeImmutable(float $ts): \DateTimeImmutable` — `$sec = (int) $ts; $usec = (int) (($ts - $sec) * 1_000_000); return \DateTimeImmutable::createFromFormat('U u', "{$sec} {$usec}")`.
Caveat: `DateTimeImmutable::createFromFormat('U u', ...)` returns `false` on PHP < 8.3 if the microsecond part is exactly zero — the `?:` fallback to `new \DateTimeImmutable()` catches this.
Source: step 7.2 ai/candy-query-docs-7.2
