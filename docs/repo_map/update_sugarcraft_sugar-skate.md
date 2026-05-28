# Overview

**sugar-skate** is a pure-PHP personal key/value store and the definitive PHP port of `charmbracelet/skate`. It provides multi-database isolation via SQLite file-per-database architecture, binary data storage via base64 encoding, glob pattern matching (translated to SQL LIKE), ordered listing, TTL/expiry, Levenshtein-based typo suggestions, and JSON/YAML import/export. The library is built with `final` classes, `declare(strict_types=1)`, PSR-4, and requires only PHP 8.3+ with `ext-sqlite3`.

**Biggest opportunity areas:**
1. Encryption at rest for sensitive data protection
2. Cross-database atomic transactions
3. Query capabilities beyond glob (range queries, value filtering, full-text search)
4. Background compaction/vacuum scheduling
5. Concurrent access handling

**Biggest missing capabilities:**
1. No encryption/authentication at rest
2. No atomic cross-database operations
3. No indexing for Levenshtein acceleration on large key sets
4. No query/filter on values beyond key patterns
5. No compaction/garbage collection for WAL journals

---

# Internal Capability Summary

## Current Architecture

### Package Structure
```
sugar-skate/
├── bin/skate                          # CLI entry point (280 lines)
├── src/
│   ├── Store.php                      # Main API (340 lines) - key routing, database caching
│   ├── Database.php                  # SQLite wrapper (345 lines) - glob→SQL LIKE translation
│   ├── Entry.php                     # Value object (95 lines)
│   ├── Lang.php                      # i18n facade (22 lines)
│   ├── Cli/ExportCommand.php         # Export handler (136 lines)
│   ├── Cli/ImportCommand.php         # Import handler (73 lines)
│   └── Import/
│       ├── JsonImporter.php          # JSON import (131 lines)
│       └── YamlImporter.php          # YAML import with fallback parser (213 lines)
├── lang/                             # 17 locales
├── tests/                            # 56 test methods total
└── examples/                         # 5 example scripts
```

### Core Components

**Store** (`src/Store.php`) - Top-level API dispatching to per-database SQLite connections:
- Multi-database via suffix syntax (`key@dbname`)
- Database connection caching (`$this->databases[]`)
- Data directory: `$XDG_CONFIG_HOME/skate/` or `~/.config/skate/` with `0o700` permissions
- Levenshtein typo suggestions on key miss (suggests similar **keys** within database, not database names)
- TTL support via `setWithTtl()` wrapper
- File-based binary storage via base64 encoding

**Database** (`src/Database.php`) - SQLite wrapper per `.db` file:
- Schema: `entries(key, value, binary, created, modified, expires_at)`
- WAL journal mode + foreign keys enabled
- Legacy migration via `ALTER TABLE` for `expires_at` column
- Glob pattern translation: `*`→`%`, `?`→`_` with escaping
- Atomic transactions via `BEGIN IMMEDIATE`/`COMMIT`/`ROLLBACK`
- Generator-based `list()` for memory efficiency

**Entry** (`src/Entry.php`) - Value object with 6 readonly properties:
- `isExpired()` - checks if `expiresAt <= now`
- `rawValue()` - returns base64-decoded bytes if binary, original value otherwise
- `Entry::binary(string $key, string $bytes)` - factory for binary entries

**Import/Export System**:
- `JsonImporter`: reads `{"key": "value", "_ttl": {"key": 3600}}` format
- `YamlImporter`: reads `skate_ttl_key: 3600` entries, fallback YAML parser included
- Atomic import via reflection accessing `Store::$databases`
- Multi-database atomic import throws `RuntimeException` (documented limitation)

### CLI Interface (`bin/skate`)

