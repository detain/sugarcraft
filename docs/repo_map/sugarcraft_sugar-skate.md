# SugarCraft/sugar-skate — Innovation & Comparison Report

## Overview

**sugar-skate** is a pure-PHP personal key/value store and the definitive PHP port of `charmbracelet/skate`. It provides multi-database isolation, binary data storage, glob pattern matching, ordered listing, TTL/expiry, Levenshtein-based typo suggestions, and JSON/YAML import/export — all backed by SQLite instead of the Go project's BadgerDB. The library is `final` classes, `declare(strict_types=1)`, PSR-4, and works on PHP 8.3+ with only `ext-sqlite3` required.

**Key statistics:**
- **5 source files** in `src/` (Store, Database, Entry, Cli/ExportCommand, Cli/ImportCommand)
- **2 importer classes** in `src/Import/` (JsonImporter, YamlImporter)
- **3 test files** with 30+ test methods covering all major operations
- **5 example scripts** (basic, multidb, glob, binary, reverse-order)
- **17 locales** (en + 16 translated)
- **1 VHS demo** (.vhs/glob.gif)

**Status:** 🟢 v1 ready — local-only KV store with full test coverage and documentation

---

## Architecture

### Package Structure

```
sugar-skate/
├── bin/skate                          # CLI entry point (280 lines)
├── src/
│   ├── Store.php                      # Main API (340 lines)
│   ├── Database.php                   # SQLite wrapper (345 lines)
│   ├── Entry.php                     # Value object (95 lines)
│   ├── Lang.php                      # i18n facade (22 lines)
│   ├── Cli/ExportCommand.php         # Export handler (136 lines)
│   ├── Cli/ImportCommand.php        # Import handler (73 lines)
│   └── Import/
│       ├── JsonImporter.php          # JSON import (131 lines)
│       └── YamlImporter.php         # YAML import (213 lines)
├── lang/                             # 17 locales
├── tests/
│   ├── StoreTest.php                  # 26 test methods
│   ├── DatabaseTest.php             # 18 test methods
│   └── ImportExportTest.php         # 12 test methods
└── examples/
    ├── basic.php, multidb.php, glob.php, binary.php, reverse-order.php
```

### Core Components

#### 1. Store (`src/Store.php`)

The `Store` class is the top-level API — a **router** that dispatches operations to the appropriate per-database SQLite connection. Key design decisions:

**Multi-database via suffix syntax** (`key@dbname`):
```php
// File: src/Store.php, lines 304–316
private function parseKey(string $key): array
{
    $at = \strrpos($key, '@');
    if ($at === false) {
        return [$this->defaultDb, $key];
    }
    $dbName = \substr($key, $at + 1);
    $entryKey = \substr($key, 0, $at);
    return [$dbName, $entryKey];
}
```

**Database caching**: Connections are cached in `$this->databases[]` to avoid reopening SQLite files on every operation (line 43).

**Data directory resolution** (lines 321–331):
- Respects `$XDG_CONFIG_HOME` environment variable
- Falls back to `~/.config/skate`
- Created with `0o700` permissions (owner-only read/write/execute)

**Levenshtein typo suggestions** on key miss (lines 146–165):
```php
private function suggestSimilar(string $key, string $dbName): ?string
{
    $allKeys = $this->database($dbName)->allKeys();
    $best = null;
    $bestDist = \PHP_INT_MAX;
    foreach ($allKeys as $candidate) {
        $dist = \levenshtein($key, $candidate);
        if ($dist < $bestDist && $dist <= (int) (\strlen($key) / 2)) {
            $bestDist = $dist;
            $best = $candidate;
        }
    }
    return $best;
}
```
Called from `get()` only when the key is truly missing (line 121). Suggestion is written to `STDERR` to keep `stdout` clean for value output.

**TTL support** via `setWithTtl()` (lines 97–103): Discards non-positive TTLs as no-ops, delegates to `set()` with the `expires_at` parameter.

**File-based binary storage**: `setFile()`/`getFile()` read/write binary files using base64 encoding internally (lines 210–230).

#### 2. Database (`src/Database.php`)

