# C-TUI-1.a — WELD (2026-10-28)

Pick: cf5f0f925 → master d88f83e87. Zero conflicts (brief's only context risk was `candy-code/lang/en.php` — auto-merged clean); stable patch-id c57fd999 identical both sides; `git cherry master cc/C-TUI-1.a` = `-` (single commit, absorbed). Worktree + branch pruned after this weld commit.

## What landed

Vim text objects, `c` and counts in `ComposerVim`: `d`/`y`/`c`-then-`i`/`a` arms through sugar-forms' own `TextObject` geometry landing in the existing `applySpan()`; decimal count threaded through doubled operator, `dj`/`dk`, `x`, pastes, delegated motions; `c`/`C`/`c`-then-Insert ride the `openLine()` precedent, bare `cw` behaves as `ce`; the armed grammar cancels and re-routes on unreadable keys (Tab and the Escape relay keep their owners); bare `0` stays line-start motion (`claimsKey` instance predicate on the armed count). sugar-forms consumed unchanged. Declared regions in `HandlesKeys.php` honored (TUI-3A vim-arms file, disjoint hunks).

## Review record

REVIEW-1.md CLEAN (0C/0M, findings none; 9T + 18T new files + claim roster + 3 wiring pins). Help text moved in step in `lang/en.php` (`settings.vimMode.` row — the step's only docs/guard surface; Vim keys are a mode, not KeyBindingRegistry rows, so README Keys did not move — confirmed).

## Durations (weld duty)

Both new test files' rows landed in preceding scripts commit 69dfca1b2 (junit per-testcase sums at merged tip: ComposerVimTextObjectTest 0.055/9T, ComposerVimCountTest 0.056/18T; tie groups path-asc; set-equality 1471+2=1473 held).

## Gates on merged tree (cwd candy-code, plain pipe, env -u LINES -u COLUMNS)

- `--filter 'ComposerVim|LangParity'` → OK 82T/3293A. Brief figure 82T/3292A; the +1A is LangParity 7T/2839 vs 2838 in the lane tree — C-TOOL-3.b (pick 1999f4114, landed after this lane's b38baf0f8 cut) adds exactly one `lang/en.php` row; tests 82 exact, assert count follows lang rows (benign merged-tree figure).
- `--filter 'DuplicatedTestHelperDrift|GlobDialectDifferential'` → OK 27T/560A (both in-step guards green; no glob-shaped literals).

## Regeneration

None required. Verified: gen-command-docs/gen-tool-docs/gen-settings-doc `--write` zero diffs; gen-protocol-schema `--check` up to date; suite-figure.json untouched.
