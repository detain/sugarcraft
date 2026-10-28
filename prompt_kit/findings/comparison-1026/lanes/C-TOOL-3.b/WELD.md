# WELD — C-TOOL-3.b (2026-10-28)

Lane commit 28609c078 (cc/C-TOOL-3.b, base b985fbe51) → pick **1999f4114** on master.
Auto-merge on Chat.php only (C-SES-9.b cure at :12283-85 vs lane hunks 3742/4274/4394 —
disjoint as REVIEW-1 predicted), zero conflicts, no hand resolution. Patch-id checked
via `git cherry` below.

## Weld cures (REVIEW-1 MINOR + 1 NIT folded — ride the weld commit, the pick stays byte-clean)

1. `candy-code/src/Tui/ToolTailSlots.php` LEAK LAW docblock (MINOR, doc-truth + wrap):
   the reflowed line was 127 chars and kept "and on session resets" while `clearAll()`
   has no production caller (test-only). Rewrapped to the file's ~78-col style and the
   reset claim replaced by the real backstop: "…settles (replaceToolRunningPlaceholder);
   the MAX_SLOTS cap bounds anything else." Grep proof no guard pins the old sentence:
   `git grep "session resets"|LEAK LAW` over candy-code/tests + docs → zero hits at the
   base; DocFigure/LangParity arms re-run green after the edit (112T/7161A).
   PreviewSlots' mirror fiction left untouched (pre-lane fleet shape, out of step).
2. `candy-code/src/Renderer.php` ~:5776 (NIT): removed the double blank line between
   `private const TAIL_LINES = 6;` and the following docblock.
3. NIT SKIPPED as disclosed (spec-true): '… 1 earlier lines' singular caption — REVIEW-1
   accepted-as-disclosed; a plural-aware second key can ride a later TUI lane.

## Durations rows (weld duty, this step)

`scripts/parallel-tests-durations.tsv` (preceding `scripts:` commit):
- `candy-code/tests/Renderer/RunningToolTailTest.php	0.038	10` (junit per-testcase sum, single-file run at merged tree)
- `candy-code/tests/Chat/ToolTailFoldTest.php	0.014	7`
Set identity: paths unique; body duration-desc invariant held (base tail exceptions past
ParallelSafeDocblockTest untouched, rows inserted inside their tie groups, path-asc).
suite-figure.json untouched.

## Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `RunningToolTailTest|ToolTailFoldTest` → **OK 17T/64A** (2 pre-existing Deprecations) — matches REVIEW-1.
- `ToolProgress|MediaProgress|PreviewSlot|Placeholder|Pending` → **OK 238T/3838A** — matches.
- `LangParity|GlobDialect|DocFigureProseDrift` → **OK 112T/7161A** — LangParity green with the new `tui.tool.*` key.
- goldens `MediaFormRenderTest|MediaFormFieldsTest|MenuCascadeTest` → **OK 48T/840A** (broad `MediaForm|MenuCascade` also green at 304T/2909A). Zero golden regeneration.

## Absorption / cleanup

`git cherry master cc/C-TOOL-3.b` → `-` (see master-hash note: cure diff is docblock/
whitespace-only on the pick's two files; verified content diff pick-vs-lane = exactly
the two cure hunks above, nothing else). Worktree `/home/sites/cc-cmp-C-TOOL-3.b`
removed, branch `cc/C-TOOL-3.b` deleted after the proof.
