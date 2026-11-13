# Changelog

All notable changes to candy-kv are documented here. Dates are the day the
change landed in the monorepo.

## 2026-11-03 — CLI & storage contract hardening

A full audit round (multi-persona review + fix wave). Changes consumers can
observe:

### Fixed — CLI contract

- **Every flag is answered or refused.** Unknown options, surplus
  positionals, and unknown commands now exit **2** with a message naming the
  offending token, instead of being silently ignored (a typo'd `--ttl300`
  used to store a forever-secret with no warning).
- **`--` end-of-options sentinel** added; `set k -- --ttl=60` stores the
  literal value. A bare `-` still means STDIN.
- **`--ttl` bounds.** Values must be positive whole seconds
  (`1..999999999`); `--ttl 0` is now a usage error (it used to mean
  "forever" at the CLI while `setWithTtl(<=0)` meant "store nothing" —
  two zero semantics, both retired).
- **`set` is binary-safe by default.** Values containing NUL bytes or
  invalid UTF-8 are auto-detected and stored base64-encoded with the binary
  flag (`--binary`/`--text` force either way). Previously the CLI hardcoded
  the binary flag off and corrupted binary values on store.
- **`get` prints the exact stored bytes** with no trailing newline and no
  scrubbing; `--sanitize` opts into the single-line control-free form. On a
  miss the CLI (not the library) prints a localized `key not found` /
  did-you-mean line to stderr and exits 1.
- **New verbs/flags:** `prune` (delete expired rows from the default db),
  `delete --dry-run` (report match count, write nothing).
- **Singular/plural success grammar:** "Deleted 1 entry." / "Imported 1
  entry." (previously "Deleted 1 entries.").
- **Import is fail-fast.** Top-level lists/sequences, non-string/number
  values, and bad `_ttl` shapes refuse the whole document *before* any
  write; the atomic multi-database guard now sees every key regardless of
  value shape. `--no-atomic` is named in the refusal text.
- **YAML parse errors carry the line number** ("Syntax error on line 3:
  expected ':' after the key"); the symfony engine's line/column summary is
  relayed instead of discarded.
- **Reserved-key refusals are format-scoped:** `_ttl`/`_binary` block JSON
  export only; `skate_ttl_*`/`skate_binary_*` block YAML export only. A
  YAML-prefixed key no longer poisons `export json`.
- **Empty-store JSON export** is `{}` (object), never `[]` (list).
- Locale detection wired: `LC_ALL`/`LC_MESSAGES`/`LANG` select the CLI
  language across all 16 shipped locales (previously dormant — English only
  at runtime).

### Fixed — storage semantics

- **Pattern operations are case-sensitive** (`PRAGMA case_sensitive_like`),
  matching exact-key operations; previously `delete 'Abc-*'` could touch
  `abc-1`.
- **`\*` / `\?` escape literal glob characters**; `?` matches one
  character (the LIKE runs over the stored TEXT, so a multi-byte codepoint
  fills the single wildcard — pinned by `StorageContractTest`). A backslash
  before anything else stays literal.
- **Expired rows are invisible to `delete`/`deleteMany`** — they are
  `pruneExpired()`'s complement now; readers, deleters and `count` all agree
  on the live set.
- **Reads never create files.** Constructing a `Store` touches nothing;
  `get`/`list`/`suggestions` on a missing database see emptiness instead of
  writing a fresh (and freshly permissioned) `.db`. Writes create as
  before; opening an existing file no longer silently creates a sibling.
- **`XDG_CONFIG_HOME`/`HOME` must be absolute** when set — a relative env
  value used to relocate the whole store with the working directory.
- **Secret-safe file modes:** `saveTo()` creates/tightens its target to
  `0600` *before* writing bytes; the data directory re-asserts `0700`
  regardless of umask; `deleteDatabase()` reports `false` when a WAL sidecar
  survives (surviving sidecars can hold recoverable rows).
- **Empty entry keys are refused** (`set ""` used to succeed).
- **`key\@db` spelling:** `\@` escapes a literal `@` in keys; routing uses
  the rightmost *unescaped* `@`; a leading `@` is a literal key.
- **Typo suggestions are codepoint-based** (accented/CJK keys are no longer
  scored byte-fragments) and computed only on demand — the library never
  writes to STDERR.
- **`Entry::rawValue()` on a binary row with corrupt base64 raises**
  instead of returning the base64 text as "the value".
- SQLite errors surface as exceptions (no more silent `execute() === false`
  paths); the WAL sidecar permission story is enforced on open.

### Internal

- `Store::splitKey()` is the single public implementation of the `@`
  routing law (importers previously kept hand-copied variants).
- Dead code removed: `Store::defaultDataDir()`, duplicated export key
  branch, the no-FK `PRAGMA foreign_keys` arm.
- `listDatabases()` moved from `glob()` to `scandir` (env-derived paths
  with `[`/`*` no longer mis-list).

## 2026-10-24 — bare-accessor sweep

- `Database::getRaw()` → `Database::raw()`; `Store::getFile()` →
  `Store::saveTo()` (direction-verb rename — the method writes store data
  *out* to disk). Legacy spellings kept as byte-identical `@deprecated`
  forwarders marked `REMOVED-IN: 2.0.0`; **they are removed in v2.0.0**.
  CLI `get` is unrelated and unchanged.

## 2026-10 — rename: sugar-skate → candy-kv

- Package `sugarcraft/sugar-skate` → `sugarcraft/candy-kv`; namespace
  `SugarCraft\Skate\` → `SugarCraft\Kv\`; binary `skate` → `candy-kv`.
- The on-disk data directory keeps the historical `skate` name
  (`$XDG_CONFIG_HOME/skate/`) so pre-rename stores stay readable.
- TTL storage migrated from offset-bearing `TEXT` timestamps to integer
  epoch seconds, done once per database on open, preserving each row's
  exact instant.

## Earlier

- Original PHP implementation of a skate-style key/value store: multi-
  database `@db` routing, glob listing/deletion, binary entries via base64,
  JSON/YAML import/export, Levenshtein did-you-mean, TTL expiry.