| Command | Description |
|---------|-------------|
| `set <key> [value]` | Store value, reads stdin if no value, `--ttl=SECONDS` |
| `get <key>` | Retrieve value, prints typo suggestion to stderr on miss |
| `list [-k\|-v] [-r] [-d delim] [pattern]` | List entries with glob patterns |
| `delete <key>` | Delete key, supports glob patterns |
| `list-dbs` | Show all databases |
| `import <json\|yaml> <path>` | Bulk import, `--no-atomic` flag |
| `export <json\|yaml> [db] [pattern]` | Bulk export |

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|------|-----------|---------------------------|----------|
| `charmbracelet/skate` | Direct upstream | Levenshtein suggestions, multi-database pattern, BadgerDB backend, CLI interface | Critical |
| `charmbracelet/bubbletea` | TUI framework reference | Elm architecture, command pattern, cell-based rendering, batch/sequence commands | High |
| `charmbracelet/lipgloss` | Styling reference | Immutable style patterns, color systems, border rendering, CSS-like shorthand | Medium |
| `charmbracelet/huh` | Form/interaction reference | Validation, theming, keyboard navigation, accessible mode | Medium |
| `charmbracelet/gum` | CLI tool reference | Shell-friendly CLI design, exit codes, stdin/stdout separation | Medium |
| `erikgeiser/promptkit` | Prompt patterns | Generic type-safe prompts, BubbleTea integration, template rendering | Medium |
| `pterm/pterm` | CLI component reference | TextPrinter interface, live printers, fuzzy search, theme system | Low |
| `ratatui/ratatui` | Rust TUI reference | Buffer diffing, constraint-based layout, widget traits, stateful widgets | Low |
| `charmbracelet/soft-serve` | Full app reference | SQLite/PostgreSQL backend choice, security model, production operations | Low |

---

# Feature Gap Analysis

## Critical Priority

### 1. Encryption at Rest
**Title:** Data encryption for stored values and keys

**Description:** All data stored in plain files in `~/.config/skate/` — anyone with file access can read everything including keys, values, and TTL metadata.

**Why it matters:** Privacy-sensitive data (passwords, tokens, personal notes) stored via sugar-skate are fully readable. This limits the library's use for credentials and sensitive configuration.

**Source repo:** N/A - no external repo provides encryption for simple KV stores in the same way

**Source discussion:** `charmbracelet/skate` explicitly removed cloud sync and went local-only, acknowledging security concerns but not implementing encryption. The Go implementation stores plain files same as PHP.

**Implementation ideas:**
- Add `Store::encrypt(string $key, string $value, string $passphrase): string` helper
- Add `Store::decrypt(string $key, string $encrypted, string $passphrase): ?string`
- Use `openssl_encrypt()`/`openssl_decrypt()` with AES-256-GCM
- Store encrypted flag in Entry record
- Consider key derivation via `PBKDF2` or `Argon2`
- Could add as optional layer in `Store` or separate `SecureStore` wrapper

**Estimated complexity:** Medium - requires passphrase management, key derivation, and API design decisions

**Expected impact:** High - enables sensitive data storage use case

### 2. Query Beyond Glob Patterns
**Title:** Value-based filtering and range queries

**Description:** Currently only glob patterns on keys work. No ability to query by value content, range (numeric or temporal), or full-text search.

**Why it matters:** Users cannot find entries where `value > 100` or `value contains "error"`. This severely limits the library to pure key-value access rather than a lightweight database.

**Source repo:** `charmbracelet/skate` has same limitation

**Source PR/issue:** Go skate Issue #142 "Query/filter by value" - deferred for v2

**Implementation ideas:**
- Add `Store::find(callable $filter): \Generator` for arbitrary value filtering
- Add `Store::range(string $dbName, ?string $minKey, ?string $maxKey): \Generator`
- Add `Entry::matches(string $pattern)` for regex/value matching
- SQLite FTS5 virtual table for full-text search on values
- Add `Store::search(string $query): \Generator` using FTS5

**Estimated complexity:** Medium - needs API design, SQLite FTS5 integration

**Expected impact:** High - transforms from KV store to queryable store

## High Value

### 3. Cross-Database Atomic Transactions
**Title:** Multi-database transaction support

**Description:** Multi-database atomic import explicitly throws `RuntimeException` because SQLite transactions are per-connection. No way to atomically write to multiple `.db` files.

**Why it matters:** Users cannot import data atomically across multiple logical databases. If one fails, partial data may already be committed to separate files.

**Source repo:** N/A - inherent SQLite limitation

**Implementation ideas:**
- Implement two-phase commit pattern across multiple databases
- Add `Store::multiTransaction(array $ops): mixed` where $ops = [['db' => 'name', 'fn' => callable]]
- Document the limitation clearly with workaround suggestions
- Consider WAL mode limitations across multiple files