The `Database` class wraps one SQLite file. This is where the **per-database isolation** happens — each database is a completely separate `.db` file, not a table within a shared file.

**Schema** (lines 39–48):
```sql
CREATE TABLE IF NOT EXISTS entries (
    key         TEXT PRIMARY KEY,
    value       TEXT NOT NULL,
    binary      INTEGER NOT NULL DEFAULT 0,
    created     TEXT NOT NULL,
    modified    TEXT NOT NULL,
    expires_at  TEXT
)
```

**Legacy migration** (lines 50–57): On open, checks for `expires_at` column and runs `ALTER TABLE` if missing. This enables seamless schema upgrades across versions.

**Performance settings** (lines 58–59):
```php
$this->db->exec('PRAGMA journal_mode = WAL');
$this->db->exec('PRAGMA foreign_keys = ON');
```
WAL (Write-Ahead Logging) provides better concurrency and crash recovery than default rollback journal.

**Glob pattern to SQL LIKE translation** (`buildGlobQuery()`, lines 315–344):
- `*` → `%` (matches any characters)
- `?` → `_` (matches single character)
- `%` and `_` are escaped as `\%` and `\_` to prevent accidental LIKE wildcards

**Atomic transactions** (lines 290–301):
```php
public function transaction(callable $fn): mixed
{
    $this->db->exec('BEGIN IMMEDIATE');
    try {
        $result = $fn();
        $this->db->exec('COMMIT');
        return $result;
    } catch (\Throwable $e) {
        $this->db->exec('ROLLBACK');
        throw $e;
    }
}
```
Uses `BEGIN IMMEDIATE` to acquire a write lock immediately rather than deferring until the first write — prevents deadlocks.

#### 3. Entry (`src/Entry.php`)

A **value object** with 6 readonly properties: `key`, `value`, `binary`, `createdAt`, `modifiedAt`, `expiresAt`. Notable methods:

- `isExpired()`: Checks if `expiresAt <= now`
- `rawValue()`: Returns base64-decoded bytes if `binary === true`, original value otherwise
- `Entry::binary(string $key, string $bytes)`: Factory that base64-encodes and marks as binary

#### 4. Import/Export System

**JsonImporter** (`src/Import/JsonImporter.php`):
- Reads `{ "key": "value", "_ttl": { "key": 3600 } }` format
- `_ttl` top-level map specifies per-key expiry in seconds
- Atomic import uses reflection to access `Store::$databases` and call `Database::transaction()` directly
- Multi-database atomic import throws `RuntimeException` (documented limitation — SQLite transactions are per-connection)

**YamlImporter** (`src/Import/YamlImporter.php`):
- Reads `skate_ttl_key: 3600` entries as TTL metadata
- Falls back to built-in minimal YAML parser (200 lines) if `symfony/yaml` is not available
- Handles simple scalar values and quoted strings

**ExportCommand** (`src/Cli/ExportCommand.php`):
- Outputs `_ttl` map in JSON, `skate_ttl_<key>` entries in YAML
- Uses `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` flags
- Falls back to minimal YAML formatting if `symfony/yaml` unavailable

---

## Database Abstraction

Sugar-skate implements a **file-per-database** abstraction. Unlike Go skate which uses BadgerDB (an LSM-tree based embedded KV store with directories per database), PHP skate uses **one SQLite file per database** stored under `~/.config/skate/`:

```
~/.config/skate/
├── default.db        # Default database
├── passwords.db      # Passwords database
├── bookmarks.db      # Bookmarks database
└── notes.db         # Notes database
```

### Why SQLite Instead of BadgerDB?

BadgerDB is a Go-native LSM-tree store with:
- Concurrent read/write transactions
- Garbage collection (compaction)
- Variable-size keys and values
- Directory-based multi-database (each DB = a subdirectory)

PHP has no native Badger equivalent. SQLite was chosen because:
1. ** ubiquitously available** (`ext-sqlite3` is part of PHP core)
2. **Zero configuration**: Single file, auto-created on first access
3. **ACID transactions**: Full rollback support
4. **Familiar ecosystem**: PHP developers already know SQLite
5. **Sufficient performance** for personal CLI use cases

