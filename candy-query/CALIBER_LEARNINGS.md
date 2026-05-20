# Caliber Learnings — candy-query

Accumulated patterns and anti-patterns specific to this library.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:sqlite-pragma-schema]** — SQLite PRAGMA results (`table_info`, `index_list`, `index_info`, `foreign_key_list`) return untyped scalar arrays. Wrap each in a dedicated private method that returns typed `SchemaColumn`/`SchemaIndex`/`SchemaForeignKey` value objects — the types catch mis-indexed row access at construction time rather than at call site. Canonical: `SchemaBrowser::loadColumns()` / `loadIndexes()` / `loadForeignKeys()`.

- **[pattern:immutable-cursor-pager]** — Cursor-based pagination over an in-memory result-set is naturally immutable: `nextPage()` / `prevPage()` return `new self(...)` with a shifted offset rather than mutating `$this`. Storing `$rows` (the full set) as a constructor arg keeps the pager stateless between navigation calls and enables `withPageSize()` to recompute offset clamps without re-querying. Canonical: `ResultPager`.
