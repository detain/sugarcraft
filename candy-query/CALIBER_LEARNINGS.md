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

### 2026-06-02 — restart detection via uptime comparison
Pattern: Record server uptime at construction (`Sampler::registerUptime()`) and compare uptime snapshots across polls to detect MySQL restarts. When uptime decreases (wraps or resets), clear cached state before it becomes stale.
Source: step-7.1 ai/resilience

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
