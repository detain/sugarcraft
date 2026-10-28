# C-CTX-5.a review r2 — VERDICT: FINDINGS (1 CRITICAL)

## CRITICAL — the fix is a phantom; nothing landed
- `99f036780` does not exist: `git cat-file -t 99f036780` fails in BOTH the worktree and
  /home/sites/sugarcraft (shared object DB); `cc/C-CTX-5.a` reflog has exactly two entries
  (branch-from-master + 2f867a969); branch tip IS the r1 commit (parent ca69cb333); worktree
  status clean. No +30/−1 diff exists anywhere.
- The claimed new method `testAFoldedResultStillCountsAgainstTheNoticeCap` appears in ZERO
  files across /home/sites and /tmp/opencode (recursive grep). `CacheReusingSummaryBackendTest.php`
  still has 7 `public function test` methods (claim: 8).
- The claimed REPORT re-stamp also did not land: the only REPORT.md (main-tree untracked
  prompt_kit/findings/comparison-1026/lanes/C-CTX-5.a/, mtime 17:08, pre-dating r1's 17:36
  review) still carries the pre-fix figure `151T/579A` and no NoticeCap mention; neither
  152T/581A nor 11968/11970 appear in it. The r1 NIT (REPORT must land with the fix round,
  §1.6/§1.12) is therefore ALSO unresolved, and its tracked-committed location is still absent.
- r1's step-local MINOR (notice-cap/overflow-truncation pin) remains UNFIXED.

## Gate re-run at tip (for the record)
- `--filter 'CacheReusingSummaryBackend|CompactionFallbackParity'` (spawn's string, note it
  lacks the Test suffix r1 used): Tests: 15, Assertions: 108, Deprecations: 2. Claimed
  152T/581A not matched (that band belongs to a wider, still-undocumented filter — same
  §1.6 gap as r1). r1's own two-class figure 14T/102A vs my 15T/108A at an IDENTICAL commit
  is a provider-row wobble (CompactionFallbackParityTest has 3 methods/7 rows); disclose at
  weld, not lane-attributable (tree byte-unchanged).
- Family guard 102T/4137A: not re-derived (nothing changed since r1; moot).
- Mutation claim: unverifiable — the line does not exist.

## Task results
(1) FAIL — stat scope moot, commit absent. (2) PARTIAL — ran, figures above. (3) N/A — no
pin to read. (4) FAIL — REPORT not re-stamped. (5) vacuous-clean (no new fixtures).

## Fix required (r3)
Re-dispatch a FRESH fixer in the existing clean worktree /home/sites/cc-cmp-C-CTX-5.a
(tip 2f867a969 is intact — do not re-cut it): land the 8th test method pinning the
notice-cap overflow-truncation leg (+ the two real-EngineBackend vacuity pins if still on
the step's list), commit with author law, and land the re-stamped REPORT at
prompt_kit/findings/comparison-1026/lanes/C-CTX-5.a/REPORT.md as a TRACKED file in the
fix commit (r1 NIT), recording the exact filter strings.
