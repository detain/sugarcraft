# Code Review: candy-query

## 1. Summary

**candy-query** is a terminal SQLite/MySQL/PostgreSQL browser — a port of `jorgerojas26/lazysql` on the SugarCraft TUI stack. It provides an interactive CLI for browsing database tables, executing SQL queries, and administering MySQL/PostgreSQL servers through an admin pane featuring dashboard widgets, process lists, variable editors, and performance schema tools. The library bridges synchronous PDO operations (SQLite) with ReactPHP async drivers (MySQL via `react/mysql`, PostgreSQL via `voryx/pgasync`) for non-blocking queries. Key components include `MysqlDatabase`, `PostgresDatabase`, and `SqliteDatabase` implementations of `DatabaseInterface`, an `AdminQueryCache` singleton for coordinating async admin queries, `ServerContext` for caching MySQL server metadata, and a `PreviewQuery` class for blob-safe row previews.

The codebase shows signs of technical debt: SQL injection risks in identifier handling across all three database drivers, deprecated/unmaintained async PostgreSQL driver (`voryx/pgasync`), fragile string-matching error classification, process-global singleton state that complicates testing, and an inconsistent approach to connection reuse after reconnection. The `rows()` methods in all three database drivers use naive `str_replace` for identifier quoting, which fails to properly escape all edge cases. Additionally, `MysqlSchemaProvider` uses `quote()` — a *value*-quoting method — for table/column *identifiers*, which is semantically incorrect and could lead to injection in certain contexts.

## 2. Critical Issues

### SQL Injection in Identifier Handling — `src/Db/MysqlDatabase.php:101-105`

```php
$sql = sprintf(
    'SELECT * FROM `%s` LIMIT %d',
    str_replace('`', '``', $table),
    $limit,
);
```

The comment claims "Safe: backtick identifiers are properly escaped via placeholder" but `str_replace('`', '``', $table)` is not a proper escaping mechanism. A crafted table name like `` `; DROP TABLE users; -- `` would not be correctly handled. MySQL's identifier escaping requires doubling *every* backtick in the identifier, but this only handles existing backticks — it does not validate or sanitize other characters that could be exploited in edge cases with Unicode or complex identifiers. The library already has `Identifier::quote()` at `src/Db/Identifier.php:35` which implements this correctly, but `MysqlDatabase::rows()` does not use it.

**Recommendation:** Replace `str_replace('`', '``', $table)` with `Identifier::quote(Flavor::MySQL, $table)`.

### SQL Injection in Identifier Handling — `src/Db/PostgresDatabase.php:83-86`

```php
$sql = sprintf(
    'SELECT * FROM "%s" LIMIT %d',
    str_replace('"', '""', $table),
    $limit,
);
```

Same anti-pattern as MySQL: `str_replace('"', '""', $table)` handles only existing double-quotes but does not validate the input. PostgreSQL identifiers can contain Unicode, spaces, and special characters. A table name containing characters outside the valid identifier set could cause parsing issues or potentially be exploited. `Identifier::quote()` at `src/Db/Identifier.php:37` already handles this correctly.

**Recommendation:** Replace `str_replace('"', '""', $table)` with `Identifier::quote(Flavor::Postgres, $table)`.

### SQL Injection in Identifier Handling — `src/Db/SqliteDatabase.php:60`

```php
$sql = sprintf('SELECT * FROM "%s" LIMIT %d', str_replace('"', '""', $table), $limit);
```

Same `str_replace` anti-pattern for SQLite. While SQLite is more permissive with identifier naming, this approach is still inconsistent with the dedicated `Identifier` class.

**Recommendation:** Use `Identifier::quote(Flavor::Sqlite, $table)`.

### Incorrect Use of Value Quoting for Identifiers — `src/Schema/MysqlSchemaProvider.php:53,80,115`

```php
$safeTable = $this->db->quote($table);  // Line 53
```

The code uses `$this->db->quote()` — which quotes *string values* — for table names, then interpolates the result directly into SQL:

```php
"WHERE table_schema = DATABASE() AND table_name = {$safeTable} "
```

`PDO::quote()` escapes string values by surrounding them with quotes and escaping internal quotes (e.g., `O'reilly` becomes `'O''reilly'`). For table names in the `WHERE table_name = {$safeTable}` context, this produces SQL that looks like `table_name = 'mytablename'`, which MySQL will implicitly convert — but this is semantically wrong since `quote()` is for string literals, not identifiers. The code happens to work by accident because MySQL's automatic type coercion converts the string to an identifier in this context, but this is fragile and could break with different MySQL configurations or in edge cases with special characters.

