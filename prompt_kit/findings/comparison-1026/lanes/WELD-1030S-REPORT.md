# WELD-1030S — C-TUI-2.e (Diff-review band wired to bare /diff)

Batch: single lane C-TUI-2.e, r2 CLEAN 0C/0M (REVIEW-2.md 3963 B on disk, verified).
Master at start: b77a18838 (== origin/master, in sync). Branch cut at 80fe2275b.

## Step 0 — Preflight ✅
- branch master, tree clean apart from lanes/** + lead's live files (cc-cmp-lead.md M, AGENT_LAW_SNIPPET/LEAD_LAWS untracked — never-stage items, untouched).
- pick range 80fe2275b..ecaeff725 = git rev-list --count 3 ✅ (1e40e6df7 DiffCommand --print + COMMANDS regen; 9643543f8 wiring; ecaeff725 r1 fixes) — exclusive-start law (j) honored, count proven before picking.
- lane diffstat (11 files, +867/−24): candy-code README/COMMANDS/en.php, src/App/App.php, src/Chat.php, Commands/KeyBindingRegistry.php, Host/Commands/DiffCommand.php, Tui/Renderer.php, tests/App/DiffReviewWiringTest.php (NEW, 224L), tests/Chat/UndoRedoDiffCommandsTest.php, tests/Commands/KeyBindingDriftTest.php. No src/Protocol/**, no web → skip gen-protocol-schema + web build.
- evidence census (r2 proof: untracked-in-tree only): main-tree lanes/C-TUI-2.e/{FIX-1.md 1142B, REVIEW-1.md 10152B, REVIEW-2.md 3963B}; worktree adds REPORT.md 1890B (builder record; sibling dirs on master carry REPORT.md — collect all 4).

## Step 1 — Picks ✅ (zero conflicts)
- 1e40e6df7 → a0e40cded (clean, en.php auto-merge)
- 9643543f8 → 77fb4fc1a (clean, README/en.php/Chat.php auto-merges)
- ecaeff725 → 59ea47407 (clean)
- Union proof: range-diff shows ONLY the -x trailer lines differing per pair (patches byte-identical); `git cherry master cc/C-TUI-2.e` = all 3 `-`. No 1030R-style '+' absorption needed.

## Step 2 — Regenerate ✅
- gen-command-docs/gen-tool-docs/gen-settings-doc --write: all "up to date", zero drift (lane committed its COMMANDS region; README rows rode pick). No regen commit needed.
- gen-protocol-schema + web build SKIPPED by design: lane diffstat touches no src/Protocol/** and no candy-code-web file.

## Step 3 — Merged-tree gates ✅ (cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS, plain pipe)
- DiffReviewWiring 9T/87A OK ✅ | UndoRedoDiff 10T/97A OK ✅ | KeyBindingDrift 233T/1917A OK ✅ (2 baseline deprecations house shape)
- DiffReview|DiffGutter|DiffSplit 70T/1977A OK ✅
- ReadmeRosterDrift|CommandDocsDrift|DocFigureProseDrift 108T/4253A OK vs brief 4246A: +7A RE-DERIVED as concurrent-weld drift — exact same filter on the reviewer-base tree (/home/sites/cc-cmp-C-TUI-2.e) reproduces 108T/4246A byte-exact; the delta is the 1030R family's statusLine README/docs rows re-counted by the live-derivation guards (Readme 10T/78A + CommandDocsDrift 6T/11A + DocFigure 92T/4164A = 4253). Tests count stable; all green. Disclosed, foreign reds not chased.
- gen-command-docs --check rc=0 ✅; gen-tool-docs --check rc=0; gen-settings-doc --check rc=0.

## Step 4 — DUR rows + evidence collect ✅
- scripts/parallel-tests-durations.tsv: MINT candy-code/tests/App/DiffReviewWiringTest.php 2.881/9 (median of 3 junit testcase-sums: 2.912/2.687/2.881 — TESTCASE-SUM instrument, not filter wall-clock, per 1030R ThemeFileTest lesson); RE-TIME candy-code/tests/Chat/UndoRedoDiffCommandsTest.php 3.417@10 -> 4.498@10 (junit sums 4.498/4.602/4.438; row already carried 10 cases). Both inserted at descending-sort positions.
- Evidence sizes verified: REVIEW-1.md 10,152 B / REVIEW-2.md 3,963 B / FIX-1.md 1,142 B (main tree) + builder REPORT.md 1,890 B (worktree, md5-copied; sibling dirs carry REPORT.md).
