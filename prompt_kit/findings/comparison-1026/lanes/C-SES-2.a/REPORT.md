# C-SES-2.a — DONE

Commit `94e9b7385369267a8a617adf75e9c11d3e0aa60c` on `cc/C-SES-2.a` (base 7949cc23d), tree clean.
3 files, +485/−35. Author+committer `detain@interserver.net` verified separately; no Co-Authored-By.

## What landed
- **Marker table.** `session_search_meta(version, built_at, dirty)` created inside the same
  latched try (FTS5-less DB gains zero tables). `markBuilt()` writes it; the ctor seeds
  process trust from it (version == INDEX_VERSION && dirty == 0 → no rebuild).
- **Chunked transaction sweep.** `rebuildSearchIndex()` chunks ids at
  `SessionSearchIndex::REBUILD_CHUNK` (500) and runs each chunk inside
  `sessionStore->immediateTransaction()`; clear at first chunk, collectGarbage+markBuilt at
  last. A crash or mid-sweep throw rolls back — no half-swept vouch.
- **Durable dirty + BUSY carve-out.** failed `syncMessages` records `dirty=1` durably;
  `clearAll()` and the `collectGarbage()` failure path clear the marker; `search()` treats
  SQLITE_BUSY/SQLITE_LOCKED (errorInfo[1] 5/6) as cannot-answer-now: null, marker untouched.

## Gates (cwd candy-code, plain pipe)
- `--filter SessionSearchIndex`: **15T/64A OK** (2 pre-existing PHPUnit Deprecations, identical at base).
- `--filter Session`: **1702T/14904A OK** (same 2 deprecations).
- Guards `SwallowingCatch|GlobDialect|DuplicatedTestHelperDrift|TreeWideGuardRoster|DocFigureProseDrift|StderrEmitterCensus`: **243T/32272A OK**.
- `ReadmeSuiteFigureDrift`: expected staleness pair ONLY (artifact 25,537 vs live 25,589 — +52 from merged lanes, +5 mine; closeout re-pins; suite-figure.json untouched by law).
- Mutations, all restored md5-clean: M1 comment seedTrustFromMarker call → exactly tests 9+13 red; M2 neuter isLockContention code list → test 11 red; M3c unwrap sweep closure from immediateTransaction (IIFE) → test 10 red. (First M3 attempt hit an unrelated same-prefix call at :799 — green result discarded, re-run anchored to the sweep line.)

## Deviations
- Brief's "inTransaction() observed from a sync seam" → pin observes `SessionStore::$transactionDepth` (reflection): PDO::inTransaction() is blind to raw BEGIN IMMEDIATE on this PHP build — the hazard SessionStore:896's own comment names. Documented in the test helper.
- BUSY pin uses a stand-alone non-WAL file + 1 s `ATTR_TIMEOUT` (`barePdo`): the store's WAL readers are not blocked by BEGIN EXCLUSIVE, and raw PDO's default 60 s silent busy-sleep would read as a hang. Production path (store connection, 5 s pragma) unchanged.
- `$dirty` initial flipped true→false; "never swept" rides `built=false` — markBuilt persists $dirty, so an initial-true would have leaked dirty=1 markers. clearAll clears the marker row (rather than setting dirty): the next process sees "no vouch", same polarity as a fresh DB.
- saveChat byte-identical copy of SessionSearchFtsTest's twin (house licensé); no new test file — no durations row owed.
