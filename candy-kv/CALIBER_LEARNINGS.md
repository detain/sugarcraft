# candy-kv Caliber Learnings

Accumulated patterns and gotchas discovered during porting and auditing.

## TTL / expiry_at migration

- Fresh schema: `expires_at INTEGER` holding absolute **epoch seconds**. All SQL predicates compare `CAST(expires_at AS INTEGER) >= :now` bound with `time()` as an integer, so offset-bearing legacy text, numeric text, and integer rows all judge by true instant.
- Legacy DBs migrate ONCE, on open (`Database::migrateExpiryColumn()`): rows whose stored value is non-numeric text are parsed with `DateTimeImmutable` (offset-aware) and rewritten as epoch integers inside a `BEGIN IMMEDIATE` transaction; unparseable text refuses the open loudly. An empty legacy string normalises to NULL (no expiry) — parsing `''` would mean "now".
- GOTCHA (measured): a TEXT-affinity column converts an INTEGER bind to numeric TEXT (`typeof` stays `'text'`, value is the digit string). `CAST` reads it identically; do NOT pin `typeof(expires_at)='integer'` after migrating a legacy table — pin the digit-string shape instead.
- GOTCHA (measured): SQLite GLOB negates a set with `[^…]`, NOT `[!…]` — `[!0-9]` is the literal set `!0-9` and matches every digit, so a "skip pure digits" guard written with `[!…]` re-selects migrated rows on every open (the tip binary then tries to parse its own epoch as a date). The bin-level probe in `DatabaseTtlMigrationTest` (second open of a migrated file) is what caught this — in-process pins open legacy files only once.
- `created`/`modified` remain `DATE_ATOM` strings — display metadata only, never compared in SQL.
- `get()` filters out expired entries. Use `getRaw()` to bypass the filter (it also sees pre-migration legacy rows: `Entry::fromRow()` accepts epoch ints, digit strings, and legacy ISO texts).
- `setWithTtl()` takes scalar values (arrays/resources throw `InvalidArgumentException`); `ttl <= 0` is a documented NO-OP that stores NOTHING (it does not fall back to "forever").
- `Entry::isExpired()` and the SQL filter agree at the second boundary; the residual same-second race (`>=` vs `<` on sub-second clocks) is resolved in the user's favour on reads, by design.
- Export reports remaining TTL as a `_ttl` map in JSON and `skate_ttl_<key>` integer lines in YAML — identical shape under both YAML engines since the D4 envelope unification.

## Levenshtein typo suggestions

- `suggestSimilar()` is private and called only from `get()` when a key is missing.
- Distance threshold: `strlen(key) / 2` — long keys allow more drift before suggestion is suppressed.
- Suggestion is written to `STDERR` to keep stdout clean for the value output. Since D4 the line is localised (`Lang::t('store.did_you_mean')`); it used to be a hardcoded English `fwrite` bypassing i18n.

## YAML envelope / YamlDialect

- One flat `key: value` dialect for BOTH engines (`YamlDialect::build/parse`): entries, `skate_ttl_<key>` integer lines, `skate_binary_<key>` = `1`. symfony/yaml is a SUGGEST — when installed the component renders/parses the same flat map; when absent a hand-rolled emitter+parser takes over. `buildFallback()`/`parseFallback()` are public `@internal` seams so the fallback stays testable while symfony is installed in dev.
- Binary entries ride base64 in the value field (JSON `_binary` key list / YAML `skate_binary_` flags) — raw bytes in `json_encode` were a hard export failure before D4. Importers decode strictly; invalid base64 refuses naming the key.
- Export refuses keys colliding with reserved metadata shapes (`_ttl`, `_binary`, `skate_ttl_…`, `skate_binary_…`) rather than emitting an unreadable file.
- Fallback emitter quoting: quote on any of empty, control bytes, backslash, colon, `#`, quotes, leading indicator, reserved word (`~ null true false yes no on off`), or numeric-lookalike; escape `\n \r \t \0 \\ \" \xHH` inside double quotes. Parser applies the EXACT inverse — sentinels (`~`/`null`) and coercion apply to PLAIN tokens ONLY, quoted `"~"` stays a string.
- Symfony's dumper tags control-byte strings as inline `!!binary <base64>` (never wrapped — verified); the fallback reads that tag, and refuses any other `!!` tag loudly. Both engines skip lone `---`/`...` marker lines (symfony route strips them first — it otherwise dies with "Multiple documents are not supported").
- PHP escaping gotcha (bit once): inside PHP **single quotes**, `''` is not an escape — the escaper must emit `\'` (SQL-style doubling breaks `php -l` across whole locale files).

