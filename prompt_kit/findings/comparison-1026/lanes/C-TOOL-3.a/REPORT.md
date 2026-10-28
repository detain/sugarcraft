# C-TOOL-3.a — lane report (recovery pass, final)

VERDICT: DONE. Commits 3d7c1efe3626dcf7af39cf6aa5c2e8bb9c039ae1 +
0758e111d9413954871e4e706ab18db8dc0e45d7 on cc/C-TOOL-3.a (base ebf05989e).
Recovery premise "5 dirty uncommitted files" was stale — predecessor had
committed; disk verified, nothing re-committed. %ae/%ce detain@interserver.net
on both (separate greps), zero Co-Authored trailers, tree clean.

Files (+354/-3, exactly the brief set): src/Tui/ToolTailSlots.php 83 |
src/Tools/BuiltIn/PtyRun.php 37/1 | src/Support/PtyDriver.php 16/2 |
tests/Tui/ToolTailSlotsTest.php 93 | tests/Tools/PtyRunToolProgressTest.php 125.

Gates (cwd worktree candy-code, plain pipe, env -u LINES -u COLUMNS): php -l
5/5 clean; --filter 'ToolTailSlots|PtyRunToolProgress' 11T/48A OK (2
pre-existing config deprecations); five-guard 133T, ONLY staleness pair red
(25,537 pinned vs 26,201 live — pre-existing base drift, weld re-pin duty).

DEVIATIONS: lane ships TWO commits (impl + round-2 docblock cure), not the one
subject the recovery brief named; squash rejected — rewrites SHAs cited by
REVIEW-1.md. r1 MAJOR cured at HEAD: ToolTailSlots docblocks now NOT-YET-WIRED
truth naming C-TOOL-3.b for fold->set, paint-peek, settle-clear (incl.
MAX_SLOTS residual). Zero wiring added, per brief.

DUR (gate): NEW tests needing durations.tsv rows —
candy-code/tests/Tui/ToolTailSlotsTest.php, candy-code/tests/Tools/PtyRunToolProgressTest.php.

Seams: C-TOOL-3.b wires the store; serve/web ToolProgress path unchanged.
Never touched master/plan/run-state/suite-figure/durations; never pushed.