### Trade-offs vs Go Implementation

| Aspect | Go (BadgerDB) | PHP (SQLite) |
|---|---|---|
| Storage engine | LSM-tree (append-only) | B-tree (overwrites) |
| Multi-database | Directories | Separate files |
| Write performance | Higher (append-only) | Moderate |
| Read performance | Very high (LSM read cache) | Good |
| Transaction scope | Key-value only | Full SQL |
| Schema migration | Manual | `ALTER TABLE` supported |
| Binary data | Native | base64 encoding |
| Disk space | More (LSM write amplification) | Less |

---

## Fuzzy Search Implementation

### Levenshtein-Based Key Suggestions

The fuzzy search in sugar-skate operates on **keys within a database**, triggered when `get()` finds no matching entry. This differs from Go skate's fuzzy search, which operates on **database names** when a database lookup fails.

**Algorithm** (`src/Store.php`, lines 146–165):

1. Fetch all non-expired keys from the target database via `Database::allKeys()`
2. Initialize `bestDist = PHP_INT_MAX`, `best = null`
3. For each candidate key:
   - Compute `levenshtein($key, $candidate)` (PHP's built-in, O(n*m) time)
   - Accept candidate only if `dist < bestDist && dist <= strlen(key) / 2`
   - The `/ 2` threshold means longer keys allow proportionally more edit distance
4. Return the closest match, or `null` if none qualify

**Example behavior:**
```
store->get('colro', '');  // "colro" misspelled
// stderr: "key not found; did you mean 'color'?"
// (assuming 'color' exists in the database)
```

**Performance consideration**: `allKeys()` loads all keys into a PHP array. For databases with thousands of entries, this is a memory and CPU consideration. No indexing optimization exists for Levenshtein (unlike trigram indexes in full-text search).

### Difference from Go Implementation

Go skate's `findDb()` function (`charmbracelet/skate`, line 286) uses `levenshtein.ComputeDistance()` to suggest **database names** when `openKV(name)` fails. Sugar-skate's `suggestSimilar()` suggests **keys** within the already-resolved database.

The practical difference:
- Go: "database 'passwrd' not found; did you mean 'passwords'?"
- PHP: "key 'colro' not found; did you mean 'color'?"

---

## CLI Interface

The CLI (`bin/skate`, 280 lines) implements 7 commands:

| Command | Description | Notable Features |
|---|---|---|
| `set <key> [value]` | Store a value | Reads from stdin if no value; `--ttl=SECONDS` |
| `get <key>` | Retrieve a value | Prints typo suggestion to stderr on miss |
| `list [-k\|-v] [-r] [-d delim] [pattern]` | List entries | Glob patterns, reverse order, keys/values/both modes |
| `delete <key>` | Delete a key | Supports glob patterns, returns count |
| `list-dbs` | Show all databases | Lists `.db` files in data directory |
| `import <json\|yaml> <path>` | Bulk import | `--no-atomic` flag, path=`-` for stdin |
| `export <json\|yaml> [db] [pattern]` | Bulk export | Outputs to stdout, filtered by pattern |

**STDIN handling**:
- `set`: If no positional value given, reads one `trim()`ed line from `STDIN`
- `import`: `path='-'` or `path='/dev/stdin'` reads all of `php://stdin`

**Exit codes**:
- `get`: Returns `1` if key is truly missing (not just empty value)
- `import`/`export`: `1` on error, `0` on success

---

## Tests

### Coverage Summary

| File | Test Count | Coverage Areas |
|---|---|---|
| `StoreTest.php` | 26 tests | set/get/delete/list/cross-db/TTL/binary/file ops |
| `DatabaseTest.php` | 18 tests | set/get/overwrite/delete/glob/list/transaction/count |
| `ImportExportTest.php` | 12 tests | JSON/YAML import atomicity/TTL/multi-db/basic |

### Notable Test Patterns

**TTL expiry testing** (`StoreTest.php`, line 285–309): Inserts an expired entry via raw SQLite reflection to bypass the `get()` expiry filter, verifying `entry()` returns `null` for expired keys while `getRaw()` can still retrieve them.

**Transaction rollback testing** (`DatabaseTest.php`, line 225–237): Verifies that when a callback throws, all prior inserts within the transaction are rolled back.

**Binary data round-trip** (`StoreTest.php`, line 232–238): Stores raw bytes `"\x00\xff\xfe\xfd"` with `binary=true`, retrieves via `entry()->rawValue()`, and asserts byte-perfect round-trip.

**Glob pattern coverage** (`DatabaseTest.php`, lines 121–142): Tests `*` (multi-char) and `?` (single-char) patterns independently to verify correct SQL LIKE translation.

---

## Comparison: sugar-skate vs charmbracelet/skate (Go)

### Feature Parity Matrix

| Feature | Go skate | PHP sugar-skate | Notes |
|---|---|---|---|
| Key/value storage | ✅ | ✅ | |
| Multi-database (`key@db`) | ✅ | ✅ | |
| Binary data (`-b` flag) | ✅ | ✅ | Base64 in PHP |
| List (keys/values/both) | ✅ | ✅ | |
| Reverse iteration | ✅ | ✅ | |
| Glob patterns | ✅ | ✅ | `*`/`?` → SQL LIKE |
| Levenshtein suggestions | ✅ | ✅ | DB names (Go) vs keys (PHP) |
| TTL/expiry | ✅ (code-level) | ✅ | Both implement |
| Import/Export | ❌ | ✅ | PHP-specific addition |
| STDIN input | ✅ | ✅ | |
| Delete database | ✅ | ✅ | |
| Local-only (v1+) | ✅ | ✅ | Both removed cloud sync |

### Architecture Differences

| Aspect | Go Implementation | PHP Implementation |
|---|---|---|
| Single-file CLI | Yes (main.go, 443 lines) | No (bin/skate 280 + 5 src files) |
| Storage backend | BadgerDB (LSM-tree) | SQLite 3 (B-tree) |
| Transaction pattern | `wrap()` helper closure | `Database::transaction()` method |
| Multi-database | Subdirectories | Separate `.db` files |
| Fuzzy search target | Database names | Keys |
| Schema migration | N/A (Badger schema-less) | `ALTER TABLE` on open |
| Import/export | None | JSON + YAML with TTL metadata |
| i18n | None | 17 locales via `SugarCraft\Core\I18n\T` |

### What PHP Adds Over Go

1. **Import/Export**: Bulk load/save in JSON or YAML format with atomic transaction support
2. **i18n**: Full localization for 16 languages beyond English
3. **Schema migration**: Automatic `ALTER TABLE` for `expires_at` column on legacy databases
4. **Binary factory**: `Entry::binary()` convenience constructor for creating binary entries
5. **Detailed expiry metadata**: `Entry::isExpired()`, `Entry::expiresAt` (Go skate has expiry but less granular access)

---

## Third-Party Repository Comparisons

### Related Key-Value Stores

Beyond the upstream `charmbracelet/skate`, several other projects in the ecosystem relate to key-value storage:

1. **dgraph/badger** (upstream dependency of Go skate): Production-grade LSM-tree KV store in Go. Not directly relevant to PHP porting.
2. **sqlite** (PHP's backend): Sugar-skate uses SQLite3 directly — no abstraction layer between PHP and the database.
3. **symfony/cache** (optional dependency): Could theoretically back sugar-skate, but would add significant weight for a CLI tool.
4. **sugarcraft/candy-core** (dependency): Provides `SugarCraft\Core\I18n\T` for i18n — not a storage dependency.

### Notable Patterns

The **file-per-database** pattern in sugar-skate (`<name>.db` files in a shared directory) is a common approach in CLI tools:
- `go-skate` uses subdirectories per database
- Many CLI tools (e.g., `less` history, `fzf` cache) use this pattern

The **TTL as expiry timestamp** (storing ISO 8601 datetime in a TEXT column) is straightforward and SQLite-friendly. An alternative would be storing Unix epoch seconds as an INTEGER, which would enable efficient range queries but lose human readability.

---

## Strengths and Innovations

### Strengths

1. **Zero-configuration persistence**: Data directory created automatically with `0o700` permissions on first Store instantiation
2. **Multi-database isolation without server**: Each `.db` file is independent — backup, share, or delete without affecting others
3. **Glob pattern filtering**: SQL LIKE translation enables filtering without loading all keys into PHP memory
4. **Iterable results**: `Database::list()` and `Store::list()` are generators — memory-efficient for large databases
5. **Graceful typo recovery**: Levenshtein suggestions on key miss reduce user frustration
6. **Import/export with TTL**: Critical for backup/restore workflows with expiring entries
7. **Atomic single-database transactions**: Uses `BEGIN IMMEDIATE` to avoid SQLite deadlock scenarios
8. **Legacy schema migration**: `ALTER TABLE` automatically adds new columns without data loss

### Innovations Over Upstream

1. **Import/Export system** (JSON/YAML): Go skate has no equivalent; this is a genuine extension
2. **i18n**: 17 locales via the SugarCraft i18n framework
3. **Binary file convenience**: `setFile()`/`getFile()` for seamless file storage
4. **Entry metadata access**: PHP's `Entry::isExpired()`, `createdAt`, `modifiedAt` give more introspection than Go's raw bytes

---

## Weaknesses and Limitations

1. **No encryption at rest**: Data stored in plain files in `~/.config/skate/` — anyone with file access can read everything
2. **No authentication**: No access control; file permissions are the only protection
3. **No atomic cross-database operations**: `BEGIN IMMEDIATE` only locks one `.db` file; multi-db atomic import explicitly throws
4. **Levenshtein on large key sets**: `allKeys()` loads every key into memory; no index-based acceleration for fuzzy search
5. **Single PHP process**: No concurrent access support; multiple processes accessing the same `.db` file will have locking contention
6. **No query beyond glob**: No pattern matching on values, no range queries, no full-text search
7. **Binary data overhead**: base64 encoding increases size by ~33%; no native binary storage (unlike Go Badger)
8. **No compaction/cleanup**: WAL journal grows indefinitely; no automated `VACUUM` scheduling

---

## File Reference Map

| File | Lines | Purpose |
|---|---|---|
| `src/Store.php` | 340 | Main API, key routing, database caching, levenshtein suggestions |
| `src/Database.php` | 345 | SQLite wrapper, glob→LIKE translation, transactions |
| `src/Entry.php` | 95 | Value object, rawValue(), isExpired(), binary() factory |
| `src/Lang.php` | 22 | i18n facade extending `SugarCraft\Core\I18n\Lang` |
| `src/Cli/ExportCommand.php` | 136 | JSON/YAML export with TTL metadata encoding |
| `src/Cli/ImportCommand.php` | 73 | JSON/YAML import dispatcher |
| `src/Import/JsonImporter.php` | 131 | JSON import with `_ttl` map and atomic transaction support |
| `src/Import/YamlImporter.php` | 213 | YAML import with `skate_ttl_` prefix convention and fallback parser |
| `bin/skate` | 280 | CLI entry point, 7 commands, stdin handling, option parsing |
| `lang/en.php` | 25 | English translations (template for other 16 locales) |
| `tests/StoreTest.php` | 310 | 26 tests covering Store API, TTL, multi-db, binary, glob |
| `tests/DatabaseTest.php` | 275 | 18 tests covering Database operations, transactions, glob |
| `tests/ImportExportTest.php` | 221 | 12 tests covering JSON/YAML import, atomicity, TTL |

---

## Conclusion

Sugar-skate is a faithful and well-extended PHP port of `charmbracelet/skate`. Its most significant deviation from upstream is the **SQLite-instead-of-BadgerDB** storage backend, trading Go-specific LSM-tree performance for universal PHP compatibility. The **import/export system** and **i18n support** are genuine additions not present in Go skate, making the PHP version more suitable for environments where data portability and localization matter.

The **file-per-database architecture** is clean and intuitive — users can think in terms of separate "stores" without configuring anything. The **Levenshtein typo suggestions** and **glob pattern matching** bring user-experience touches usually found only in more mature CLI tools.

For v1, the library is production-ready for personal use cases. For team or sensitive use cases, the lack of encryption at rest and authentication would need to be addressed at the application layer or through filesystem-level controls.