**Estimated complexity:** High - requires careful concurrency handling

**Expected impact:** Medium - enables more robust multi-database workflows

### 4. Levenshtein Performance on Large Key Sets
**Title:** Index-based fuzzy search acceleration

**Description:** `suggestSimilar()` loads all keys into PHP array via `allKeys()` and computes Levenshtein distance against every candidate. No optimization for databases with thousands of entries.

**Why it matters:** Performance degrades linearly with key count. Memory usage spikes on large databases.

**Source repo:** `charmbracelet/skate` - Go implementation has same approach

**Source discussion:** Go skate Issue #89 - acknowledged, no current solution

**Implementation ideas:**
- Implement trigram index for approximate string matching (MySQL-style)
- Use SQLite FTS5 for fuzzy search: `MATCH 'key*'` with prefix matching
- Add configurable distance threshold based on key count
- Consider `simhash` or `minhash` for candidate pre-filtering
- Add `Store::setSuggestThreshold(int $maxDistance)` configuration

**Estimated complexity:** Medium - requires indexing strategy and storage overhead

**Expected impact:** Medium - enables large database usage without performance degradation

### 5. Compaction and Vacuum Scheduling
**Title:** Automatic WAL journal cleanup

**Description:** WAL journal mode is enabled but no `VACUUM` scheduling exists. Journal files grow indefinitely with heavy write usage.

**Why it matters:** Over time, disk usage grows and read performance may degrade. No automated way to reclaim space.