**Recommendation:** Use `Identifier::quote(Flavor::MySQL, $table)` for all identifier quoting.

### Value Escape Used as Identifier Escape — `src/Db/PreviewQuery.php:65-72`

```php
Flavor::Postgres => 'SELECT column_name, data_type FROM information_schema.columns '
    . "WHERE table_schema = CURRENT_SCHEMA() AND table_name = '"
    . str_replace("'", "''", $table)
    . "' ORDER BY ordinal_position",
```

`str_replace("'", "''", $table)` is **value escaping** (single-quote doubling), not identifier escaping. For PostgreSQL identifiers, the correct approach is double-quote doubling. While `PreviewQuery::build()` at line 121 correctly uses `self::quoteIdent()` which doubles the correct quote character, the `columnsSql()` method mixes value-style escaping (`'` + `''`) for what is semantically an identifier. This inconsistency means the introspection query could fail or misbehave with table names containing single quotes.

**Recommendation:** Use `Identifier::quote(Flavor::Postgres, $table)` in `columnsSql()` for Postgres flavor.

### Deprecated/Unmaintained Async PostgreSQL Library — `composer.json:45`

```json
"voryx/pgasync": "^2.2",
```

`voryx/pgasync` is unmaintained and has known incompatibilities with PHP 8.3+. The library's last meaningful update was years ago, and it relies on RxPHP observables for async flow, which itself has compatibility issues with modern PHP. Async PostgreSQL support is effectively broken in production environments running PHP 8.3+.

**Recommendation:** Replace `voryx/pgasync` with `react/pg` (nicola-shanley/pg), which is the actively maintained ReactPHP PostgreSQL client, or implement a PDO-based synchronous fallback with async wrapping.

## 3. High Severity Issues

### Fragile Error Message String Matching — `src/Admin/ServerContext.php:269-282`

```php
private function isIgnorableError(\PDOException $e): bool
{
    $code = $e->getCode();
    if ($code === '42000') {
        return true;
    }
    $message = strtolower($e->getMessage());
    return str_contains($message, 'access denied')
        || str_contains($message, 'command denied')
        || (str_contains($message, 'table') && str_contains($message, 'doesn\'t exist'))
        || str_contains($message, 'can\'t connect')
        || str_contains($message, 'lost connection')
        || str_contains($message, 'timeout');
}
```

This approach fails on localized MySQL error messages. MySQL error messages are returned in the server's locale, so a German or Chinese MySQL server would return messages in that language, causing `str_contains()` checks against English substrings to fail silently. This means access-denied errors on non-English MySQL installations would propagate as exceptions rather than being gracefully handled as ignorable.

