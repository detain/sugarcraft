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
