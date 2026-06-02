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