**Recommendation:** Use MySQL error codes (which are locale-independent) instead of message strings. The code already checks for `42000` (SQL state for access denied) — extend this pattern to check specific error codes like `1142` (SELECT privilege denied), `1227` (access denied), `1146` (table doesn't exist).

### Process-Global Singleton for State — `src/Admin/AdminQueryCache.php:30`

```php
private static ?self $instance = null;
```

`AdminQueryCache` uses a static singleton pattern that:
1. Makes testing difficult since tests must call `AdminQueryCache::reset()` to clear state
2. Prevents multiple `App` instances from coexisting in the same process with independent cache state
3. Creates hidden global state that can cause subtle bugs in long-running processes

The comment at lines 21-23 acknowledges this: *"This is a deliberate process-global: App is rebuilt immutably on every update(), so the live cache cannot live on the model."* However, this design decision trades testability and multi-instance safety for convenience.

**Recommendation:** Pass `AdminQueryCache` as a constructor dependency (injectable), with the static `instance()` kept as a fallback for the render path but removed from core logic. Consider using `AdminQueryCache` as a model property.

### Race Condition in Uptime Detection — `src/Admin/ServerContext.php:252-264`

```php
private function detectReset(): void
{
    $uptime = $this->statusVariablesCache['Uptime'] ?? null;
    if ($uptime === null) {
        return;
    }

    $currentUptime = (int) $uptime;
    if ($this->lastUptime !== null && $currentUptime < $this->lastUptime) {
        $this->wasResetCache = true;
    }
    $this->lastUptime = $currentUptime;
}
```

`lastUptime` is an instance property, but `statusVariables()` can be called concurrently from multiple code paths (synchronous `ServerContext::serverVariables()` and the async admin fetch path). When multiple fetches race, one fetch might update `lastUptime` after another has already read it but before it writes, leading to incorrect reset detection. Additionally, if `statusVariables()` is called concurrently with `refresh()`, the cache could be cleared mid-read.

**Recommendation:** Synchronize access to `lastUptime` with a lock or make it an atomic operation. Consider using `Swoole\Atomic` or PHP 8.1's `Atomic` class for safe concurrent updates.

### Async Connection Not Invalidated on Reconnect — `src/Admin/AdminQueryCache.php:121-129`

```php
public function connection(string $key, \Closure $factory): AsyncConnection
{
    if ($this->connection === null || $this->connectionKey !== $key) {
        $this->connection = $factory();
        $this->connectionKey = $key;
    }

    return $this->connection;
}
```

When `MysqlDatabase::reconnect()` is called (triggered by `ReconnectManager` after a connection error), the `AdminQueryCache` still holds the old `ReactMysqlConnection` which wraps the old PDO connection. The `connectionKey` is based on DSN + username, which does not change after a reconnect, so the stale `ReactMysqlConnection` is reused. This means async queries after a reconnect would use the dead connection.

**Recommendation:** Add an `invalidate()` method to `AdminQueryCache` that clears the cached connection, and call it from `MysqlDatabase::reconnect()` or from the `ReconnectManager` after a successful reconnect.

## 4. Medium Severity Issues

### Deprecated `Database` Class Exposes PDO — `src/Database.php:51`

```php
public function __construct(public readonly \PDO $pdo)
```

The `Database` class (which wraps `SqliteDatabase`) is deprecated but still exposes the underlying `PDO` instance publicly. This violates encapsulation and makes it impossible to change the PDO wrapper implementation without breaking consumers. Additionally, `public readonly \PDO $pdo` with `readonly` on a property that is a mutable object (`PDO`) provides a false sense of immutability — the object's internal state can still be modified.

**Recommendation:** Remove the `public readonly \PDO $pdo` property or make it private. All access should go through the `DatabaseInterface` methods.

### Magic Numbers Not Extracted — `src/Admin/ServerContext.php:22-23`

```php
private const STATUS_CACHE_TTL = 3.0;
private const SERVER_CACHE_TTL = 30.0;
```

These magic numbers are used in two places: `ServerContext` (status: 3.0s, server: 30.0s) and `AdminQueryCache` (TTL: 3.0s). The redundancy is confusing — are these meant to be the same TTL? If so, they should reference a shared constant. If not, the naming should clarify the distinction.

**Recommendation:** Define a single `DEFAULT_CACHE_TTL = 3.0` constant and reference it consistently, or clarify the distinction between `STATUS_CACHE_TTL` and `SERVER_CACHE_TTL` in comments.

### Large Constructor with 29 Properties — `src/App.php:82-109`

The `App` constructor has 29 parameters. While this follows the immutable `with*()` pattern correctly, a constructor this large is difficult to use and to test. Builder patterns or a named-constructor (`App::start()`, `App::builder()`) mitigate this but the constructor itself remains unwieldy.

**Recommendation:** Group related parameters into value objects (e.g., `AdminState`, `TableBrowseState`, `QueryEditorState`) to reduce the parameter count to ~10-15.

### Inconsistent Naming for Caching Wrappers — `src/Admin/CachedConnection.php` vs `src/Admin/CachingServerContext.php`

`CachedConnection` and `CachingServerContext` serve similar purposes (providing cached data) but have inconsistent naming. One is a `Connection` wrapper, the other is a `ServerContext` wrapper. This makes the codebase harder to navigate.

**Recommendation:** Rename one or both to clarify the distinction, e.g., `CachedDatabaseConnection` and `CachingServerContext` are already better, but could be further clarified as `AsyncCachedConnection` and `AsyncCachingServerContext` to emphasize they are tied to the async fetch path.

### Duplicated `extractDsnValue` Logic — `src/Admin/ReactMysqlConnection.php:86-97` vs `src/Admin/ReactPostgresConnection.php:78-84`

Both `ReactMysqlConnection` and `ReactPostgresConnection` implement `extractDsnValue()` with slightly different regexes:

```php
// ReactMysqlConnection:
if (preg_match("/(?:mysql:)?(?<=\Amysql:|(?<=;)|(?<=\A)){$key}=([^;]+)/", $dsn, $matches)) {

// ReactPostgresConnection:
if (preg_match("/{$key}=([^;]+)/", $dsn, $matches)) {
```

The MySQL version has more sophisticated anchoring to handle the `mysql:` prefix, while the Postgres version is simpler. These should be consolidated into a shared utility or base class.

**Recommendation:** Extract `extractDsnValue()` to a shared `DsnParser` utility class.

## 5. Low Severity Issues

### Missing Query Cancellation Mechanism

There is no mechanism to cancel in-flight async queries. If a user navigates away from a table or cancels a query, the pending promise continues running until it resolves or rejects. For long-running queries against large remote tables, this could waste network bandwidth and CPU.

**Recommendation:** Implement cancellation tokens using `React\Promise\CancellablePromiseInterface` and pass them through the query pipeline.

### No Connection Pooling Beyond Single Connection Reuse

`AdminQueryCache::connection()` creates at most one `ReactMysqlConnection` or `ReactPostgresConnection` per DSN key. There is no true connection pooling for high-throughput scenarios. For a CLI tool this is likely fine, but it limits the library's utility for concurrent multi-query workloads.

**Recommendation:** Document this as a known limitation, or implement a simple round-robin pool if needed.

### No Transaction/Savepoint Support

None of the database classes (`MysqlDatabase`, `PostgresDatabase`, `SqliteDatabase`) implement transaction support (begin, commit, rollback, savepoint). This is a significant limitation for any data modification workflow.

**Recommendation:** Add transaction methods to `DatabaseInterface` and implement them in each driver.

### No Query Timeout Configuration

There is no way to set a maximum execution time for queries. A long-running query (e.g., an accidental full table scan) can hang the application indefinitely. `PostgresDatabase` has `StatementTimeout` at `src/Admin/Resilience/StatementTimeout.php` but this is only used in the admin/Postgres context, not in the main query path.

**Recommendation:** Add a `setQueryTimeout(int $seconds)` method to `DatabaseInterface` and implement it per-driver.

## 6. Missing Features

| Feature | Description |
|---------|-------------|
| Query timeout | No per-query timeout configuration |
| Connection pooling | Single connection per DSN, no pooling |
| Transaction/savepoint support | No begin/commit/rollback API |
| Query cancellation | No cancellation token support for in-flight queries |
| Batch query support | No way to send multiple queries in one round-trip |
| Prepared statement caching | Prepared statements are created per-call and not cached |
| Connection health checks | No periodic ping/keepalive for long-running connections |
| Automatic reconnection for async path | `ReactMysqlConnection` has no reconnect logic |

## 7. Duplicated Logic / Refactoring Opportunities

| Location | Issue | Recommendation |
|----------|-------|----------------|
| `MysqlDatabase::rows()`, `PostgresDatabase::rows()`, `SqliteDatabase::rows()` | All use hand-rolled `str_replace` quoting instead of `Identifier::quote()` | Centralize to `Identifier::quote(Flavor, string)` |
| `ReactMysqlConnection::extractDsnValue()`, `ReactPostgresConnection::extractDsnValue()` | Nearly identical DSN parsing with subtle regex differences | Extract to shared `DsnParser` utility |
| `ServerContext::STATUS_CACHE_TTL` (3.0), `AdminQueryCache::TTL` (3.0) | Same value duplicated with different names | Use shared constant |
| `ConnectionActions::isAclError()`, `isConnectionError()`, `isUnknownThreadError()` | String-matching on error messages (same fragility as `isIgnorableError`) | Use error codes only |
| `PreviewQuery::quoteIdent()` (line 138-141) vs `Identifier::quote()` | Functionally similar but implemented independently | Have `PreviewQuery::quoteIdent()` call `Identifier::quote()` |

## 8. Compatibility Issues

| Issue | Details |
|-------|---------|
| `react/mysql` 0.7.x-dev | The `0.7.x-dev` version constraint may not be compatible with PHP 8.3+ in all configurations. Should be pinned to a stable release or a version confirmed to work with PHP 8.3. |
| `voryx/pgasync` unmaintained | `voryx/pgasync` is not compatible with PHP 8.3+. Async PostgreSQL support is effectively broken on modern PHP. |
| PerfSchema MySQL 8.0+ requirement | `ConnectionActions::setInstrumentation()` at line 77 uses `performance_schema.threads` which requires MySQL 8.0+. The test at `tests/Admin/Connections/ConnectionActionsTest.php` does not appear to mock this version check. |
| Magic number in `App::subscriptions()` | The throttle delay `3.0` at line 892 is hardcoded rather than referencing `STATUS_CACHE_TTL`. |

## 9. Async Pattern Improvements

### No Connection Invalidation on MysqlDatabase Reconnect

When `MysqlDatabase::reconnect()` successfully re-establishes the PDO connection, the `AdminQueryCache` still holds the old `ReactMysqlConnection`. The async path continues using the dead connection for queries. The cache key (`$connKey`) does not change after a reconnect since it's based on DSN + username, so `AdminQueryCache::connection()` returns the stale connection.

**Improvement:** After a successful reconnect in `MysqlDatabase`, call `AdminQueryCache::invalidateConnection()` to force creation of a fresh `ReactMysqlConnection`.

### No Cancellation Token Support

Async queries created by `ReactMysqlConnection::query()` and `ReactPostgresConnection::query()` return plain `PromiseInterface` without implementing `CancellablePromiseInterface`. There is no way to cancel a query that the user has abandoned (e.g., navigating away from a table).

**Improvement:** Wrap promises with `React\Promise\CancellablePromise` and wire cancellation to close the underlying connection or send a `KILL` query.

### No Timeout Handling for Async Queries

`ReactMysqlConnection::query()` has no timeout — a query that never returns (e.g., network partition) will hold the connection forever. The `react/mysql` library supports timeouts but they are not configured here.

**Improvement:** Pass a timeout option to `MysqlClient::query()` using `react/mysql`'s timeout support, or wrap the promise with `React\Promise\TimeoutPromise`.

### ReactPostgresConnection Uses Deprecated voryx/pgasync

`ReactPostgresConnection` (line 7) uses `voryx/PgAsync\Client` which is unmaintained and incompatible with PHP 8.3+. The promise adapter via RxPHP (`->toArray()->toPromise()`) adds unnecessary overhead and compatibility risk.

**Improvement:** Replace with `react/pg` (nicola-shanley/pg) which is actively maintained and built specifically for ReactPHP.

## 10. Recommendations Summary

| Priority | File:Line | Issue | Recommendation |
|----------|-----------|-------|----------------|
| CRITICAL | `src/Db/MysqlDatabase.php:101-105` | `str_replace('`', '``', $table)` is not proper identifier escaping | Use `Identifier::quote(Flavor::MySQL, $table)` |
| CRITICAL | `src/Db/PostgresDatabase.php:83-86` | `str_replace('"', '""', $table)` same issue | Use `Identifier::quote(Flavor::Postgres, $table)` |
| CRITICAL | `src/Db/SqliteDatabase.php:60` | Same `str_replace` anti-pattern | Use `Identifier::quote(Flavor::Sqlite, $table)` |
| CRITICAL | `src/Schema/MysqlSchemaProvider.php:53` | Uses `quote()` (value escaping) for identifiers | Use `Identifier::quote(Flavor::MySQL, $table)` |
| CRITICAL | `src/Db/PreviewQuery.php:65-72` | `str_replace("'", "''", $table)` is value escaping, not identifier escaping | Use `Identifier::quote(Flavor::Postgres, $table)` |
| CRITICAL | `composer.json:45` | `voryx/pgasync` unmaintained, incompatible with PHP 8.3+ | Replace with `react/pg` |
| HIGH | `src/Admin/ServerContext.php:269-282` | String matching on localized error messages fails on non-English MySQL | Use error codes instead of message strings |
| HIGH | `src/Admin/AdminQueryCache.php:30` | Static singleton complicates testing and prevents multi-instance coexistence | Make cache injectable; keep static factory for convenience |
| HIGH | `src/Admin/ServerContext.php:252-264` | `lastUptime` updated/read without synchronization | Add atomic operations or mutex for concurrent access |
| HIGH | `src/Admin/AdminQueryCache.php:121-129` | Connection not invalidated after `MysqlDatabase::reconnect()` | Add `invalidateConnection()` method and call on reconnect |
| MEDIUM | `src/Database.php:51` | Deprecated `Database` exposes `public readonly \PDO` | Remove public PDO property; use interface access only |
| MEDIUM | `src/Admin/ServerContext.php:22-23` | Magic numbers `3.0` and `30.0` duplicated across files | Extract shared constant with clarifying name |
| MEDIUM | `src/App.php:82-109` | 29-constructor-parameter `App` is unwieldy | Group into value objects (~10-15 params max) |
| MEDIUM | `src/Admin/CachedConnection.php` | Naming inconsistent with `CachingServerContext` | Clarify naming: `AsyncCachedConnection` vs `CachingServerContext` |
| MEDIUM | `src/Admin/ReactMysqlConnection.php:86-97`, `src/Admin/ReactPostgresConnection.php:78-84` | Duplicated `extractDsnValue` with different regex | Extract to shared `DsnParser` utility |
| LOW | `src/Db/PreviewQuery.php:138-141` | `quoteIdent()` duplicates `Identifier::quote()` logic | Have `quoteIdent()` delegate to `Identifier::quote()` |
| LOW | `src/Admin/ServerContext.php:892` | Magic number `3.0` throttle delay | Extract to constant |
| LOW | `composer.json:44` | `react/mysql: 0.7.x-dev` version stability | Pin to stable release compatible with PHP 8.3 |