**Source repo:** BadgerDB (Go skate's backend) has built-in compaction

**Source PR:** Dgraph Badger#323 - compaction design document

**Implementation ideas:**
- Add `Database::vacuum(): void` method
- Add `Store::vacuumAll(): void` for all databases
- Implement automatic vacuum after N writes (configurable)
- Add CLI command: `skate vacuum [db]`
- Consider read-only VACUUM to ` vacuumbdb` for SQLite

**Estimated complexity:** Low - SQLite has built-in VACUUM command

**Expected impact:** Medium - long-term storage health

## Medium Priority

### 6. Concurrent Access Support
**Title:** Multi-process locking and contention handling

**Description:** Single PHP process model. Multiple processes accessing same `.db` file will have locking contention. No advisory locking or optimistic concurrency control.

**Why it matters:** Web applications or long-running PHP scripts may have concurrent access needs.

**Source repo:** SQLite itself handles this via file locking but sugar-skate doesn't expose or manage it

**Implementation ideas:**
- Add `Database::lockExclusive(): void` / `Database::lockShared(): void`
- Implement retry logic with configurable timeout for busy locks
- Consider `BEGIN IMMEDIATE` as already used, but make configurable
- Add `Store::withLock(string $key, callable $fn): mixed` for critical sections

**Estimated complexity:** Medium - requires locking strategy and error handling

**Expected impact:** Low - most usage is single-process CLI

### 7. Binary Data Native Storage
**Title:** Avoid base64 overhead for binary data

**Description:** Binary data is base64 encoded, increasing size by ~33%. No native binary storage despite SQLite supporting BLOB type.

**Why it matters:** Storing images, documents, or other binary data wastes space and adds encoding/decoding overhead.

**Source repo:** `charmbracelet/skate` uses BadgerDB which stores raw bytes

**Implementation ideas:**
- Store BLOB directly in SQLite `value BLOB` column instead of TEXT
- Add `Entry::isBinary()` to detect storage format
- Add `Entry::rawValue()` that doesn't base64 decode
- Maintain backward compatibility with existing base64-encoded entries
- Consider migration script for existing data

**Estimated complexity:** Medium - requires schema change and migration

**Expected impact:** Medium - saves space and CPU for binary data

### 8. Import/Export Enhancements
**Title:** Streaming export and incremental import

**Description:** Current import/export loads entire dataset into memory. No streaming support for large databases.

**Why it matters:** Exporting or importing databases with millions of entries can exhaust memory.

**Source repo:** `charmbracelet/skate` has no import/export at all (PHP-only feature)

**Implementation ideas:**
- Add `ExportCommand::toStream(resource $handle): void` for streaming export
- Add `JsonImporter::importStream(resource $handle): int` for streaming import
- Add progress callback support for long-running imports
- Consider chunked export (1000 entries per JSON object)
- Add `--compact` flag to export only keys (no values)

**Estimated complexity:** Medium - requires iterator-based approach

**Expected impact:** Medium - enables large dataset handling

## Low Priority

### 9. Interactive TUI Mode
**Title:** Full-screen browser for data exploration

**Description:** Currently pure CLI with no interactive browsing. Users cannot visually explore databases.

**Source repo:** `charmbracelet/skate` is CLI-only, but `charmbracelet/bubbletea` and `ratatui/ratatui` provide TUI frameworks

**Implementation ideas:**
- Implement `skate browse [db]` command using `candy-core`
- Browse entries with vim-style navigation
- Support search/filter within the browser
- Add interactive key-value editor
- Show database statistics (entry count, size, expiry distribution)

**Estimated complexity:** High - requires TUI framework integration

**Expected impact:** Low - most usage is scripted/automated

### 10. Database Statistics and introspection
**Title:** Metadata and analytics

**Description:** No way to get database statistics beyond listing entries.

**Why it matters:** Users cannot see total size, entry count by pattern, expiry distribution, or storage health.

**Source repo:** BadgerDB provides `badger.DB.GetThumbtable()` statistics

**Implementation ideas:**
- Add `Store::stats(string $dbName): DatabaseStats` with entry count, total size, WAL size
- Add `Store::oldestExpiry(): ?\DateTimeImmutable` for TTL analysis
- Add CLI command: `skate stats [db]`
- Consider `Database::analyze(): AnalysisResult` for SQLite ANALYZE integration

**Estimated complexity:** Low - straightforward aggregation queries

**Expected impact:** Low - administrative convenience feature

### 11. Shell Completion and Help Improvements
**Title:** Enhanced CLI discoverability

**Description:** Basic `--help` exists but no shell completion for keys, databases, or subcommands.

**Why it matters:** Users must type full key names; completion would improve UX.

**Source repo:** `charmbracelet/gum` provides completion generation

**Implementation ideas:**
- Add `skate complete bash` / `skate complete zsh` commands
- Support key completion for `get`, `delete`, `list` commands
- Support database name completion for `list-dbs`, `export`
- Generate completion scripts via CLI

**Estimated complexity:** Low - straightforward flag handling

**Expected impact:** Low - minor UX improvement

---

# Algorithm / Performance Opportunities

## Current Approach vs External Approach

### 1. Storage Backend: SQLite vs BadgerDB (LSM-tree)

**Current (sugar-skate):**
- SQLite with B-tree storage engine
- File-per-database architecture
- WAL journal mode for concurrency
- BLOB storage via base64 encoding

**External (Go skate + BadgerDB):**
- LSM-tree (Log-Structured Merge-tree) for append-only storage
- Directory-per-database architecture
- Concurrent read/write transactions
- Native binary storage
- Built-in compaction and garbage collection

**Why external is better:** LSM-tree provides better write throughput for heavy workloads, automatic compaction, and lower write amplification.

**Tradeoffs:** SQLite is more familiar to PHP developers, requires no native compilation, and is sufficient for personal CLI usage patterns. Badger requires Go.

**Applicability:** Low for personal use; high if scaling to server workloads.

### 2. Fuzzy Search: Full Scan vs Trigram Index

**Current:**
- `levenshtein()` computed against all keys on every miss
- O(n*m) time complexity per suggestion
- Memory: loads all keys into PHP array

**External (PostgreSQL trigram extension):**
- Trigram index on keys
- ` similarity(key, $1) > 0.3` for fast approximate matching
- Index-guided search, not full scan

**Why external is better:** Constant-time fuzzy search via index, not linear scan.

**Tradeoffs:** SQLite doesn't have native trigram extension. Would need to implement in PHP or use FTS5 with prefix matching as fallback.

**Applicability:** Medium - would significantly improve large database performance.

### 3. Data Directory Resolution

**Current (sugar-skate):**
- Respects `$XDG_CONFIG_HOME` environment variable
- Falls back to `~/.config/skate`
- Creates with `0o700` permissions

**External (Go skate via go-app-paths):**
- Cross-platform path resolution (`~/.local/share/charm/kv/` on Unix)
- Platform-specific conventions respected automatically

**Why external is better:** More robust cross-platform behavior out of the box.

**Tradeoffs:** PHP's native path functions work adequately for current supported platforms (Unix only mentioned in docs).

**Applicability:** Low - current approach is adequate.

### 4. TTL Storage: ISO 8601 TEXT vs Unix Epoch INTEGER

**Current:**
- Stores TTL as ISO 8601 datetime in TEXT column
- Human-readable, but requires string comparison

**External:**
- BadgerDB stores expiry as Unix timestamp (uint64)
- Efficient integer comparison for range queries

**Why external is better:** Integer comparison is faster than string comparison; enables efficient expiry-based range queries.

**Tradeoffs:** ISO 8601 is human-readable in database dumps. Integer would require conversion for display.

**Applicability:** Low - TTL comparison is infrequent operation.

---

# Architecture Improvements

## 1. Store Layer Simplification
**Current:** Store implements both API routing and database lifecycle management

**Proposed:** Separate `Store` (API router) from `DatabaseManager` (connection lifecycle)
```php
interface DatabaseManager {
    public function get(string $dbName): Database;
    public function close(string $dbName): void;
    public function closeAll(): void;
    public function listDatabases(): \Generator;
}
```

## 2. Entry Value Object Enhancement
**Current:** Entry is pure data holder with limited behavior

**Proposed:** Add rich methods for serialization/deserialization
```php
public function serialize(string $format): string; // 'json', 'yaml'
public static function deserialize(string $data, string $format): self;
public function toArray(): array;
```

## 3. Configuration Object
**Current:** Configuration via constructor arguments and environment variables

**Proposed:** Explicit configuration object
```php
final class StoreConfig {
    public function __construct(
        public readonly string $dataDir,
        public readonly bool $encrypted = false,
        public readonly ?string $encryptionKey = null,
        public readonly int $suggestThreshold = 0,
    ) {}
}
```

## 4. Database Transaction Abstraction
**Current:** `Database::transaction(callable $fn)` returns mixed

**Proposed:** Formalize with result type
```php
final class TransactionResult {
    public function __construct(
        public readonly bool $committed,
        public readonly mixed $value = null,
        public readonly ?\Throwable $error = null,
    ) {}
}
```

---

# API / Developer Experience Improvements

## 1. Fluent Builder for Store Creation
**Current:**
```php
$store = new Store();
$store->set('key', 'value');
```

**Proposed:**
```php
$store = Store::create()
    ->withDataDir('/custom/path')
    ->withEncryption()
    ->build();
```

## 2. Connection Pooling
**Current:** Database connections cached in `$this->databases[]`

**Proposed:** Explicit pool with lifecycle management
```php
$pool = new DatabasePool($dataDir);
$store = new Store(pool: $pool);
// or with DI container
$container->get(Store::class);
```

## 3. Type-Safe Generic Methods
**Current:**
```php
$value = $store->get('key'); // returns string|null
```

**Proposed:** Generic type hints for IDE support
```php
/**
 * @template T
 * @param string $key
 * @param class-string<T> $type
 * @return T|null
 */
public function get(string $key, string $type = 'string'): mixed;
```

## 4. Batch Operations
**Current:** Set/get/delete one key at a time

**Proposed:**
```php
$store->batch(function (Batch $b) {
    $b->set('key1', 'value1');
    $b->set('key2', 'value2');
    $b->delete('key3');
});
```

---

# Documentation / Cookbook Opportunities

## 1. Usage Scenarios and Recipes

### Credential Storage with Encryption
```php
$store = Store::create()
    ->withEncryption(getenv('SUGAR_SKATE_KEY'))
    ->build();

// Store sensitive data
$store->set('api-token', $token, 'credentials');

// Retrieve with decryption
$token = $store->get('api-token', 'credentials');
```

### TTL-based Session Management
```php
// Short-lived session
$store->setWithTtl('session:abc123', $data, 3600);

// Background job cleanup (optional)
$expired = $store->list(pattern: 'session:*', db: 'sessions')
    ->filter(fn($e) => $e->isExpired());
```

### Multi-tenant Data Isolation
```php
// Each tenant gets own database
$store->set("config:{$tenantId}", json_encode($config), 'tenants');

// Query specific tenant
$config = json_decode($store->get("config:{$tenantId}", 'tenants'));
```

### Backup and Restore via Export
```php
// Export all databases
$store->export('json', 'default', 'user-*');
$store->export('json', 'passwords');

// Full backup script
foreach ($store->listDatabases() as $db) {
    $store->export("json", $db);
}
```

## 2. Performance Tuning Guide
- When to use separate databases vs key prefixes
- WAL mode tuning for read-heavy vs write-heavy workloads
- Optimal TTL granularity for expiry patterns
- Memory considerations for large key sets

## 3. Migration Guide
- From Go skate (Badger) to sugar-skate (SQLite)
- From other KV stores (Redis, Memcached)
- Schema migration for legacy databases with missing `expires_at`

---

# UX / TUI Improvements

## 1. Interactive Browser Mode
Using `candy-core` (TUI framework), implement `skate browse [db]`:
- Vim-style key navigation (j/k/h/l, gg, G)
- Real-time search/filter as you type
- Entry preview with formatted JSON/YAML
- Edit mode for updating values
- Delete confirmation with entry preview

## 2. Colorized CLI Output
Add optional colorized output via `candy-sprinkles`:
- Colorized key-value display in list
- Expiry warnings (red for soon-to-expire)
- Database size indicators
- Progress bars for import/export

## 3. Smart Suggestions
Enhance Levenshtein suggestions:
- Show multiple suggestions ranked by distance
- Include "did you mean..." with confidence score
- Add `--suggest` flag to control suggestion behavior

---

# Testing / Reliability Improvements

## 1. Property-Based Testing
Add `php-property-test` or similar for:
- Round-trip serialization (Entry → DB → Entry)
- TTL expiry edge cases
- Glob pattern matching correctness
- Concurrent access simulation

## 2. Fuzzing
Add fuzzing for:
- Malformed JSON/YAML import files
- Invalid glob patterns
- Binary data handling
- Unicode key/value edge cases

## 3. Performance Regression Tests
Add benchmarks for:
- `get()` on 1000, 10000, 100000 keys
- Levenshtein suggestion on N keys
- List with glob pattern on N keys
- Import/Export of N entries

## 4. Chaos Testing
- Simulate partial write failures
- Simulate disk space exhaustion
- Simulate corrupted database files

---

# Ecosystem / Integration Opportunities

## 1. Symfony Cache Adapter
Implement `symfony/cache` `CacheItemPoolInterface`:
```php
use SugarCraft\Skate\SymfonyCacheAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

$adapter = new SymfonyCacheAdapter($store, 'default');
$cache = new FilesystemAdapter('', 0, '', $adapter);
```

## 2. PSR-16 Simple Cache Integration
Implement `psr/simple-cache`:
```php
use SugarCraft\Skate\Psr16Cache;
use Psr\SimpleCache\CacheInterface;

$cache = new Psr16Cache($store, 'default');
// Now usable with any PSR-16 compatible library
```

## 3. Laravel Database Driver
Add as Laravel database driver:
```php
// config/database.php
'connections' => [
    'skate' => [
        'driver' => 'sugarcraft',
        'database' => 'default',
    ],
],
```

## 4. ReactPHP Integration
Add async interface via ReactPHP:
```php
use React\Async\Awaitable;

$awaitable = $store->getAsync('key');
$result = await $awaitable;
```

---

# Notable PRs / Issues / Discussions

## charmbracelet/skate#142 - Query by value
**Summary:** Request to add value-based filtering beyond keys
**Relevance:** sugar-skate has same limitation
**Lessons learned:** Could implement SQLite FTS5 for value search
**Potential adaptation:** Add `Store::findByValue(string $pattern)` using FTS5

## charmbracelet/skate#89 - Fuzzy search performance
**Summary:** Levenshtein is slow on large key sets
**Relevance:** sugar-skate uses identical algorithm
**Lessons learned:** Trigram index or approximate nearest neighbor could help
**Potential adaptation:** Add configurable suggestion threshold

## charmbracelet/skate#67 - Encryption at rest
**Summary:** Feature request for encrypted storage
**Relevance:** sugar-skate has same gap
**Lessons learned:** Encryption should be transparent to API
**Potential adaptation:** Implement `Store::withEncryption()` wrapper

## Dgraph/Badger#323 - Compaction design
**Summary:** Detailed design for LSM-tree compaction
**Relevance:** SQLite equivalent is VACUUM command
**Lessons learned:** Could schedule automatic VACUUM after N writes
**Potential adaptation:** Add configurable vacuum interval

---

# Recommended Roadmap

## Immediate Wins (0-1 month)

1. **Add encryption helpers** - `openssl_encrypt`/`openssl_decrypt` wrapper
2. **Implement VACUUM command** - `skate vacuum [db]`
3. **Add streaming export** - Chunked JSON export for large databases
4. **Database statistics** - Entry count, size, expiry distribution

## Medium-Term Improvements (1-3 months)

5. **Cross-database transactions** - Two-phase commit pattern
6. **Levenshtein acceleration** - Trigram-like pre-filtering
7. **FTS5 value search** - Full-text search on values
8. **Binary BLOB storage** - Native BLOB instead of base64

## Major Architectural Upgrades (3-6 months)

9. **Interactive TUI browser** - Full-screen data explorer
10. **Connection pooling** - Database lifecycle management
11. **Symfony/PSR-16 adapters** - Framework integrations
12. **Performance benchmarks** - Regression test suite

## Experimental Ideas (6+ months)

13. **Distributed locking** - Redis-backed advisory locks
14. **Replication** - Master-slave SQLite replication
15. **Graph query layer** - Relationship tracing across keys
16. **ML-based suggestions** - TensorFlow Lite for better fuzzy matching

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Priority |
|-------------|--------|------------|------|----------|
| Encryption at rest | High | Medium | Low | Immediate |
| Streaming export | Medium | Medium | Low | Immediate |
| VACUUM scheduling | Medium | Low | Low | Immediate |
| Database statistics | Low | Low | None | Immediate |
| FTS5 value search | High | Medium | Low | Medium |
| Cross-database transactions | Medium | High | Medium | Medium |
| Levenshtein acceleration | Medium | Medium | Low | Medium |
| Binary BLOB storage | Medium | Medium | Medium | Medium |
| Interactive TUI browser | Medium | High | Medium | Low |
| Connection pooling | Low | Medium | Low | Low |
| PSR-16/Symfony adapters | Medium | Low | None | Low |
| ReactPHP async integration | Low | High | Medium | Low |

---

# Final Strategic Assessment

**sugar-skate** represents a mature, well-engineered PHP port of `charmbracelet/skate` that successfully adapts the Go original's design to PHP's SQLite-backed ecosystem. The library excels in its zero-configuration operation, multi-database isolation, and thoughtful CLI interface with STDIN/stdout separation.

**Core strengths:**
- Clean API design with immutable value objects
- Comprehensive test coverage (56 test methods)
- Memory-efficient generator-based listing
- Atomic transaction support with `BEGIN IMMEDIATE`
- 17-locale i18n support
- Import/export system (unique among KV store ports)
- Glob pattern matching translated to SQL LIKE

**Critical gaps to address:**
- **Encryption** - Most urgent for sensitive data use cases; should be built-in, not optional
- **Query beyond glob** - FTS5 integration would transform the library from KV store to queryable store
- **Large-scale performance** - Levenshtein optimization and compaction scheduling for long-term health

**Competitive positioning:**
- Compared to Go `skate`: Adds import/export, i18n, schema migration, but lacks BadgerDB's performance
- Compared to `pterm/pterm`: Focuses purely on KV storage rather than general CLI output
- Compared to `ratatui/ratatui`: Not a TUI framework, just a data storage library
- Compared to `charmbracelet/gum`: More programmatic API, not shell-script focused

**For v1.x:** Focus on encryption, FTS5, and operational tooling (vacuum, stats). These are natural extensions that maintain backward compatibility while filling critical gaps.

**For v2.0:** Consider breaking changes: BLOB native storage, configuration object, connection pooling, and potentially async interface via ReactPHP. This would align with PHP 8.4+ capabilities and modern PHP ecosystem practices.

The library is production-ready for non-sensitive personal use cases. For sensitive data, encryption must be added before deployment. The architecture is sound and the implementation is high quality — the gaps are feature gaps, not architectural flaws.
