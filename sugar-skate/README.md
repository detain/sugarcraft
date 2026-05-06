# SugarSkate

PHP port of [charmbracelet/skate](https://github.com/charmbracelet/skate) — a personal key/value store with multi-database support, binary data handling, and glob/list filtering.

## Features

- **Multi-database** — separate stores with `@dbname` suffix, auto-created on first use
- **Binary data** — safely stores and retrieves raw binary (images, files) via base64 encoding
- **Glob pattern matching** — list/get/delete keys using `*` and `?` wildcards
- **Ordered listing** — forward or reverse lexicographic order
- **Flexible listing** — keys only, values only, or key-value pairs
- **SQLite-backed** — one SQLite DB per database, stored in `$XDG_CONFIG_HOME/skate/` or `~/.config/skate/`
- **PHP 8.1+** — pure PHP, no extension required beyond SQLite (php-sqlite3)
- **Iterable streams** — list() yields results without loading everything into memory

## Install

```bash
composer require candycore/sugar-skate
```

## Quick Start

```php
use CandyCore\Skate\Store;

$skate = new Store();

// Set and get
$skate->set('greeting', 'Hello, World!');
echo $skate->get('greeting'); // Hello, World!

// With a database
$skate->set('token', 'ghp_xxxx', 'passwords');
echo $skate->get('token', 'passwords');

// List all keys
foreach ($skate->list() as $entry) {
    echo "{$entry->key} => {$entry->value}\n";
}

// Glob patterns
foreach ($skate->list(pattern: 'user-*') as $entry) { ... }

// Delete
$skate->delete('greeting');
```

## CLI

```bash
skate set <key> [value]          # Set a key
skate get <key>                  # Get a value
skate list [-k|-v] [-r] [-d delim] [pattern]  # List entries
skate delete <key>               # Delete a key
skate list-dbs                   # Show all databases
```

## Data Directory

Default: `~/.config/skate/` (respects `$XDG_CONFIG_HOME`).

Each database gets its own SQLite file: `~/.config/skate/<dbname>.db`.

## License

[MIT](LICENSE)
