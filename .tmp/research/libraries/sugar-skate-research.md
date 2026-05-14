# sugar-skate Library Research

**Date:** 2026-05-13
**Library:** sugar-skate (PHP 8.3+ port of charmbracelet/skate)
**Research Scope:** Key/value store patterns across Go, Rust, and Python

---

## Executive Summary

This document researches key/value store libraries in Go, Rust, and Python to identify patterns that could improve sugar-skate. The current implementation is solid but has opportunities to:

1. **Add import/export functionality** (ABSENT in sugar-skate, present in most alternatives)
2. **Add typo-suggestions via Levenshtein distance** (present in Go skate, absent in PHP port)
3. **Add TTL/expiration support** (present in Rust's tinykv/rkv, absent in sugar-skate)
4. **Improve key parsing robustness** (Go skate lowercases keys, sugar-skate does not)
5. **Add pattern-based key iteration helpers** (e.g., prefix, range scans)
6. **Add JSON/nested value support** (TinyDB pattern, not currently needed but could be useful)

---

## 1. Go Skate (Upstream)

**Repository:** https://github.com/charmbracelet/skate (1.8K stars)
**Latest:** v1.0.1 (2025-03-06)
**Backend:** BadgerDB (LSM-tree key-value database)
**Storage:** Each database is a directory under `~/.local/share/charm/kv/{dbname}/`

### Architecture

```
skate/
├── main.go          # CLI commands (cobra)
├── key-parser.go    # KEY[@DB] parsing
└── badger.DB        # One DB per namespace
```

### Key Parser Implementation

**Source:** [charmbracelet/skate/main.go:380-395](https://github.com/charmbracelet/skate/blob/main/main.go)

```go
func keyParser(k string) ([]byte, string, error) {
    var key, db string
    ps := strings.Split(k, "@")
    switch len(ps) {
    case 1:
        key = strings.ToLower(ps[0])  // <-- KEYS ARE LOWERCASED
    case 2:
        key = strings.ToLower(ps[0])  // <-- KEYS ARE LOWERCASED
        db = strings.ToLower(ps[1])
    default:
        return nil, "", fmt.Errorf("bad key format, use KEY@DB")
    }
    return []byte(key), db, nil
}
```

**Key insight:** Go skate lowercases keys. Sugar-skate currently preserves case. This is a **behavioral deviation** from upstream.

### Database Path Resolution

**Source:** [charmbracelet/skate/main.go:175-183](https://github.com/charmbracelet/skate/blob/main/main.go)

```go
func getFilePath(args ...string) (string, error) {
    scope := gap.NewScope(gap.User, "charm")  // Uses go-app-paths for XDG compliance
    dd, pathErr := scope.DataPath("")
    if pathErr != nil {
        return "", pathErr
    }
    dir := filepath.Join(dd, "kv")
    if err := os.MkdirAll(dir, 0o750); err != nil {
        return "", err
    }
    return filepath.Join(append([]string{dir}, args...)...), nil
}
```

**Key insight:** Uses `go-app-paths` library for cross-platform XDG compliance. Sugar-skate implements this manually with `getenv('XDG_CONFIG_HOME')`.

### List with Iterator Pattern

**Source:** [charmbracelet/skate/main.go:296-330](https://github.com/charmbracelet/skate/blob/main/main.go)

```go
return db.View(func(txn *badger.Txn) error {
    opts := badger.DefaultIteratorOptions
    opts.PrefetchSize = 10
    opts.Reverse = reverseIterate
    if keysIterate {
        opts.PrefetchValues = false  // Optimization: only fetch keys
    }
    it := txn.NewIterator(opts)
    defer it.Close()
    for it.Rewind(); it.Valid(); it.Next() {
        item := it.Item()
        k := item.Key()
        // ... handle keys-only, values-only, all modes
    }
    return nil
})
```

### Typo Suggestions via Levenshtein Distance

**Source:** [charmbracelet/skate/main.go:200-230](https://github.com/charmbracelet/skate/blob/main/main.go)

```go
func findDb(name string) (string, error) {
    // ...
    for _, db := range dbs {
        diff := int(math.Abs(float64(len(db) - len(name))))
        levenshteinDistance := levabshtein.ComputeDistance(name, db)
        suggestByLevenshtein := levenshteinDistance <= diff
        if suggestByLevenshtein {
            suggestions = append(suggestions, db)
        }
    }
    return "", errDBNotFound{suggestions: suggestions}
}
```

### Missing Features (vs sugar-skate)

1. **No import/export** - Cannot dump to JSON/CSV
2. **No TTL/expiration** - Values persist forever
3. **No binary flag display in list** - Binary detection only on get

---

## 2. Go Skate (skate) - Data Model

**Source:** [charmbracelet/skate](https://github.com/charmbracelet/skate)

### Schema

Skate uses BadgerDB which stores `[]byte` keys and `[]byte` values. No schema enforcement.

### Multi-Database Architecture

- Each database = one BadgerDB instance (directory)
- Path: `~/.local/share/charm/kv/{dbname}/`
- Keys are byte arrays, lowercased strings
- Values are raw bytes (binary or text)

### Operations

| Operation | Go skate | sugar-skate |
|-----------|----------|-------------|
| `set key[@db] value` | ✓ | ✓ |
| `get key[@db]` | ✓ | ✓ |
| `delete key[@db]` | ✓ | ✓ |
| `list [@db]` | ✓ | ✓ |
| `list-dbs` | ✓ | ✓ |
| `delete-db` | ✓ | ✓ |
| Glob patterns | ✗ | ✓ (`*`, `?`) |
| Keys-only mode | `-k` flag | `mode: 'keys'` |
| Values-only mode | `-v` flag | `mode: 'values'` |
| Reverse order | `-r` flag | `reverse: true` |
| Custom delimiter | `-d` flag | `delimiter: "\t"` |
| Binary display | `-b` flag | Entry has `binary` property |
| stdin for value | ✓ | ✗ |
| Levenshtein suggestions | ✓ | ✗ |

---

## 3. Rust Key/Value Libraries

### 3.1 OKV (explodingcamera/okv)

**Repository:** https://github.com/explodingcamera/okv
**Stars:** ~100
**Backend:** Multiple (memdb, rocksdb, redb, Cloudflare D1/KV)
**Serialization:** serde_json, rmp-serde, or custom

```rust
use okv::Okv;

let db = Okv::new("dbname").build()?;
db.set("key", "value")?;
let value: String = db.get("key")?;
```

**Key patterns:**
- Unified API across multiple backends
- Automatic serialization/deserialization via Serde
- Type-safe values (integer types, String, bool, bytes)

### 3.2 TinyKV (Rust)

**Repository:** https://docs.rs/tinykv/latest/tinykv/
**Features:**
- JSON-based storage (serde)
- TTL/expiration support
- Auto-saving
- Backup support (.bak files)
- `no_std` support

```rust
use tinykv::{Kv, Options};

let db = Kv::new(Options::default().path("db.json"))?;
db.set("key", "value")?;
db.expire("key", std::time::Duration::from_secs(60))?;  // TTL!
```

**Key patterns:**
- **TTL/expiration** - Sugar-skate lacks this
- **Auto-save** on modification
- **Backup files** (.bak)

### 3.3 RKV (simonnim/msg-rkv)

**Repository:** https://docs.rs/rkv/latest/rkv/
**Features:**
- Single backend (SafeMode, based on LMDB-inspired design)
- Type-safe values via Value enum
- Manager singleton for environment control
- Supports: bool, i64, u64, f64, Str, Blob, Json, Instant

```rust
let manager = Manager::new()?;
let env = manager.get_or_create("mydb", Rkv::new)??;
let store = env.single()?;

// Values are typed
store.put(&mut writer, "int", &Value::I64(1234))?;
store.put(&mut writer, "string", &Value::Str("Hello"))?;
store.put(&mut writer, "blob", &Value::Blob(b"bytes"))?;
```

**Key patterns:**
- **Typed values** - sugar-skate could benefit from typed entries
- **Value::Json variant** - Store JSON documents

---

## 4. Python Key/Value Libraries

### 4.1 PickleDB

**Repository:** https://github.com/patx/pickledb (1.1K stars)
**Latest:** v1.6 (2026-01-05)
**Backend:** orjson + aiofiles (async-first) or SQLite optional

```python
from pickledb import PickleDB

db = PickleDB("example.json").load()
db.set("key", "value")
db.get("key")  # returns "value"
```

**Key patterns:**

```python
# Atomic save to temp file + rename
async def save(self) -> bool:
    temp = f"{self.location}.tmp"
    async with aiofiles.open(temp, "wb") as f:
        await f.write(orjson.dumps(self.db))
    await asyncio.create_subprocess_exec('mv', temp, self.location)
    return True
```

**Features:**
- Async-first with `aiofiles`
- Optional SQLite backend
- Atomic writes (write to `.tmp`, rename)
- Auto-save on `__aexit__` / `__exit__`

### 4.2 TinyDB

**Repository:** https://github.com/msiemens/tinydb (7.5K stars)
**Latest:** v4.8.2 (2024-10-12)
**Backend:** JSON storage (default), pluggable

```python
from tinydb import TinyDB, Query

db = TinyDB('db.json')
db.insert({'name': 'John', 'age': 22})
User = Query()
db.search(User.name == 'John')
```

**Key patterns:**

1. **Middleware architecture** for caching, etc.:
```python
from tinydb.storages import JSONStorage
from tinydb.middlewares import CachingMiddleware

db = TinyDB('db.json', storage=CachingMiddleware(JSONStorage))
```

2. **Query API** for complex searches:
```python
db.search(User.age > 18)  # Numerical comparisons
db.search(User.name == 'John')  # Exact match
```

3. **Tables (like skate's databases)**:
```python
table = db.table('name')  # Like @db suffix
table.insert({'value': True})
```

**Sugar-skate could learn from:**
- Middleware for caching reads
- Rich query API for future expansion

### 4.3 Vedis

**Repository:** https://github.com/coleifer/vedis-python
**Backend:** C library (compiled)
**Features:** Redis-like API, embedded, zero-conf

```python
from vedis import Vedis

db = Vedis(':mem:')  # or filename for disk
db['key'] = 'value'
db['key']  # 'value'

# Hash operations (nested key-value)
h = db.Hash('some key')
h['k1'] = 'v1'

# Counters
db.incr('counter')  # Atomic counter operations
```

**Key patterns:**
- **Hashes** (nested key-value within a key) - similar to skate's `@db` notation but for nested values
- **Atomic counter operations** (`incr`, `decr`)
- **Sets**, **Lists** (beyond basic key-value)

---

## 5. Comparative Analysis

### 5.1 Storage Backends

| Library | Backend | Notes |
|---------|---------|-------|
| Go skate | BadgerDB | LSM-tree, optimized for writes |
| Sugar-skate | SQLite3 | ACID, WAL mode, familiar |
| PickleDB | JSON file or SQLite | Simpler, human-readable |
| TinyDB | JSON file | No dependencies |
| OKV (Rust) | Pluggable (memdb, rocksdb, redb) | Flexibility |
| Vedis | C library | Fast, Redis-like |

### 5.2 Key Matching Patterns

| Library | Glob | Prefix | Regex | Levenshtein |
|---------|------|--------|-------|-------------|
| Go skate | ✗ | ✗ | ✗ | ✓ (for DB names) |
| Sugar-skate | ✓ (`*`, `?`) | ✓ (via glob) | ✗ | ✗ |
| PickleDB | ✗ | ✗ | ✗ | ✗ |
| TinyDB | ✗ (Query API) | ✓ | ✓ (via custom) | ✗ |
| Vedis | ✗ | ✗ | ✗ | ✗ |

**Sugar-skate's glob support is actually BETTER than most alternatives.**

### 5.3 Import/Export

| Library | JSON | CSV | Binary | STDIN |
|---------|------|-----|--------|-------|
| Go skate | ✗ | ✗ | ✓ (file redirection) | ✓ (value from stdin) |
| Sugar-skate | ✗ | ✗ | ✓ (setFile/getFile) | ✗ |
| PickleDB | ✓ (file IS JSON) | ✗ | ✗ | ✗ |
| TinyDB | ✓ | ✗ | ✗ | ✗ |
| Vedis | ✗ | ✗ | ✗ | ✗ |

### 5.4 Data Types

| Library | String | Binary | Number | JSON | Counter |
|---------|--------|--------|--------|------|---------|
| Go skate | ✓ | ✓ | ✗ | ✗ | ✗ |
| Sugar-skate | ✓ | ✓ | ✗ | ✗ | ✗ |
| PickleDB | ✓ | ✗ | ✓ (auto) | ✓ | ✗ |
| TinyDB | ✓ | ✗ | ✓ | ✓ | ✗ |
| Vedis | ✓ | ✓ | ✓ | ✓ (as JSON) | ✓ |
| RKV (Rust) | ✓ | ✓ | ✓ | ✓ | ✗ |

---

## 6. Specific Improvements for sugar-skate

### Priority 1: Import/Export (High Value, Low Effort)

**Problem:** No way to export/import data. Users cannot backup or migrate.

**Pattern from PickleDB:**
```php
// Export: db.json contains full database
// import: load from JSON file
```

**Recommendation:** Add `Store::export(string $dbName, string $format = 'json'): string` and `Store::import(string $dbName, string $data, string $format = 'json'): int`

```php
// sugar-skate/src/Store.php additions
public function export(string $dbName = null, string $format = 'json'): string
{
    $db = $this->database($dbName ?? $this->defaultDb);
    $entries = [];
    foreach ($db->list() as $entry) {
        $entries[$entry->key] = $entry->binary
            ? ['_binary' => true, 'data' => $entry->value]
            : $entry->value;
    }
    return json_encode($entries, JSON_PRETTY_PRINT);
}

public function import(string $dbName, string $data): int
{
    $entries = json_decode($data, true);
    $count = 0;
    foreach ($entries as $key => $value) {
        if (is_array($value) && ($value['_binary'] ?? false)) {
            $this->set("{$key}@{$dbName}", $value['data'], true);
        } else {
            $this->set("{$key}@{$dbName}", (string) $value);
        }
        $count++;
    }
    return $count;
}
```

**Effort:** ~2 hours
**Value:** High - enables backup, migration, data sharing

---

### Priority 2: Levenshtein Typo Suggestions (Medium Value, Medium Effort)

**Problem:** Misspelled database names give unhelpful errors.

**Pattern from Go skate:** Use Levenshtein distance to suggest similar database names.

**Recommendation:** Add `Database::suggestSimilar(string $name): array` and use in error messages.

```php
// sugar-skate/src/Database.php
public static function suggestSimilar(string $name, array $candidates): array
{
    $suggestions = [];
    $diff = abs(count($name) - count($candidate));
    foreach ($candidates as $candidate) {
        $distance = levenshtein($name, $candidate);
        if ($distance <= $diff) {
            $suggestions[] = $candidate;
        }
    }
    return $suggestions;
}
```

**Effort:** ~1 hour
**Value:** Medium - improves UX for CLI users

---

### Priority 3: Key Case Normalization (Low Value, Medium Effort)

**Problem:** Go skate lowercases all keys. Sugar-skate preserves case. This is a behavioral deviation.

**Recommendation:** Add optional case-insensitive mode or document the difference clearly.

```php
// In Store or via constructor option
private bool $caseSensitive = true;

public function set(string $key, string $value, bool $binary = false): Entry
{
    [$dbName, $entryKey] = $this->parseKey($key);
    $stored = $binary ? base64_encode($value) : $value;
    $normalizedKey = $this->caseSensitive ? $entryKey : strtolower($entryKey);
    return $this->database($dbName)->set($normalizedKey, $stored, $binary);
}
```

**Effort:** ~2 hours
**Value:** Low - it's arguably better to preserve case, just document it

---

### Priority 4: TTL/Expiration Support (Medium Value, Higher Effort)

**Problem:** Values persist forever. No way to set expiration.

**Pattern from Rust tinykv:**
```rust
db.expire("key", Duration::from_secs(60));
```

**Recommendation:** Add `Entry::expireAt()` or `Store::setWithTtl()`.

```php
// sugar-skate/src/Entry.php addition
final readonly class Entry
{
    // existing properties...
    public readonly ?\DateTimeImmutable $expiresAt;

    public function expires(): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < new \DateTimeImmutable();
    }
}

// sugar-skate/src/Database.php schema change
// CREATE TABLE entries (
//     key TEXT PRIMARY KEY,
//     value TEXT NOT NULL,
//     binary INTEGER NOT NULL DEFAULT 0,
//     created TEXT NOT NULL,
//     modified TEXT NOT NULL,
//     expires_at TEXT  -- new column, nullable
// );
```

**Effort:** ~4 hours (schema migration, index for expires_at)
**Value:** Medium - requested feature for API tokens, temporary data

---

### Priority 5: STDIN Value Input (Low Value, Low Effort)

**Problem:** Go skate reads value from stdin if not provided. Sugar-skate requires explicit value.

**Recommendation:** Add `Store::setFromStdin(string $key): Entry` or CLI wrapper.

```php
// sugar-skate/src/Store.php
public function setFromStdin(string $key, bool $binary = false): Entry
{
    $value = file_get_contents('php://stdin');
    return $this->set($key, $value, $binary);
}
```

**Effort:** ~1 hour
**Value:** Low - CLI convenience feature

---

### Priority 6: Atomic Database Operations (Medium Value, Medium Effort)

**Problem:** No transaction support for multi-key operations.

**Pattern from PickleDB:** Atomic file writes via temp file + rename.

**Recommendation:** Add `Database::transaction(callable $fn)` wrapping SQLite transaction.

```php
public function transaction(callable $fn): mixed
{
    $this->db->exec('BEGIN TRANSACTION');
    try {
        $result = $fn($this);
        $this->db->exec('COMMIT');
        return $result;
    } catch (\Throwable $e) {
        $this->db->exec('ROLLBACK');
        throw $e;
    }
}
```

**Effort:** ~2 hours
**Value:** Medium - enables atomic multi-key operations

---

## 7. Recommendations Summary

| Priority | Feature | Effort | Value | Notes |
|----------|---------|--------|-------|-------|
| 1 | Import/Export JSON | 2h | High | Enables backup/migration |
| 2 | Levenshtein suggestions | 1h | Medium | Better error messages |
| 3 | TTL/Expiration | 4h | Medium | Schema change needed |
| 4 | Atomic transactions | 2h | Medium | Multi-key operations |
| 5 | STDIN input | 1h | Low | CLI convenience |
| 6 | Case normalization | 2h | Low | Document or add option |

---

## 8. Detailed Implementation: Import/Export

This is the highest-priority improvement. Here's the detailed implementation plan:

### 8.1 Store Methods to Add

```php
// sugar-skate/src/Store.php

/**
 * Export all entries from a database as JSON.
 *
 * @param string|null $dbName Database to export. Defaults to store's default.
 * @return string JSON-encoded entries
 */
public function export(?string $dbName = null, string $format = 'json'): string
{
    $db = $this->database($dbName ?? $this->defaultDb);
    $entries = [];
    foreach ($db->list() as $entry) {
        $entries[$entry->key] = $entry->toArray();
    }
    return json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

/**
 * Import entries from JSON data.
 *
 * @param string $dbName Target database
 * @param string $json JSON data to import
 * @param bool $overwrite Overwrite existing keys (default: true)
 * @return int Number of entries imported
 */
public function import(string $dbName, string $json, bool $overwrite = true): int
{
    $data = json_decode($json, true);
    if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new \InvalidArgumentException(Lang::t('store.invalid_json'));
    }
    $count = 0;
    foreach ($data as $key => $value) {
        if (!$overwrite && $this->get("{$key}@{$dbName}") !== '') {
            continue;
        }
        if (is_array($value) && ($value['_binary'] ?? false)) {
            $this->set("{$key}@{$dbName}", $value['data'] ?? '', true);
        } else {
            $this->set("{$key}@{$dbName}", (string) $value);
        }
        $count++;
    }
    return $count;
}

/**
 * Export a single entry as JSON (for piping to other tools).
 */
public function exportEntry(string $key): string
{
    $entry = $this->entry($key);
    if ($entry === null) {
        return '';
    }
    return json_encode($entry->toArray(), JSON_UNESCAPED_UNICODE);
}
```

### 8.2 Entry Method to Add

```php
// sugar-skate/src/Entry.php

/**
 * Convert entry to array for serialization.
 *
 * @return array{key: string, value: string, binary: bool, created_at: string, modified_at: string}
 */
public function toArray(): array
{
    return [
        'key' => $this->key,
        'value' => $this->value,
        'binary' => $this->binary,
        'created_at' => $this->createdAt->format(\DATE_ATOM),
        'modified_at' => $this->modifiedAt->format(\DATE_ATOM),
    ];
}
```

### 8.3 Lang Strings to Add

```php
// sugar-skate/lang/en.php additions
'store.export_success' => 'Exported {count} entries from {db}',
'store.import_success' => 'Imported {count} entries to {db}',
'store.invalid_json' => 'Invalid JSON data: {error}',
```

---

## 9. Research Sources

| Source | URL | Relevance |
|--------|-----|-----------|
| Go skate main.go | https://github.com/charmbracelet/skate/blob/main/main.go | Primary reference |
| Go skate v1.0.0 release | https://github.com/charmbracelet/skate/releases/tag/v1.0.0 | Migration notes |
| PickleDB | https://github.com/patx/pickledb | Async + atomic writes |
| TinyDB | https://github.com/msiemens/tinydb | Middleware + query patterns |
| Vedis | https://github.com/coleifer/vedis-python | Counter + hash patterns |
| OKV (Rust) | https://github.com/explodingcamera/okv | Multi-backend pattern |
| TinyKV (Rust) | https://docs.rs/tinykv/latest/tinykv/ | TTL pattern |
| RKV (Rust) | https://docs.rs/rkv/latest/rkv/ | Typed values pattern |
| go-app-paths | https://github.com/muesli/go-app-paths | XDG path resolution |

---

## 10. Future Considerations

### 10.1 Encryption

Go skate historically used Charm KV with E2E encryption. This was removed in v1.0.0. Sugar-skate currently has no encryption. If desired, consider:
- `sodium_crypto_secretbox_*` for symmetric encryption
- Key derivation from user password

### 10.2 Cloud Sync

Go skate had cloud sync via Charm Cloud (deprecated Nov 2024). Not recommended for sugar-skate without clear use case.

### 10.3 HTTP API

Some key-value stores expose HTTP APIs (TinyKVS, nubmq). Sugar-skate is library-first; CLI is secondary. Don't add HTTP server unless requested.

### 10.4 Clustering/Replication

None of the studied libraries do this in the embedded/local-first space. Out of scope.

---

*Research compiled from public repository analysis and documentation review.*
