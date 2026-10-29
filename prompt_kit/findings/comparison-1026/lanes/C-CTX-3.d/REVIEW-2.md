# C-CTX-3.d REVIEW-2 (delta e08dddd3e..f0f2a0922)

VERDICT: CLEAN (delta scope = tests-only + REPORT; one NIT below, no fix required).

- Filters re-derived exact: MemoryRecallSourcesTest **14T/81A** green (Deprecations 2 = baseline); battery filter **252T/4791A/1F** — sole red DreamPassTest:288, reproduced identically on main-tree master e306bf4fc (foreign, base-standing, matches REPORT carry-2).
- Scope: `git diff` = MemoryRecallSourcesTest.php +122, lane REPORT.md +36. Zero src touches; durations.tsv/suite-figure.json untouched (correct — welder duty).
- Literal-bound claim CONFIRMED: pins use literals 8 / 603 / 10 plus literal survivor list e2..e11; no re-read of MAX_SESSION_HITS / MAX_SESSION_SNIPPET_BYTES / VIEW_MAX_ENTRIES in any cap leg. The one re-read (`assertLessThan(VIEW_MAX_BYTES, $bytes)`) is the budget-cannot-bind precondition, not the pinned bound — vacuity-safe.
- Non-vacuity + mutation-reason per leg (my lane map denies cp/edit so I reasoned statically on the final tree, not re-ran):
  (a) data leg asserts 9 matches via searchScoped(...,9) BEFORE the 8-row pin — thin fixture would redden it; cap 8→999 ⇒ 9 rows ⇒ exactly 1 red (no other test seeds >3 sessions; 8→7 also reddens — two-sided).
  (b) raw snippet is `[zebrafish] `+700q = 712B > 600 ⇒ clip; 600→100000 ⇒ no `...`, strlen 712 ⇒ 1 red (ends-with fires first, same method); other tests' snippets are short — unaffected. Eviction of the 700-q run is separately pinned negative.
  (c) 11×~24B vs 6000B budget ⇒ only the count can bind; 10→999 ⇒ count 11 ⇒ exactly 1 red (the 15×3000-char budget test stays byte-bound, journal-recall test seeds 2). e11-in/e1-out pins the newest-tail eviction the code performs.
- NIT-2 measured true: exact sentence in src + this test (assertSame ×3) + MemoryToolTest substring pin (2 asserting files); 'no memory store' carried in 4 test files' prose (MemoryPromptWiringTest:47, BaseSystemPromptTest:85 comment-only). Wording fixed and honest, disagreement framing accepted.
- NIT-1 (MemoryTool:112-118 docblock) and NIT-3 (TaskTool:1461 no root) verified byte-untouched — welder carries stand.
- 3 inherited reds base-proven, not lane: OneSidedHomeSandbox (CacheReusingSummaryBackendTest, InstructionWalkPastRootTest) + SwallowingCatch (MainWorktreeTest:137/:178) reproduce on master tree; GlobDialect's 556×552-vs-305,805 matches fixer's disclosure verbatim and the delta adds ZERO glob-shaped literals (all new string literals carry spaces/no wildcards) → derived corpus identical at base.

NIT (step-local, no action required): test comment at leg (b) says "the body that was indexed is 712 bytes of q's" — the indexed content is 710B (10 + 700 q's); 712 is the snippet after FTS `[ ]` markers, and only 700 bytes are q's. Behavior claim itself is true.

## Stage-for-weld carries (updated)
1. durations.tsv row MemoryRecallSourcesTest.php = 14T; suite-figure/README re-pin carry now 14T/81A (+3T/+21A over the e08dddd3-era 11T/60A).
2. §4 ledger cites tip f0f2a0922 (not e08dddd3e).
3. Welder cures (per REVIEW-1, still owed): NIT-1 withShellOwner docblock wording; NIT-3 TaskTool:1461 pass $memoryRoot one-liner.
4. Foreign/standing reds to not-attribution: DreamPass:288, GlobDialect, OneSidedHomeSandbox, SwallowingCatch.
