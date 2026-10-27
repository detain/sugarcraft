### C-SES-2.a — FTS index: one-transaction rebuild + persisted build marker
- item: C-SES-2 (sources: SES-2-P2)
- kind: fix · MAJOR · effort M
- approach:
  - **Root cause.** `SessionSearchIndex::$built` starts `false` in every process (`src/Session/SessionSearchIndex.php:68-70`), so `needsRebuild()` (`:102-105`) is true on each launch's first body search. `EnhancedSessionStore::rebuildSearchIndex()` (`src/Session/EnhancedSessionStore.php:2083-2103`) then runs `clearAll()` plus a per-session `syncMessages()` with no enclosing transaction: one WAL commit per row, run inline from `listSessionsFiltered()` (`:962`) on `update()`. The review measured 128 s at 300 sessions. Any throw inside `search()` also sets `dirty`, which forces another full rebuild.
  - **Fix, three parts:**
    1. Run the sweep inside `sessionStore->immediateTransaction()`, chunked at about 500 sessions per transaction so a concurrent writer can interleave.
    2. Persist the build state in a `session_search_meta(version, built_at, dirty)` row written in the same transaction. `needsRebuild()` becomes "no marker, a version mismatch, or a recorded dirty flag", and a failed sync records `dirty = 1` durably. The incremental `syncSearchIndex()` already keeps a built index current across processes.
    3. In `search()`, treat `SQLITE_BUSY`/`SQLITE_LOCKED` as "cannot answer now": return `null` (the LIKE degrade) and do not mark the index dirty.
  - **What the user sees:** the first `/sessions <q>` stops freezing on every launch. Only the first build per database costs anything, and that build runs in one transaction.
- files.create: none
- files.modify: `candy-code/src/Session/SessionSearchIndex.php` (createSchema, needsRebuild, markBuilt, syncMessages catch, search catch), `candy-code/src/Session/EnhancedSessionStore.php` (rebuildSearchIndex())
- tests: modify `candy-code/tests/Session/SessionSearchIndexTest.php`. It pins:
  - a second store instance over a populated database does zero rebuild (row-identity snapshot);
  - the sweep runs inside a transaction (`inTransaction()` observed from a sync seam);
  - a BUSY throw does not set dirty;
  - a version bump forces one rebuild.
- docs/guards: none
- serialized: none (hot file: `EnhancedSessionStore.php`, see Notes)
- deps: none
- phase: normal
- ruling: none
- risks: Old databases have no marker and rebuild once, after which they are trusted. The marker must also be cleared by `clearAll()` and `collectGarbage()` failure paths.

