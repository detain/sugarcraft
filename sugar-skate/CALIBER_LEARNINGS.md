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

- `bin/skate set`: when no positional value argument is given, reads one line from STDIN and `trim()`s it.
- `bin/skate import`: path `-` or `/dev/stdin` reads all of `php://stdin` content.
- Import always wraps in atomic transaction by default; pass `--no-atomic` to disable.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

### 2026-05-31 — Use candy-fuzzy for scored filter matching
Pattern: When a lib needs type-to-filter with ranked results, adopt `sugarcraft/candy-fuzzy` and use `SmithWatermanMatcher::matchAll()` — it returns scored `MatchResult` objects with grapheme-aligned highlight indices.
Anti-pattern: Ad-hoc `str_contains()` or `stripos()` boolean filtering; it gives no ranking signal and no match-position data for highlighting.
Source: step-33 ai/filter-consumers
