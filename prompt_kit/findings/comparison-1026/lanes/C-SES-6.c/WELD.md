# C-SES-6.c — WELD (2026-10-29, welder: cc-cmp welder subagent)

Base at weld: master 240ba85c1 (weld-cure: roster NOW const pair; master tip was da284586a).
Lane cc/C-SES-6.c (base cf0cfd0be), 2 commits, r2 CLEAN 0C/0M/0m.

## Pick map
- 38ae2f185 → c7ee812b9 — install.sh bootstrapper + Homebrew formula generator (6 files + 2 NEW test files + gen-homebrew-formula.php + template + README Install section). Zero conflicts.
- bcb1769f6 → 24d746501 — review fixes r1 (TreeWideGuardRosterTest +8, install.sh `--` guard 1 line). Zero conflicts. ✅ mark cites 24d746501.

## Absorption proof
- `git cherry master cc/C-SES-6.c` → `-` for both commits (absorbed).
- patch-id --stable SAME for both; content check clean.
- authors 2/2 %ae=%ce=detain@interserver.net, zero Co-Authored-By.

## Merged-tree gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe, -c phpunit.xml)
- `--filter TreeWideGuardRosterTest`: **OK 17T/3569A** (0F/0E). Lane-tip figure was 17T/3559A; +10A is merged-tree re-count (batch-1 additions in the walked directories) — re-derived at the merged tree per brief.
- `--filter 'InstallScript|HomebrewFormula|SelfUpdate'`: **OK 62T/901A** (0F). The lane's 197T/55444A was the pre-fix 8-class tip figure; per brief the actuals above are the merged-tree truth.
- Both new files standalone: HomebrewFormulaTest OK 7T; InstallScriptTest OK 13T (junit count; the REPORT's "12 tests" predates a provider row — junit is the durations authority).
- 2 PHPUnit Deprecations on each filter = pre-existing foreign AccessorDeprecationTest provider shape (disclosed in every batch-1 weld).

## Bookkeeping
- durations.tsv rows ADDED (welder-only file): `tests/Cli/InstallScriptTest.php 4.959 13` (after ProcessUniqueTempNameTest 5.035), `tests/Cli/HomebrewFormulaTest.php 1.424 7` (after WorkflowEngineCancellationTest 1.469). Rows 1545→1547, descending-order violations 4==4 unchanged.
- §4 mark ✅ 24d746501 at plan_comparison.md:2097.

## Carries to closeout (§7)
- NIT-2 (r1/r2 ruling, out-of-scope report-only): candy-code/README.md:154 "no environment variables" wording vs install.sh — leave to closeout docs pass; no guard owns it.
- Figure carry: the 2 NEW files add +20T to the live suite enum (junit 13+7; the REPORT's 19T/55444A was its own 8-class filter figure at the pre-fix tip, not a suite delta). Closeout re-pin owns the exact pair.
