# C-PRV-2.c — WELD (2026-10-29, welder: cc-cmp welder subagent)

Base at weld: master ff8be1e5f (weld: C-SES-6.c). Lane cc/C-PRV-2.c (W17 base), 2 commits, r2 CLEAN 0C/0M step-local (2 NIT closeout).

## Pick map
- e8668b386 → 8848bbcc9 — first-class azure and openrouter provider types (ProviderTypes + CustomProvider owned-header lowercase leg + docs + NEW tests/Providers/AzureOpenRouterTypesTest.php). Zero conflicts.
- b08ca9605 → ac5eb1749 — review fixes (round 2): 1 file +7 (case-variant provider row). Zero conflicts. ✅ mark cites ac5eb1749.

## Absorption proof
- `git cherry master cc/C-PRV-2.c` → `-` for both commits.
- patch-id --stable SAME for both.
- authors 2/2 %ae=%ce=detain@interserver.net, zero Co-Authored-By.

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml) — ALL EXACT vs REVIEW-2
- `--filter 'AzureOpenRouterTypesTest'`: **OK 21T/53A**.
- `--filter 'AzureOpenRouterTypes|CustomProvider|ProviderFactory|ProviderErrorCopy'`: **OK 265T/744A** (builder claim EXACT).
- 2 PHPUnit Deprecations per filter = pre-existing foreign AccessorDeprecationTest shape.

## Bookkeeping
- durations.tsv row ADDED: `tests/Providers/AzureOpenRouterTypesTest.php 0.108 21` (after CompactionJournalTest 0.109). Rows 1547→1548, descending-order violations 4==4.
  CORRECTION on record: REVIEW-2's "No new test file → no durations row owed" was scoped to the r2 FIX commit; the lane build commit e8668b386 creates the file (disk-proved: absent at ff8be1e5f, present at 8848bbcc9; REPORT.md:73 itself lists "durations row" as welder duty). Row minted per §1.5 — an unlisted file runs in zero shards.
- §4 mark ✅ ac5eb1749 at plan_comparison.md:2064.

## Carries to closeout (§7)
- NIT (r2, closeout-only): docs/ENVIRONMENT.md azure 'provider will reject' wording.
- NIT (r2, closeout-only): window-figure discrepancy (494 vs 530T/43972A) in the lane REPORT text.
- Figure carry: +21T live enum from the new file (REVIEW-2's +4T was the provider-window delta line; closeout re-pin is the authority).
