# C-TUI-2.d — WELD record (batch-5 step 3, 2026-10-28)

## Picks (cherry-pick -x, zero conflicts)
- c49418587 → e05f83340 (diff-review core: CheckpointDiff files()/between(), DiffReview model + source; lang/en.php append merged clean; 2 NEW test files)
- 6697ce667 → a03990a0f (r1 review fixes; test-count neutral, extends 2 existing methods)

## Gates on merged tree (plain pipe, cwd=candy-code) — EXACT vs REVIEW-2.md
- `--filter 'DiffReview'` → Tests: 19, Assertions: 195 — EXACT (file totals 14/7 per REVIEW-2:11)
- `--filter 'CheckpointDiff'` → Tests: 11, Assertions: 91 — EXACT
- combo `CheckpointDiff|DiffReview|LangParity|GlobDialect|DocFigure|SymbolCitation` → Tests: 152 EXACT, Assertions 25,189 (+215 tree-scan wobble on the new files), OK
- census trio `StderrEmitterCensus|BootstrapLaunchFormatConstants|TreeWideGuardRoster` → Tests: 130 EXACT, Assertions 25,604 (+198 wobble), OK

## Durations (welder minted from merged junit; scripts commit c1e43a308)
- candy-code/tests/Tui/DiffReview/DiffReviewTest.php 2.51 / 14
- candy-code/tests/Workspace/CheckpointDiffFilesTest.php 1.13 / 7
- tsv 1559 → 1561 (inserted at duration-neighbor positions; pure +2 diff)

## Figure carry (note only — §7 closeout re-pins)
+21T (14 + 7 new-file tests; name-filter overlaps counted once).

## Rider
candy-code/README.md one-line settings-layered numeral 134→135 — the generated-truth
residue of the step-2 (C-TUI-2.c) gen-settings-doc regen whose docs/SETTINGS.md half
rode pick 18822cc20; `gen-settings-doc --check` rc0 with it. Rides this weld commit.

## Absorption proof (post-weld)
git cherry master cc/C-TUI-2.d → both '-' (patch-id SAME for e05f83340/a03990a0f).
Worktree ../cc-cmp-C-TUI-2.d + branch cc/C-TUI-2.d pruned after proof.
