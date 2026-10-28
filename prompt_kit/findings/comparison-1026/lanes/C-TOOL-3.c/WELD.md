# C-TOOL-3.c weld record (W13, session-owned background shells) — 2026-10-28

PICK 6f69565d3 → master **1f1db4f97**, zero conflicts (base 23fa8ab8e == current-era
tip; no shared-file overlap with the two earlier picks of this run). patch-id
09188264cfdb identical both sides → no conflict-resolution content. %ae/%ce separate
greps =1 each, zero trailers.

GATES (merged tree, cwd candy-code, plain pipe, env -u LINES -u COLUMNS; explicit
class lists per REVIEW-2 — a naive 'Bash|…' union catches PtyRun* and is banned):
- --filter 'ShellOwnership': 10T/60A green — EXACT roster.
- --filter 'BackgroundShellRegistryTest|ShellStopTest|ShellOutputTest|ShellOwnershipTest':
  59T/350A green — EXACT ("~59T/350A" roster figure reproduced precisely).
- --filter 'BashBackgroundTest|BashToolProgressTest|ShellTailRingTest': 20T/77A green
  — EXACT ("+20T/77A" leg).
No regen owed (no command/tool/protocol surface).

DURATIONS RIDER 20b39cfa7: row tests/Tools/ShellOwnershipTest.php 1.362/10 (junit
per-testcase sum at merged tip), between 1.364 and 1.357 neighbours; set-equality
1490→1491, gained exactly this file; 4 pre-existing desc-violations unchanged.

ORDERING NOTE (lead ruling, recorded): C-TOOL-3.d and C-TOOL-3.e and P-SESSION-TABS
consume `ownedBy()` — they land AFTER this pick and may rely on it. The trait
AcceptsShellOwner + registry owner-tagging is now master API.

ABSORPTION: git cherry master cc/C-TOOL-3.c → `-` (no '+'); worktree
/home/sites/cc-cmp-C-TOOL-3.c removed and branch cc/C-TOOL-3.c deleted after this commit.
