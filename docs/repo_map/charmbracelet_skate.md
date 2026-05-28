# charmbracelet/skate

## Metadata
- URL: https://github.com/charmbracelet/skate
- Language: Go
- Stars: ~1.8k–2k
- License: MIT
- Description: A personal key-value store. 🛼

## Feature List
- **Key-Value Storage**: Store and retrieve string/binary data by key
- **Multiple Databases**: Support for named databases via `KEY@DB` syntax (e.g., `secret@passwords`)
- **List Operations**: List all keys, all values, or key-value pairs with configurable delimiter
- **Reverse Iteration**: List entries in reverse lexicographic order
- **Binary Data Support**: Store and retrieve binary data (images, etc.) with `-b` flag
- **Unicode Support**: Full Unicode key/value support
- **Database Management**: `list-dbs`, `delete-db` commands for managing multiple databases
- **Fuzzy DB Matching**: Levenshtein-distance-based suggestions when a database isn't found
- **Standard Input/Output**: Can read value from stdin (`skate set foo < file.txt`) and write to stdout
- **Local-Only**: As of v1.0.0, no longer syncs to Charm Cloud — fully local operation

## Key Classes and Methods
- `main.go` (443 lines, single-file CLI):
  - `set()` — Store a key-value pair (from args or stdin)
  - `get()` — Retrieve a value by key
  - `del()` — Delete a key
  - `list()` — List key-value pairs with iterator options (reverse, keys-only, values-only, delimiter, show-binary)
  - `listDbs()` — List all available databases
  - `deleteDb()` — Delete an entire database with confirmation prompt
  - `keyParser(string)` — Split `KEY@DB` syntax into key bytes and db name
  - `openKV(string)` — Open a Badger DB by name (auto-creates "default" if unnamed)
  - `getFilePath(...string)` — Resolve path to the kv data directory via `go-app-paths`
  - `findDb(string)` — Find DB path with Levenshtein suggestions on miss
  - `printFromKV(string, ...[]byte)` — Format output, detecting binary vs UTF-8
  - `wrap(db, readonly, fn)` — Transaction helper (Badger read/write wrapper)
  - `errDBNotFound` — Custom error type carrying Levenshtein suggestions

## Notable Algorithms / Named Patterns
- **Levenshtein Distance**: Used in `findDb()` to suggest close database names when a lookup fails (line 286: `levenshtein.ComputeDistance(name, db)`)
- **Badger KV Store**: Underlying storage engine — a fast, embeddable key-value database written in Go (Dgraph.io)
- **go-app-paths**: Cross-platform app data directory resolution (`~/.local/share/charm/kv/`)
- **Cobra CLI Framework**: Command-line argument parsing (`spf13/cobra`)
- **Transaction Pattern**: `wrap()` helper creates a Badger transaction, executes a function, and handles commit/discard

## Strengths
- **Zero-configuration**: Data directory created automatically on first use
- **Simple CLI interface**: Familiar syntax (`set`, `get`, `list`, `delete`, `list-dbs`, `delete-db`)
- **Multi-database isolation**: Logical separation via `@db` suffix without extra setup
- **Binary data support**: Full transparency for non-UTF-8 data
- **Fast embedded storage**: Badger provides high-performance, embeddable KV storage
- **Cross-platform**: Works on Linux, macOS, Windows; paths resolve correctly per OS conventions
- **Graceful error handling**: Fuzzy database name suggestions reduce user frustration
- **Self-contained**: Single binary, no server or daemon required
- **Privacy-focused post-v1.0**: Cloud sync removed — fully local

## Weaknesses
- **No encryption at rest**: Data stored in plain files in the app data directory
- **No authentication/access control**: Anyone with file access can read all databases
- **No atomic multi-key operations**: No transaction support across multiple keys
- **No query/search beyond prefix/lists**: No pattern matching, range queries, or value-based filtering
- **Single-file implementation**: While simple, a larger Go module structure would be more maintainable for extension
- **Simple confirmation prompt**: `delete-db` uses a basic `fmt.Scanln` instead of a proper TUI confirmation
- **No sync/export/import**: No built-in way to migrate data between machines or back up databases

## SugarCraft Mapping
- **SugarSkate** (`sugar-skate/`): The direct PHP port of `charmbracelet/skate`
  - Role: Personal key/value store
  - Subdir: `sugar-skate/`
  - Composer pkg: `sugarcraft/sugar-skate`
  - Namespace: `SugarCraft\Skate`
  - Status: 🟢 v1.0.0 equivalent — local-only KV store with Badger-equivalent storage in PHP
  - Maps to: `candy-core` (base runtime), `candy-sprinkles` (styling), `candy-kit` (presentation helpers)
- **Potential future ports**:
  - **SugarPersist** — if someone wanted to extract a generalized KV storage abstraction layer
  - **SugarDB** — multi-database file-based storage pattern could inspire a SugarCraft data abstraction lib

## Analysis

**charmbracelet/skate** is a minimalist personal key-value store CLI written in Go, clocking ~1.8k–2k GitHub stars. Its design philosophy mirrors the broader Charmbracelet ecosystem: approachable, colorful, and self-contained. The entire application is implemented as a single `main.go` file using the `cobra` library for CLI argument parsing and `badger` as the underlying storage engine. The standout design decision was the `@DB` syntax for multi-database support — elegant in its simplicity, leveraging the same filesystem layout where each database is a subdirectory under `~/.local/share/charm/kv/`. The Levenshtein-distance suggestions when a database is not found (`findDb()`) demonstrates user-experience consideration rare in simple CLI tools.

As of v1.0.0, skate severed its Charm Cloud sync feature, shifting to fully local-only operation — a pragmatic privacy decision. The implementation is straightforward: all operations (`set`, `get`, `list`, `delete`) are thin wrappers around Badger transactions, with proper resource cleanup via `defer db.Close()` and a `wrap()` helper function. The `list` command uses Badger's iterator API with options for reverse traversal, key-only iteration, and value-only iteration — the same cursor/iterator pattern common in embedded database libraries.

**For SugarCraft**: The skate port (`sugar-skate`) maps cleanly as a top-level application using the `SugarCraft\Skate` namespace. It would consume `candy-core` for the runtime contract, `candy-sprinkles` for any TUI styling output, and `candy-kit` for presentation helpers. The storage layer in PHP would need to replicate Badger's semantics — likely using SQLite as a PHP-native embeddable KV store, since there's no direct Badger equivalent in PHP. The `@db` multi-database pattern translates naturally to SQLite `ATTACH` or separate database files.
