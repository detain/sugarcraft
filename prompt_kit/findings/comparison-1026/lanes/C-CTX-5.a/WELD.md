# C-CTX-5.a — weld record (batch-7, lane 3)

Welded 2026-10-28 by cc-cmp welder on master (NEVER pushed). Review verdict per lead
ledger (run-state inflight note, tick-224 lineage): **r4 FULL-RANGE CLEAN 0C/0M/0m**.
No REVIEW-3/REVIEW-4 files were persisted by those sessions (r2 phantom-commit audit
required tracked evidence — the fix rounds themselves carried it in REPORT); REVIEW-1
and REVIEW-2 are staged beside this record, REVIEW-2 copied from
/tmp/opencode/cc-cmp-lanes/C-CTX-5.a/ at weld.

## Picks (zero conflicts)

| lane commit | master pick |
|---|---|
| 2f867a969 | aa8bdbeea |
| fe47a3921 | 53b8e0fbc |
| b3f5cea29 | 972e0960b |

Untracked blocker handled: the lane's on-disk `REPORT.md` (46 lines) was a strict
prefix of the version committed by b3f5cea29 (120 lines; `diff` = pure append); backed
up to /tmp/opencode/cc-cmp-batch7/scratch/REPORT-disk-prefix.md, removed, pick created
the full tracked file, content re-verified identical to the b3f5cea29 blob.

## Gates (cwd=candy-code, env -u LINES -u COLUMNS, plain pipe, merged tree)

- `--filter 'CacheReusingSummaryBackend|CompactionFallbackParity'` — **Tests: 19,
  Assertions: 174**, PHPUnit Deprecations: 2, OK (EXACT vs r4/lead figures; the 2
  deprecations are ambient, present at base per r1).
- `--filter 'Runtime'` — Tests: 414, Assertions: 10033, OK (band-coverage superset;
  the r4 note's "201T/1099A" exact filter string was not persisted anywhere — r4
  session record lost — so the wider Runtime band green stands in, disclosed here).
- Lane doc-guard band `--filter 'DocFigureProse|ReadmeRoster|EnvRoster|TrustKey|
  ConfigWriteProducer|KeyBinding|GlobDialect|SwallowingCatchCensus|TransientFailure'`
  — **Tests: 547, Assertions: 47974**, OK (lane claimed 547T/47713A at its tip; +261A
  tree-scan recount from batch-7's merged new files).

## Standing red encountered (NOT attributed, NOT cured)

`--filter 'Compaction|Runtime'` surfaces exactly 1 failure:
`AutomaticCompactionModelSummaryTest::testClearingTheTranscriptUnarmsTheBreaker` —
on the lead's documented standing-red roster (run-state:351 "deterministic
base-inherited (P-TABS.f r1 proof; Chat-region ≤W10 band owner)"; lanes/W14+W15 WELD
rosters). Re-proven base-inherited at this weld: with ALL candy-code src+tests+lang
checked out at the batch-6 base e6188662a the isolated test stays red, so no batch-7
lane introduced it. Tree restored to the picked tip immediately (reset verified).

## Carry / durations

- Suite carry: +18T over the two new files (11 CacheReusingSummaryBackendTest +
  7 CompactionFallbackParityTest by exact-file filters; lead's "+14T" was the
  r1-era count before the r2/r3 pins grew the files).
- durations.tsv rows: `candy-code/tests/Host/CompactionFallbackParityTest.php 5.562 7`,
  `candy-code/tests/Backend/CacheReusingSummaryBackendTest.php 5.148 11` (measured
  isolated per-file runs).

## §4 / absorption / prune

- plan_comparison.md §4 marked `✅ b3f5cea29` (heading line 2456), per lead note.
- `git cherry` after weld: all three '-'.
- Worktree /home/sites/cc-cmp-C-CTX-5.a + branch cc/C-CTX-5.a pruned post-proof.
