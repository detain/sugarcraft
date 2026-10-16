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

- **Multi-database** — separate stores with `@dbname` suffix, auto-created on first use
- **Binary data** — safely stores and retrieves raw binary (images, files) via base64 encoding
- **Glob pattern matching** — list/get/delete keys using `*` and `?` wildcards
- **Ordered listing** — forward or reverse lexicographic order
- **Flexible listing** — keys only, values only, or key-value pairs
- **SQLite-backed** — one SQLite DB per database, stored in `$XDG_CONFIG_HOME/skate/` or `~/.config/skate/`
- **PHP 8.3+** — pure PHP, no extension required beyond SQLite (php-sqlite3)
- **Iterable streams** — list() yields results without loading everything into memory
- **TTL / expiry** — set keys that automatically expire after a given number of seconds
- **Levenshtein suggestions** — typo suggestions on key miss (get command prints to stderr)
- **Import/Export** — bulk load or save entries in JSON or YAML format, with optional atomic transaction
- **Atomic transactions** — Database::transaction() wraps a callback in BEGIN/COMMIT/ROLLBACK

## Install

```bash
composer require sugarcraft/candy-kv
```

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
$store->set('token@passwords', 'ghp_xxxx');
echo $store->get('token@passwords');

// List all keys
foreach ($store->list() as $entry) {
    echo "{$entry->key} => {$entry->value}\n";
}

// Glob patterns
foreach ($store->list(pattern: 'user-*') as $entry) { ... }

// Delete
$store->delete('greeting');
```

## CLI

```bash
candy-kv set <key> [value] [--ttl=SECONDS]          # Set a key (reads stdin if no value)
candy-kv get <key>                                # Get a value (suggests typo on miss)
candy-kv list [-k|-v] [-r] [-d delim] [pattern]   # List entries
candy-kv delete <key>                              # Delete a key (glob patterns supported)
candy-kv list-dbs                                   # Show all databases
candy-kv import <json|yaml> <path> [--no-atomic]   # Import entries (path=- for STDIN)
candy-kv export <json|yaml> [db] [pattern]         # Export entries
```

### TTL (Time-To-Live)

Use `--ttl` to set keys that automatically expire:

```bash
candy-kv set session:abc123 '{"user":"bob"}' --ttl=3600  # expires in 1 hour
```

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

Import and export support JSON and YAML formats. Import runs inside an atomic transaction by default; pass `--no-atomic` to disable:

```bash
candy-kv import json backup.json
candy-kv import yaml -             # read from STDIN
candy-kv export json > dump.json
candy-kv export yaml mydb 'user-*'  # export filtered keys from a specific database
```

The export output includes remaining TTL as a `_ttl` top-level key in JSON, or `skate_ttl_<key>` entries in YAML.

## Data Directory

Default: `~/.config/skate/` (respects `$XDG_CONFIG_HOME`).

Each database gets its own SQLite file: `~/.config/skate/<dbname>.db`.

## Demos

### Glob pattern matching

![glob](.vhs/glob.gif)

## Shared foundations

candy-kv uses [candy-fuzzy](https://github.com/detain/sugarcraft#candy-fuzzy) for Smith-Waterman scored matching infrastructure. The matcher is wired for future fuzzy key search; glob-to-SQLIKE pattern filtering remains the active filter path today.

## License

[MIT](LICENSE)

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
