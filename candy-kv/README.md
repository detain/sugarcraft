<img src=".assets/icon.png" alt="candy-kv" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-kv)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-kv)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-kv?label=packagist)](https://packagist.org/packages/sugarcraft/candy-kv)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyKv

> Formerly published as `sugarcraft/sugar-skate` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Skate\` → `SugarCraft\Kv\`. <!-- @rename-keep -->

candy-kv — a personal key/value store for PHP 8.3+ with multi-database support, binary data handling, and glob/list filtering.

## Features

- **Multi-database** — separate stores with the `@dbname` key suffix; the SQLite file for a name is created the first time something is written to it
- **Binary data** — safely stores and retrieves raw binary (images, files); values marked binary are kept base64-encoded in the database and exported as base64 envelopes that reimport byte-exact
- **Glob pattern matching** — `list` and `delete` accept `*` and `?` wildcards; `get` takes an exact key only
- **Ordered listing** — forward or reverse lexicographic order
- **Flexible listing** — keys only, values only, or key-value pairs
- **SQLite-backed** — one SQLite file per database name, stored under `$XDG_CONFIG_HOME/skate/` (or `~/.config/skate/`)
- **PHP 8.3+** — pure PHP, no extension required beyond SQLite (php-sqlite3)
- **Iterable streams** — `list()` yields results without loading everything into memory
- **TTL / expiry** — set keys that automatically expire after a given number of seconds; expiry is stored as integer epoch seconds, so entries mean the same instant on every machine and timezone
- **Levenshtein suggestions** — typo suggestions on key miss (`get` prints the closest key to stderr)
- **Import/Export** — bulk load or save entries in JSON or YAML (`yml` accepted as alias), with optional atomic transaction
- **Atomic transactions** — `Database::transaction()` wraps a callback in BEGIN/COMMIT/ROLLBACK

## Install

```bash
composer require sugarcraft/candy-kv
```

YAML import/export works out of the box with candy-kv's own flat YAML dialect. Installing [symfony/yaml](https://packagist.org/packages/symfony/yaml) (`composer require symfony/yaml`) upgrades the YAML route to the full component — same data contract, sturdier quoting.

## Quick Start

```php
use SugarCraft\Kv\Store;

$store = new Store();

// Set and get
$store->set('greeting', 'Hello, World!');
echo $store->get('greeting'); // Hello, World!

// With a database — target it with the `key@db` suffix.
// (set()'s 3rd parameter is `bool $binary`, get()'s is a fallback string —
// a db name NEVER goes there; the suffix is the only routing mechanism.)
// Values are stored UNENCRYPTED (see Privacy & file modes below).
$store->set('token@passwords', 'ghp_xxxx');
echo $store->get('token@passwords');

// List all keys (lazy generator)
foreach ($store->list() as $entry) {
    echo "{$entry->key} => {$entry->value}\n";
}

// Glob patterns (list/delete only — get is exact-key)
foreach ($store->list(pattern: 'user-*') as $entry) { ... }

// Delete (returns the number of entries removed; patterns too)
$removed = $store->delete('greeting');
```

## CLI

```bash
candy-kv set <key> [value] [--ttl SECONDS | --ttl=SECONDS]  # Set a key (reads stdin if no value)
candy-kv get <key>                                       # Get a value (suggests typo on miss)
candy-kv list [-k|-v] [-r] [-d DELIM | -dDELIM] [pattern] # List entries
candy-kv delete <key>                                    # Delete a key (glob patterns supported)
candy-kv list-dbs                                        # Show all databases
candy-kv import <json|yaml|yml> <path> [--no-atomic]     # Import entries (path=- for STDIN)
candy-kv export <json|yaml|yml> [db] [pattern]           # Export entries
```

`--ttl` and `-d` accept both the attached and space-separated forms.
A `--ttl` that is not a non-negative integer (e.g. `--ttl=soon`) is refused
with exit 2 — it never lands in the value.

### Exit codes

| Command | Code | Meaning |
|---|---|---|
| `get` | 0 | key found |
| `get` | 1 | key not found (suggestion, if any, goes to stderr) |
| `get 'user-*'` | 2 | patterns are not supported by `get` — use `list` |
| `set` / `list` / `delete` / `import` / `export` | 0 | success |
| `delete` | 1 | nothing matched the key/pattern |
| `set --ttl soon`, bad `set` flags | 2 | invalid usage (nothing is written) |
| unknown command | 1 | see `candy-kv --help` |

### TTL (Time-To-Live)

Use `--ttl` to set keys that automatically expire:

```bash
candy-kv set session:abc123 '{"user":"bob"}' --ttl=3600  # expires in 1 hour
```

Expiry is stored as an integer epoch second. A database written on a machine
in one timezone reads with the same true instants everywhere; pre-2026-10
databases stored offset-bearing ISO strings — candy-kv migrates those rows
once, on open, preserving each row's exact instant. Entries expire at the
second boundary (`>= now`), matching `Entry::isExpired()`.

### STDIN

When no value is given as an argument, `set` reads from stdin. For import, use `-` or `/dev/stdin` as the path:

```bash
echo "my-secret-token" | candy-kv set api-token
candy-kv import json - < backup.json
```

> **Note:** piped values are stored verbatim — the full stdin is read with no
> trimming, byte-for-byte.
> `echo` therefore stores its trailing newline; use `printf '%s' token |` (or
> pass the value as an argument) when you need the exact bytes without it.

### Import / Export

Import and export support JSON and YAML (`yml` accepted as an alias). Import
runs inside an atomic transaction by default; pass `--no-atomic` to disable:

```bash
candy-kv import json backup.json
candy-kv import yaml -             # read from STDIN
candy-kv export json > dump.json
candy-kv export yaml mydb 'user-*'  # export filtered keys from a specific database
```

Both formats are value-preserving: backslashes, newlines, colons, empty
strings and control bytes all round-trip, and binary entries come back as
binary — through JSON **and** both YAML engines.

**JSON envelope.** Plain `"key": "value"` pairs. Binary entries ride
base64; their keys are listed in a reserved `"_binary"` array. Remaining
TTLs (whole seconds) collect into a reserved `"_ttl"` object:

```json
{
  "session:abc123": "{\"user\":\"bob\"}",
  "icon": "iVBORw0KGgo=",
  "_binary": ["icon"],
  "_ttl": { "session:abc123": 3540 }
}
```

**YAML envelope.** A flat `key: value` dialect — one pair per line, keys
quoted when needed, values quoted/escaped as required. Binary entries ride
the same base64; TTLs become `skate_ttl_<key>` integer lines and binary
flags `skate_binary_<key>` lines. When symfony/yaml is installed it renders
and parses the identical flat shape (plus `!!binary` tags for stray control
bytes, which the fallback parser also reads). Export refuses keys that
would collide with the reserved metadata shapes (`_ttl`, `_binary`,
`skate_ttl_…`, `skate_binary_…`) instead of emitting unreadable files.

### CLI surface vs library surface

The CLI covers `set`, `get`, `list`, `delete`, `list-dbs`, `import` and
`export`. Everything else is library-only: `Store::count()`, `entry()`,
`getRaw()`, `setWithTtl()`, `transaction()`, `fuzzyFilter()` and
`deleteDatabase()` have no CLI verbs by design — the binary stays a thin
front for the everyday loop. Key-bearing commands route to a named database
through the `key@dbname` suffix; `list` alone always scans the default
database (patterns cannot select a db), while `export <format> [db]` takes
an explicit database name argument.

## Public API

### Store

| Method | Purpose |
|---|---|
| `__construct(?string $dataDir = null, string $defaultDb = 'default')` | Optional data dir (else `resolveDataDir()`); opens the default DB eagerly |
| `resolveDataDir(): string` (static) | `XDG_CONFIG_HOME/skate` → `$HOME/.config/skate` → passwd home; throws when none exist |
| `set(string $key, string $value, bool $binary = false, ?int $ttlSeconds = null): Entry` | Store a value; `$binary` base64-wraps it; TTL in whole seconds |
| `setWithTtl(string $key, mixed $value, int $ttlSeconds): void` | Convenience setter — scalar values only (throws on arrays/objects); `ttl <= 0` is a documented NO-OP (stores nothing) |
| `get(string $key, string $fallback = ''): string` | Exact-key read; prints a did-you-mean to stderr on miss (stderr only — the fallback is returned) |
| `entry(string $key): ?Entry` | Full entry (metadata included) |
| `list(?string $pattern = null, ?string $dbName = null, bool $reverse = false, string $mode = 'all', string $delimiter = "\t")` | Lazy generator — `Entry` objects, or formatted `string` lines with `$mode` `'keys'`/`'values'` |
| `delete(string $key): int` | Exact key or glob; returns entries removed |
| `setFile(string $key, string $filePath): Entry` | Store file contents as a binary entry |
| `getFile(string $key, string $filePath): bool` | Write a (binary) entry to disk |
| `fuzzyFilter(string $query, ?string $dbName = null): array` | Live Smith-Waterman scored key search (sugar-fuzzy), best match first |
| `listDatabases(): array` | Database names in the data dir (`settings` is reserved — see note in `Database::listDatabases()`) |
| `deleteDatabase(string $dbName): bool` | Closes the handle, unlinks `<db>.db` + WAL/SHM sidecars |
| `transaction(string $dbName, callable $fn): mixed` | BEGIN IMMEDIATE/COMMIT/ROLLBACK around a callback (no nesting) |
| `dataDir(): string` / `defaultDatabase(): string` | Accessors |

### Entry / Database

`Entry` carries `key`, `value` (the stored form — base64 text for binary
entries), `binary`, `createdAt`/`modifiedAt` (`DateTimeImmutable`, display
timestamps only), `expiresAt` (`?DateTimeImmutable`), `isExpired()`, and
`rawValue()` which base64-decodes when `binary` is set.
`Database` is the single-file layer behind a store name: `get/getRaw/set/
delete/deleteMany/list/allKeys/count/transaction/close` — `count(?pattern)`
counts live entries, `getRaw()` skips the expiry filter.

## Privacy & file modes

- Values are stored **unencrypted** — SQLite is a container, not a vault. The
  token example above is readable with `sqlite3` or any text editor.
- Database files are created `0600` (owner-only) and existing files are
  tightened to `0600` on open; the data directory is created `0700`. WAL mode
  adds `*-wal` / `*-shm` sidecars, also `0600`.
- `get` on a typo prints the closest key name to stderr. On shared machines,
  remember key names can themselves be sensitive — the output goes to stderr
  only, but stderr is still a terminal log.
- Backups: copy the `.db` **together with its `-wal` sidecar** (or checkpoint
  first). Copying only `*.db` under WAL can lose the most recent writes.

## Data Directory

Default: `~/.config/skate/` (respects `$XDG_CONFIG_HOME`). Each database is
its own SQLite file: `<dir>/<dbname>.db` (`default.db` for unsuffixed keys).

The directory and prefix keep the pre-rename **skate** name on disk so
existing installs (and upstream skate itself) keep working; new code can
point `$XDG_CONFIG_HOME` anywhere. If neither `XDG_CONFIG_HOME` nor `$HOME`
is set, candy-kv falls back to the passwd home and throws loudly if even
that is unavailable — it never writes into the install tree.

## Demos

### Glob pattern matching

![glob](.vhs/glob.gif)

## Shared foundations

candy-kv uses [sugar-fuzzy](https://github.com/detain/sugarcraft#sugar-fuzzy) for Smith-Waterman scored matching: `Store::fuzzyFilter()` is a live API that ranks keys against a free-text query. Glob-to-SQLite `LIKE` pattern filtering remains the active filter path for `list`/`delete`.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem (and the skate key/value tool that grew out of it); SugarCraft is developed as a native PHP project.
