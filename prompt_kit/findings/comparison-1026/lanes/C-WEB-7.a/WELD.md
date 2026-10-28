# C-WEB-7.a — WELD (2026-10-28, master 24d3da763 base)

**Pick:** `b810efe67` → `0e2a4e08e` on master. Clean apply, zero conflicts —
the diff-name overlap vs `581ac6ede..master` is empty for all 5 lane files.
Author/committer `detain@interserver.net` (`%ae`/`%ce` separate greps, 1 each),
zero trailers.

**Regeneration:** none (weld-verified from the pick diff: zero Protocol/**,
schema.json, SERVER.md, generated.ts, dist/ or lang/ paths — grep count 0).

**Durations:** no row — zero new test files (ShellTailRingTest.php and
BashToolProgressTest.php modified in place). `suite-figure.json` untouched.

**Merged-tree gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS):**
- `--filter 'ShellTailRingTest|BashToolProgressTest'` → `Tests: 15, Assertions: 56` OK (brief figure EXACT).
- C-TOOL-3.b ring-family spot: `--filter 'RunningToolTailTest|ToolTailFoldTest'` → `Tests: 17, Assertions: 64` OK (still green on the merged tree — same-ring consumers unaffected by the trailing-edge flush).
- 2 PHPUnit deprecations in each run = base-era AccessorDeprecationTest provider shape, disclosed non-lane by REVIEW-1.

**NITs:** REVIEW-1 CLEAN 0C/0M; both cosmetic NITs SKIPPED per review
(ShellTailRingTest class header "two contracts (WEB-4)" vs the new third;
SessionFileStore docblock line-wrap raggedness) — no action taken, no
open deferrals.

**Absorption proof:** `git cherry master cc/C-WEB-7.a` → `-` (clean single-commit
pick). Worktree `../cc-cmp-C-WEB-7.a` removed, branch `cc/C-WEB-7.a` deleted.
