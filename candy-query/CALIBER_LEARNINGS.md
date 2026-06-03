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
