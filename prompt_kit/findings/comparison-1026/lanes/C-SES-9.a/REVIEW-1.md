# C-SES-9.a — REVIEW-1 (round 1, reviewer static+targeted)
VERDICT: FINDINGS — 0 CRITICAL / 0 MAJOR / 1 MINOR (step-local) / 1 report-only

## Verified clean
- Scope exactly the 4 briefed files (+359/−8); porcelain clean; no suite-figure/durations/dist touches.
- SessionStore diff is pure-insert: the C-SES-3.a share-revoke region byte-identical vs base (hunk context only).
- Tombstone insert rides the SAME immediateTransaction as the deletes (:1023/:1409 callers frame it); only `$deleted` marked; detached children untouched.
- Reap: prepared single-bound-param DELETE, cutoff `revokedAt−30d`, fresh entries cannot satisfy `<`; INSERT OR REPLACE placeholders built from int-chunk count, ids bound — no injection.
- Lazy-mint polarity pinned both ways (deleted id → false, zero writes, no resurrection; never-minted → true, candy-code/unknown row) — first pin fails pre-fix (regression-faithful).
- EXPLAIN pin real: 800 rows + ANALYZE + exact window clause; red without `idx_usage_records_ts` AND red on planner refusal (assert-on-plan, no skip guard) — not vacuous; verified green live.
- One-snapshot: behavioral — BEGIN DEFERRED on the store's WAL reader, interleaved committed writer on a 2nd PDO, folds identical; plus source-shape pin (BEGIN<days<models<COMMIT, zero BEGIN IMMEDIATE). `$this->pdo` IS the sessionStore connection (`:54`) so the frame genuinely covers both folds; WAL proven at SessionStore:145.
- `rollupFacts(EnhancedSessionStore, ?string, ?int, bool): array` public static per brief; fact-map keys byte-identical; sole old consumer `facts()` delegates; no other src callers of the fold pair.
- Gates: `--filter 'UsageRecords|SessionStore|EnhancedSession|Usage'` → **OK 483T/2245A** (builder's 490T/2267A adds `|SessionDelete`; same class, 2 pre-existing deprecations reproduced at base); five-guard → **only** ReadmeSuiteFigureDrift staleness pair (25537 vs live 26,027 = +6). UsageRecordsTest 20T/94A (14→20). No new test files → no durations rows, correct.
- Author: %ae=%ce=detain@interserver.net, zero Co-Authored trailers.

## Findings
- **[MINOR step-local]** `src/Session/EnhancedSessionStore.php:2545-2561` — `sessionTombstoned()`'s table-absence tolerance (PDOException catch → lazy-mint proceeds; the brief's risk law "tolerant of absence") has **no regression pin**: every test runs post-constructor, which always creates the table, so the catch is unpinned defense and the "behaves exactly as before on a legacy db" claim is doc-only. Fix: one test — open store, `DROP TABLE session_tombstones` on raw PDO, `appendUsageRecord('unknown')` → assertTrue + minted row (optional second leg: DELETE the row of a marked id → append mints again).
- **[REPORT-ONLY out-of-scope]** `EnhancedSessionStore.php:1988` (saveTranscript) and `:2377` (appendSessionEvent) still lazily re-create DELETED sessions as `candy-code/unknown` — same resurrection class, unguarded because the brief scoped SES-1-P5 to appendUsageRecord only. Wave loop/closeout should decide whether a follow-up step folds the tombstone check into those paths; not this lane's defect.

[lead note: reviewer's return truncated the tail (no closing fence) — content complete; MINOR fix-now routed to r2 fixer; report-only tombstone-coverage seam added to carries.]
