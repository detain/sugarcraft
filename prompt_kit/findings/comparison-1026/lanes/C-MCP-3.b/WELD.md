# WELD — C-MCP-3.b (2026-10-28, welder ses in-tick)

## Ceremony

- Pick: lane tip `58966feb9901450efa5e1b01f4bea9e98f7feb1c` -> master `5cac857fc31c7534b1da515a50dcd7683dce91b3`
  (CLEAN cherry-pick onto fb907c832; auto-merge docs/MCP.md + lang/en.php soft-append + DocFigureProseDriftTest;
  patch-id `49b5b1342a57be673bd723bb7edb57d2d7c2fe61` IDENTICAL lane/pick; %ae/%ce SEPARATE greps 1/1 detain;
  zero trailers; 10 files +1340/-12).
- Scripts commit `37a4ef615`: durations.tsv exactly +2 rows —
  `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php 0.004 4` and
  `candy-code/tests/Tools/McpToolProgressTest.php 0.007 15` (col3 = testcase COUNT per 15ef514de law;
  per-file junit sums from targeted plain-pipe runs; desc-invariant tie-band alphabetical insert;
  tsv 1537 -> 1539, asc-violations 4==4, set-equality +2 exact).
- This weld commit carries: §4 full-hash ✅ mark, the r2 indent cure, lane evidence (BRIEF/REPORT/FIX-1/
  REVIEW-1/REVIEW-2 + this file), run-state (inflight C-MCP-3.b removed, C-AGT-5.c preserved verbatim,
  welded_count 159->160, welded list +1, len==count asserted).

## Salvage twin-builder history (recorded)

- `d7d008566` = PHANTOM tip hash in builder reports (cat-file fatal; run-state anomalies_ledger).
- Real sequence: salvage landed `828b709ea` (9 files +1252/-11) -> r1 REVIEW-1 FINDINGS 0C/1M(MAJOR-1 docs
  overclaim)/5NIT -> FIX-1 cured docs-only + took the DocFigure arm -> final tip `58966feb9` (10 files).
- Twin-fixer absorption VERIFIED HONEST (REVIEW-2 §6): first amend `28545a1a7` and final `58966feb9` share
  the IDENTICAL tree `d92c9279b584fdc8cb0de753e429bb6b5b673b4d`; committer clocks differ 17s; the concurrent
  same-mission session's arm insertion was deduplicated — final tree carries EXACTLY ONE new arm method
  (`testTheServerRequestsSectionNamesExactlyTheDeskCapableTransports`). DO NOT re-add an arm for this step.
- run-state inflight note still recorded the pre-cure tip 828b709ea as "review round 1"; disk REVIEW-2.md
  (CLEAN on 58966feb9) is the authoritative final verdict — weld proceeded on the reviewed tip.

## Merged-tree gates (plain pipe, env -u LINES -u COLUMNS, cwd candy-code; PtyRunToolTest never in filter)

- New files targeted: NonInteractiveMcpToolsHeadlessTest 4T/14A OK; McpToolProgressTest 15T/67A OK (= 19T/81A).
- Broad `--filter 'MCP|Mcp'`: 1476T/8531A OK, 0F (1 skip pre-existing, 2 PHPUnit deprecations base-present).
  Standing pr-MenuBarLabelDerivation / DreamPass legs did not appear in this filter's red set — zero reds at all.
- New DocFigure arm standalone: 1T/16A OK. Whole class post-cure: 92T/4047A OK.
- Guard set (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|
  DuplicatedTestHelperDrift): 134T/16465A, red-set = staleness pair ONLY (pinned 25,537 vs live 27,209) —
  foreign-era carry, report-only; this weld never touched suite-figure.json.

## Regen rider — NONE

Pick file-set has ZERO `src/Protocol/**` paths and ZERO `candy-code-web` paths (10-file list verified).
`php tools/gen-docs.php --check` rc=0 re-run by the WELDER at the merged tree (fixer claimed rc=0 in FIX-1;
the r2 reviewer disclosed it was map-denied from running generators — welder run closes that gap).

## Folded cure (307ffb44f precedent)

r2 MINOR-1: `candy-code/tests/Config/DocFigureProseDriftTest.php:7399` stray whitespace-only re-indent of the
last method's closing brace (4->8 spaces, twin-absorption artifact) restored to `    }`. Whitespace-only 1/1,
php -l clean, no whitespace-pinning guard exists (no cs-fixer CI job), whole DocFigure class green post-fold.

## Carries

- suite-figure +20T live (19T new files + 1T arm) rides closeout re-pin (staleness pair is the authority).
- +19T/+81A brief carry figure measured; docs/MCP.md §Server requests section is the r1-cured stdio-scoped wording.

## Absorption

`git cherry master cc/C-MCP-3.b` -> `-` (patch-id identical, no conflict-resolution delta).
Worktree /home/sites/cc-cmp-C-MCP-3.b + branch cc/C-MCP-3.b pruned same tick. NEVER pushed.
