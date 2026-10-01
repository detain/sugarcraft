# sugar-skate Caliber Learnings

Accumulated patterns and gotchas discovered during porting and auditing.

## TTL / expiry_at migration

- Schema includes `expires_at TEXT` column (ISO 8601 datetime). Legacy DBs without the column are migrated automatically via `ALTER TABLE` on open.
- `get()` filters out expired entries (`expires_at >= now`). Use `getRaw()` to bypass the filter.
- `setWithTtl()` is a convenience wrapper that discards non-positive TTLs as no-ops.
- ExportCommand reports remaining TTL as a `_ttl` map in JSON and `skate_ttl_<key>` entries in YAML.

## Levenshtein typo suggestions

- `suggestSimilar()` is private and called only from `get()` when a key is missing.
- Distance threshold: `strlen(key) / 2` — long keys allow more drift before suggestion is suppressed.
- Suggestion is written to `STDERR` to keep stdout clean for the value output.

## Atomic transactions

- `Database::transaction(callable $fn)` wraps `BEGIN IMMEDIATE` / `COMMIT` / `ROLLBACK`.
- On callback exception the transaction rolls back and the exception propagates.
- Multi-database atomic import throws `\RuntimeException` — this is a documented limitation (SQLite transactions are per-connection).
- ImportCommand supports `--no-atomic` to disable transaction wrapping for single-db imports or when manual rollback is needed.

## STDIN handling

- `bin/skate set`: when no positional value argument is given, reads ALL of STDIN (`stream_get_contents`) with **no trim** — declared product decision: byte-faithful like upstream, which does `io.ReadAll(cmd.InOrStdin())` (`cat file | skate set key` round-trips exactly). Consequence: `echo token | skate set key` stores the trailing `\n`; documented in README's STDIN section (`printf '%s'` hint). The old learning ("reads one line and trim()s") was FALSE to the code.
- `bin/skate import`: path `-` or `/dev/stdin` reads all of `php://stdin` content. NOTE: reading stdin IN-PROCESS from a test hangs forever when the suite runs on a TTY — all stdin coverage lives in `CliSmokeTest` as child processes with stdin closed/piped (see audit #2).
- Import always wraps in atomic transaction by default; pass `--no-atomic` to disable.

## i18n scope

- Localised surface: the `bin/skate` usage/diagnostic lines routed through `Lang::t('cli.usage_*')` / `cli.deleted_n` / `cli.unknown_command`, plus `store.cannot_read` / `database.entry_unreadable`.
- Intentionally UNTRANSLATED (English by design, ruled in the 2026-10-01 audit fix wave): the runtime stdout/stderr feedback inside `ImportCommand`/`ExportCommand` ("Imported N entries.", "File not found: …", …) and RuntimeException texts in the importers/`Database` (programmer-facing exception details). If a future wave localises them, `cli.import_success`/`cli.export_success` were deleted as dead keys in this pass — re-add them to en.php AND all 16 locales in one commit (locale parity is checked by key-set census).

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-05-31 — Use candy-fuzzy for scored filter matching
Pattern: When a lib needs type-to-filter with ranked results, adopt `sugarcraft/candy-fuzzy` and use `SmithWatermanMatcher::matchAll()` — it returns scored `MatchResult` objects with grapheme-aligned highlight indices.
Anti-pattern: Ad-hoc `str_contains()` or `stripos()` boolean filtering; it gives no ranking signal and no match-position data for highlighting.
Source: step-33 ai/filter-consumers
