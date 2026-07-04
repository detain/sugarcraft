---
status: complete-except-1.8 (voryx/pgasync replacement — out of scope this pass)
phase: 1
updated: 2026-07-04
---

# Implementation Plan: candy-query Code Review Findings

## Goal
Address all critical, high, medium, and low severity findings from candy-query code review including SQL injection risks, deprecated dependencies, architectural issues, and missing async patterns.

## Context & Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Use `Identifier::quote()` for all identifier quoting | Centralized, tested quoting logic already exists at `src/Db/Identifier.php:31-38` | findings/candy-query.md |
| Replace `voryx/pgasync` with `react/pg` | Actively maintained ReactPHP PostgreSQL client, compatible with PHP 8.3+ | findings/candy-query.md |
| Use error codes instead of message strings | Locale-independent, reliable across MySQL configurations | findings/candy-query.md |
| Make `AdminQueryCache` injectable rather than static | Enables testability and multi-instance coexistence | findings/candy-query.md |
| Add atomic operations for `lastUptime` access | Prevents race conditions in concurrent access patterns | findings/candy-query.md |

## Phase 1: Critical SQL Injection Fixes [PENDING]

- [x] **1.1 Fix MysqlDatabase::rows() identifier quoting** (done on master — Identifier::quote)
  - File: `src/Db/MysqlDatabase.php:100-107`
  - Replace `str_replace('`', '``', $table)` with `Identifier::quote(Flavor::MySQL, $table)`
  - Severity: CRITICAL
  - What: The comment claims "backtick identifiers are properly escaped via placeholder" but `str_replace` is not proper escaping. A table name like `` `; DROP TABLE users; -- `` would not be correctly handled.
  - Why: Prevents SQL injection through crafted table names
  - Verification: `vendor/bin/phpunit` passes for candy-query, new test for backtick in table name

- [x] 1.2 Fix PostgresDatabase::rows() identifier quoting — **done on master**
  - File: `src/Db/PostgresDatabase.php:83-86`
  - Replace `str_replace('"', '""', $table)` with `Identifier::quote(Flavor::Postgres, $table)`
  - Severity: CRITICAL
  - What: Same str_replace anti-pattern for PostgreSQL identifiers
  - Why: PostgreSQL identifiers can contain Unicode, spaces, and special characters

- [x] 1.3 Fix SqliteDatabase::rows() identifier quoting — **done on master**
  - File: `src/Db/SqliteDatabase.php:60`
  - Replace `str_replace('"', '""', $table)` with `Identifier::quote(Flavor::Sqlite, $table)`
  - Severity: CRITICAL
  - What: Same str_replace anti-pattern for SQLite
  - Why: Consistency with the dedicated Identifier class

- [x] 1.4 Fix MysqlSchemaProvider::columns() identifier quoting — **done on master**
  - File: `src/Schema/MysqlSchemaProvider.php:53`
  - Replace `$this->db->quote($table)` with `Identifier::quote($this->flavor, $table)`
  - Severity: CRITICAL
  - What: Uses `$this->db->quote()` which quotes string VALUES, not identifiers
  - Why: Semantically wrong - value quoting for identifiers could fail with special characters

- [x] 1.5 Fix MysqlSchemaProvider::indexes() identifier quoting — **done on master**
  - File: `src/Schema/MysqlSchemaProvider.php:80`
  - Replace `$this->db->quote($table)` with `Identifier::quote($this->flavor, $table)`
  - Severity: CRITICAL

- [x] 1.6 Fix MysqlSchemaProvider::foreignKeys() identifier quoting — **done on master**
  - File: `src/Schema/MysqlSchemaProvider.php:115`
  - Replace `$this->db->quote($table)` with `Identifier::quote($this->flavor, $table)`
  - Severity: CRITICAL

- [x] 1.7 Fix PreviewQuery::columnsSql() PostgreSQL identifier quoting — **done on master**
  - File: `src/Db/PreviewQuery.php:65-72`
  - Replace `str_replace("'", "''", $table)` with `Identifier::quote(Flavor::Postgres, $table)`
  - Severity: CRITICAL
  - What: Uses VALUE escaping (single-quote doubling) instead of IDENTIFIER escaping (double-quote doubling)
  - Why: Introspection query fails with table names containing single quotes

- [ ] 1.8 Replace voryx/pgasync with react/pg
  - File: `composer.json:45` and `src/Admin/ReactPostgresConnection.php`
  - Replace `"voryx/pgasync": "^2.2"` with `react/pg` (nicola-shanley/pg)
  - Rewrite ReactPostgresConnection to use the new library
  - Severity: CRITICAL
  - What: voryx/pgasync is unmaintained and has known incompatibilities with PHP 8.3+
  - Why: Async PostgreSQL support is effectively broken in production with PHP 8.3+

## Phase 2: High Severity Issues [PENDING]

- [x] 2.1 Replace string matching with error codes in isIgnorableError() — **done on master — IGNORABLE_ERROR_CODES**
  - File: `src/Admin/ServerContext.php:269-282`
  - Use error codes (1142, 1146, 1227, 2002, 2003, 2013) instead of locale-dependent message strings
  - Severity: HIGH
  - What: `str_contains()` checks against English substrings fail on non-English MySQL servers
  - Why: MySQL error messages are returned in the server's locale

- [x] 2.2 Make AdminQueryCache injectable — **done 2026-07-04 — public ctor + injectable in CachedConnection/CachingServerContext/ReconnectManager**
  - File: `src/Admin/AdminQueryCache.php:30`
  - Pass AdminQueryCache as constructor dependency; keep static instance() for convenience
  - Severity: HIGH
  - What: Static singleton makes testing difficult and prevents multi-instance coexistence
  - Why: Enables proper dependency injection and testability

- [x] 2.3 Add atomic operations for lastUptime race condition — **done 2026-07-04 — snapshot-local read/compare/write in detectReset()**
  - File: `src/Admin/ServerContext.php:252-264`
  - Use PHP 8.1+ Atomic class or mutex for concurrent access protection
  - Severity: HIGH
  - What: `statusVariables()` can be called concurrently, leading to incorrect reset detection
  - Why: One fetch might update lastUptime after another has read it but before it writes

- [x] 2.4 Add invalidateConnection() to AdminQueryCache — **done 2026-07-04 — invoked from ReconnectManager::attemptReconnect (Db layer must not import Admin, see LayeringTest)**
  - File: `src/Admin/AdminQueryCache.php:121-129`
  - Clear cached ReactMysqlConnection after MysqlDatabase::reconnect()
  - Severity: HIGH
  - What: AdminQueryCache holds old ReactMysqlConnection after reconnect, async queries use dead connection
  - Why: connectionKey doesn't change after reconnect, stale connection is reused

## Phase 3: Medium Severity Issues [PENDING]

- [ ] 3.1 Remove public PDO from deprecated Database class — **DEFERRED pre-1.0 — breaking API change; see candy-query/CALIBER_LEARNINGS.md**
  - File: `src/Database.php:51`
  - Remove `public readonly \PDO $pdo` property; use interface access only
  - Severity: MEDIUM
  - What: public readonly on mutable PDO provides false sense of immutability
  - Why: Violates encapsulation

- [x] 3.2 Extract shared cache TTL constant — **done 2026-07-04 — src/Admin/CacheTtl.php (STATUS/SERVER)**
  - File: `src/Admin/ServerContext.php:22-23` and `src/Admin/AdminQueryCache.php:28`
  - Define `DEFAULT_CACHE_TTL = 3.0` and use consistently
  - Severity: MEDIUM
  - What: Magic numbers 3.0 and 30.0 duplicated across files with different names
  - Why: Confusing redundancy

- [ ] 3.3 Group App constructor parameters into value objects — **DEFERRED pre-1.0 — large refactor; see candy-query/CALIBER_LEARNINGS.md**
  - File: `src/App.php:82-109`
  - Reduce 29 parameters to ~10-15 by grouping into AdminState, TableBrowseState, etc.
  - Severity: MEDIUM
  - What: Constructor with 29 parameters is unwieldy and difficult to test
  - Why: Improves maintainability and testability

- [ ] 3.4 Clarify caching wrapper naming — **DEFERRED pre-1.0 — cosmetic churn; see candy-query/CALIBER_LEARNINGS.md**
  - Files: `src/Admin/CachedConnection.php` vs `src/Admin/CachingServerContext.php`
  - Rename to AsyncCachedConnection and AsyncCachingServerContext
  - Severity: MEDIUM
  - What: Inconsistent naming makes codebase harder to navigate
  - Why: Clarifies that these are tied to async fetch path

- [x] 3.5 Extract shared DsnParser utility — **done on master — src/Admin/DsnParser.php**
  - Files: `src/Admin/ReactMysqlConnection.php:86-97` vs `src/Admin/ReactPostgresConnection.php:78-84`
  - Consolidate extractDsnValue() into shared DsnParser class
  - Severity: MEDIUM
  - What: Nearly identical DSN parsing with subtle regex differences
  - Why: DRY principle

## Phase 4: Low Severity Issues [PENDING]

- [x] 4.1 Have PreviewQuery::quoteIdent() delegate to Identifier::quote() — **done 2026-07-04 — quoteIdent removed**
  - File: `src/Db/PreviewQuery.php:138-141`
  - Remove duplicate escaping logic; delegate to Identifier::quote()
  - Severity: LOW
  - What: quoteIdent() duplicates Identifier::quote() functionality
  - Why: DRY principle

- [x] 4.2 Extract magic number 3.0 in App::subscriptions() to constant — **done 2026-07-04 — CacheTtl::STATUS**
  - File: `src/App.php:892`
  - Extract throttle delay 3.0 to named constant referencing STATUS_CACHE_TTL
  - Severity: LOW
  - What: Same magic number used in multiple places with different meanings
  - Why: Code clarity

- [x] 4.3 Pin react/mysql to stable release compatible with PHP 8.3 — **WONTFIX 2026-07-04 — no tagged 0.7.x exists on Packagist (latest v0.6.0 lacks the MysqlClient API in use)**
  - File: `composer.json:44`
  - Replace `react/mysql: 0.7.x-dev` with stable release
  - Severity: LOW
  - What: 0.7.x-dev version stability may not be compatible with PHP 8.3+
  - Why: Ensure PHP 8.3 compatibility

## Phase 5: Missing Features [PENDING]

- [ ] 5.1 Add query cancellation mechanism — **DEFERRED pre-1.0 — needs KILL QUERY side-channel; see candy-query/CALIBER_LEARNINGS.md**
  - Files: `src/Admin/ReactMysqlConnection.php`, `src/Admin/ReactPostgresConnection.php`
  - Implement CancellablePromiseInterface for async queries
  - Severity: LOW
  - What: No mechanism to cancel in-flight async queries
  - Why: Long-running queries waste bandwidth and CPU

- [x] 5.2 Add query timeout for async queries — **done 2026-07-04 — QueryTimeout::wrap via react/promise-timer, configurable ctor param, 30s default, <=0 disables**
  - Files: `src/Admin/ReactMysqlConnection.php`, `src/Admin/ReactPostgresConnection.php`
  - Configure timeout on MysqlClient::query() or use TimeoutPromise
  - Severity: LOW
  - What: No timeout for async queries - network partition causes indefinite hang
  - Why: Prevent infinite waits

- [x] 5.3 Document known limitations — **done 2026-07-04 — README "Known limitations"**
  - No connection pooling, no transaction support, no prepared statement caching
  - Severity: LOW
  - What: Document these as known limitations in README
  - Why: Set user expectations

## Phase 6: Duplicated Logic Refactoring [PENDING]

- [x] 6.1 Consolidate error message matching to error codes only — **done-with-rationale 2026-07-04 — codes are primary; str_contains kept as documented supplementary fallback for drivers/proxies that drop codes**
  - Files: `src/Admin/Connections/ConnectionActions.php:154-176`
  - Replace string matching in isAclError(), isConnectionError(), isUnknownThreadError()
  - Severity: MEDIUM
  - What: Same string-matching fragility as isIgnorableError()
  - Why: Locale-independent error handling

## Investigation Summary

### Files examined:
- `src/Db/MysqlDatabase.php` - Lines 101-105: str_replace backtick anti-pattern confirmed
- `src/Db/PostgresDatabase.php` - Lines 83-86: str_replace double-quote anti-pattern confirmed
- `src/Db/SqliteDatabase.php` - Line 60: str_replace double-quote anti-pattern confirmed
- `src/Db/Identifier.php` - Lines 31-38: Correct Identifier::quote() implementation exists
- `src/Schema/MysqlSchemaProvider.php` - Lines 53, 80, 115: Uses quote() for identifiers confirmed
- `src/Db/PreviewQuery.php` - Lines 65-72: Value escaping used for identifier confirmed
- `src/Admin/ServerContext.php` - Lines 22-23: Magic numbers confirmed; Lines 269-282: String matching confirmed
- `src/Admin/AdminQueryCache.php` - Line 30: Static singleton confirmed; Lines 121-129: No invalidation on reconnect
- `composer.json` - Line 45: voryx/pgasync confirmed
- `src/Database.php` - Line 51: public readonly PDO confirmed
- `src/Admin/ReactMysqlConnection.php` - Lines 86-97: DSN parsing with regex confirmed
- `src/Admin/ReactPostgresConnection.php` - Lines 78-84: DSN parsing different regex confirmed
- `src/Admin/Connections/ConnectionActions.php` - Lines 154-176: String matching error handling confirmed

### Existing tests:
- `tests/IdentifierTest.php` - Comprehensive tests for Identifier::quote() covering all flavors
- `tests/Db/MysqlDatabaseTest.php` - Tests for MysqlDatabase behavior
- `tests/Admin/ServerContextTest.php` - Tests for ServerContext including error handling

## Verification

After implementing all changes:
```bash
cd candy-query && composer install && vendor/bin/phpunit
```

All tests must pass.

## Notes

- 2026-06-30: Plan created based on comprehensive code review findings in `/home/sites/sugarcraft/findings/candy-query.md`
- Phase 1 (CRITICAL SQL injections) must be completed first before any other phase
- Tests should be added alongside each fix, not after
- Priority order: Phase 1 (Critical) → Phase 2 (High) → Phase 3 (Medium) → Phase 4-6 (Low/Refactoring)