## File modes

- Every `.db` (and its `-wal`/`-shm` sidecars) is forced to `0600` on open — newly created AND pre-existing loose files (secrets store; tightening existing files is a deliberate behaviour change shipped with docs-fix-1023 D4). Data dir is `0700`.
- GOTCHA: `fileperms()` reads PHP's stat cache — tests asserting a mode chmod'ed inside `Database` MUST call `clearstatcache(true, $path)` first or they red on a file they themselves just tightened.

## Data directory resolution

- `Store::resolveDataDir()` (public static): `XDG_CONFIG_HOME/skate` → `$HOME/.config/skate` → passwd home (`posix_getpwuid`) → `RuntimeException`. The pre-D4 fallback `dirname(__DIR__, 2)` wrote databases into the INSTALL (vendor/web-served) tree when `HOME` was unset — never fall back into the code tree.
- The directory keeps the legacy `skate` name (back-compat with existing installs and upstream skate); the `skate_ttl_`/`skate_binary_` YAML prefixes share that fate and stay.

## Atomic transactions

- `Database::transaction(callable $fn)` wraps `BEGIN IMMEDIATE` / `COMMIT` / `ROLLBACK`.
- On callback exception the transaction rolls back and the exception propagates.
- Multi-database atomic import throws `\RuntimeException` — this is a documented limitation (SQLite transactions are per-connection).
- ImportCommand supports `--no-atomic` to disable transaction wrapping for single-db imports or when manual rollback is needed.

## STDIN handling

- `bin/candy-kv set`: when no positional value argument is given, reads ALL of STDIN (`stream_get_contents`) with **no trim** — declared product decision: byte-faithful like upstream, which does `io.ReadAll(cmd.InOrStdin())` (`cat file | skate set key` round-trips exactly). Consequence: `echo token | candy-kv set key` stores the trailing `\n`; documented in README's STDIN section (`printf '%s'` hint). The old learning ("reads one line and trim()s") was FALSE to the code.
- `bin/candy-kv import`: path `-` or `/dev/stdin` reads all of `php://stdin` content. NOTE: reading stdin IN-PROCESS from a test hangs forever when the suite runs on a TTY — all stdin coverage lives in `CliSmokeTest` as child processes with stdin closed/piped (see audit #2).
- Import always wraps in atomic transaction by default; pass `--no-atomic` to disable.

## i18n scope

- Localised surface: the `bin/candy-kv` usage/diagnostic lines routed through `Lang::t('cli.usage_*')` / `cli.deleted_n` / `cli.deleted_none` / `cli.pattern_not_supported` / `cli.unknown_command`, plus `store.cannot_read` / `store.did_you_mean` (the typo suggestion is translated since D4; it previously bypassed i18n with a hardcoded English `fwrite`) / `database.entry_unreadable` / `database.query_failed`.
- Intentionally UNTRANSLATED (English by design, ruled in the 2026-10-01 audit fix wave): the runtime stdout/stderr feedback inside `ImportCommand`/`ExportCommand` ("Imported N entries.", "File not found: …", …) and RuntimeException texts in the importers/`Database` (programmer-facing exception details). If a future wave localises them, `cli.import_success`/`cli.export_success` were deleted as dead keys in that pass — re-add them to en.php AND all 16 locales in one commit.
- Locale parity is ENFORCED: `tests/LocaleParityTest.php` re-derives the en key set from `lang/` and fails on missing/extra keys per locale, on dropped/invented `{placeholders}`, and on empty values. The locale list is the `lang/*.php` glob, so new locales auto-join the guard.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-05-31 — Use sugar-fuzzy for scored filter matching
Pattern: When a lib needs type-to-filter with ranked results, adopt `sugarcraft/sugar-fuzzy` and use `SmithWatermanMatcher::matchAll()` — it returns scored `MatchResult` objects with grapheme-aligned highlight indices.
Anti-pattern: Ad-hoc `str_contains()` or `stripos()` boolean filtering; it gives no ranking signal and no match-position data for highlighting.
Source: step-33 ai/filter-consumers
