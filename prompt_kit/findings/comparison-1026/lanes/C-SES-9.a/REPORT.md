# C-SES-9.a — ledger hygiene trio (SES-1-P5 · SES-1-P6 · OBS-3-P2)

LANDED: commit 81eabef46 on cc/C-SES-9.a (base c2477fe71), tree clean, 4 files, +359/−8.

## What shipped

1. **SES-1-P5 tombstone ledger** — `session_tombstones(id PK, deleted_at)` created by BOTH
   SessionStore::initSchema() (delete side) and EnhancedSessionStore::initEnhancedSchema()
   (read side). deleteSessionTrees() records the $deleted ids riding the SAME transaction
   (C-SES-3.a share-revoke region byte-identical), reaping entries older than
   TOMBSTONE_TTL_SECONDS (30 days) on insert — the table bounds itself, never a sweep.
   appendUsageRecord(): lazy-mint consults sessionTombstoned() BEFORE recreating — a deleted
   id answers false with zero writes (no resurrection as candy-code/unknown); a never-minted
   id still gets its row (polarity pinned both ways). Read tolerates table absence → "nothing
   ever deleted".
2. **SES-1-P6 ts index** — `CREATE INDEX IF NOT EXISTS idx_usage_records_ts ON usage_records(ts)`
   + docblock :755 truth-flip (old "nothing scans by anything else" denied the OBS-3 window;
   both keys now named) + in-step flip of the UsageRecordsTest schema-pin message.
3. **OBS-3-P2 one-snapshot rollups** — EnhancedSessionStore::usageRollups(?sessionId, ?days):
   array{days, models} wraps both folds in one BEGIN DEFERRED (rides a caller's open frame —
   the inTransaction-blindness law; no BEGIN IMMEDIATE: reads must not queue behind writers;
   rolls back its own frame on throw). UsageHostCommand::rollupFacts(EnhancedSessionStore,
   ?string, ?int, bool scopeAll): array is the new public shared read (C-SES-8.a/.e/.c reuse);
   facts() delegates, both refusal arms byte-identical, fact-map shape unchanged.

## Tests (all in existing tests/Session/UsageRecordsTest.php — no new files → no durations rows)

- delete-then-append → false, 0 usage rows, no sessions resurrection, tombstone present
- never-minted id → true, candy-code/unknown lazy row, 1 usage row
- reap: aged −31 d row gone after next delete; aged id appends recreate again
- EXPLAIN QUERY PLAN of the windowed fold picks idx_usage_records_ts (800 rows, 7-day window, ANALYZE) — planner verified live
- snapshot: BEGIN DEFERRED + interleaved committed writer on a 2nd PDO + identical folds (behavior), plus source-shape pin that usageRollups itself frames both reads in the deferred transaction

## Gates

- targeted `--filter 'UsageRecords|SessionStore|EnhancedSession|Usage|SessionDelete'`: OK — Tests: 490, Assertions: 2267 (2 PHPUnit deprecations PRE-EXISTING at base, reproduced via stash)
- five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'`: Tests: 133, Assertions: 15647, Failures: 2 — exactly the ReadmeSuiteFigureDrift staleness pair (README headline + artifact vs live enumeration; closeout re-pin duty, lane forbidden from suite-figure.json)
- php -l clean on all 4 files; author/committer detain@interserver.net verified (%ae/%ce separate), zero trailers; never pushed; diff file-set == the brief's 4 files.

## Round 2 (fixer — REVIEW-1 [MINOR step-local])

- Finding: sessionTombstoned()'s table-absence PDOException catch (EnhancedSessionStore.php:2549-2561) had no regression pin — the "behaves exactly as before on a legacy db" claim was doc-only.
- Fix: ONE new test in candy-code/tests/Session/UsageRecordsTest.php — testTheTombstoneReadToleratesItsLedgerBeingGone: two legs, one live store. Row-absence leg (mark s1 via deleteSession, DELETE its tombstone row on raw PDO → append s1 → true + lazy-minted row); table-absence leg (mark s2, DROP TABLE session_tombstones on raw PDO → append 'unknown' AND 's2' → both true, candy-code/unknown row minted, 3 usage rows).
- Pre-edit vacuity proof (static): every existing test runs post-constructor, and both EnhancedSessionStore's schema step (:777) and SessionStore::initSchema run CREATE TABLE IF NOT EXISTS session_tombstones — the table is present for all 20 existing appends, so the catch is unreachable and deleting it would have kept the class green. Discrimination: removing the catch makes the drop-table appends hit the outer \Throwable fail-soft (false + dropped row) → both assertTrue legs red; inverting it (catch → true) fails all three.
- NOT touched: the [REPORT-ONLY] saveTranscript/appendSessionEvent seam (:1988/:2377) — out of this lane's scope per review, carried for the wave loop.
- Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe): `--filter 'UsageRecords'` → 21T/102A OK (20→21; the 2 PHPUnit deprecations are runtime-level, reproduced identically at base class-run AND under a lone pre-existing test — none introduced). Five-guard → 133T/15647A, Failures: 2 = ONLY the staleness pair (25,537 pinned vs 26,028 live = 26,027 + this round's +1; closeout re-pin duty).
- Commit 58701feb7 "C-SES-9.a: review fixes (round 2)" on cc/C-SES-9.a (base round-1 tip 81eabef46); single file staged; %ae/%ce grep -c detain = 1 each, zero Co-Authored trailers; never pushed/pulled; porcelain clean.
