# C-SEC-5.d weld record (W12, TUI launch trust dialog) — 2026-10-28

PICK b64922b50 → master **a93d124b6**; only auto-merge was candy-code/lang/en.php
(append-side, both neighbours kept — master's W12 additions and the lane's `tui.trust.*`
block coexist; tui.trust keys present at :2958+). patch-id c3a6cdd6ec5e identical both
sides → zero conflict-resolution content to re-verify. Author law: %ae and %ce separate
greps =1 each, zero Co-Authored/Co-developed trailers.

REVIEW-ROUND HISTORY: the repo REVIEW-2.md/REVIEW-3.md are the stale phantom-record
rounds (branch/worktree did not exist when those reviewers probed; the lane later
landed on disk as b64922b50). The real-commit round-2 review (VERDICT CLEAN 0C/0M) is
collected into this weld as **REVIEW-4.md** (from /tmp/opencode/cc-cmp-lanes/C-SEC-5.d/
REVIEW-2.md); it blob-verified the cure legs, reproduced the gates exactly, and closed
MINOR-1 (missing REPORT.md) — REPORT.md is in the lanes dir and rides this weld.

GATES (merged tree a93d124b6, cwd candy-code, plain pipe, env -u LINES -u COLUMNS):
- --filter 'LaunchTrustDialog|KeyboardHandler': 93T/12754A green — EXACT roster figure.
- --filter 'TrustKeyDocumentationDrift|LangParity|MouseModalGuard': 41T/3647A green —
  roster said 3638A; the +9A is merged-tree drift from W12 steps landed after the lane's
  base e7e22823a (same 41T, no failures). Not red, noted for the record.
- php tools/gen-command-docs.php --check rc0 before and after the pick — NO regen owed
  by this lane (no command surface).

IN-WELD CURE (rode this commit): src/Tui/TrustDialog.php class docblock "DORMANT BY
DESIGN. Nothing constructs this dialog yet" is false post-pick (App.php seeds it via
LaunchContext). Grep-verified no guard pins the phrase (only self-hit in src/;
TrustDialogTest.php:21 already says "5.d mounts it"); paragraph rewritten truthfully.
SymbolCitationDrift|DuplicatedDocBlockLine 13T/16743A green after the edit; php -l clean.

DURATIONS RIDER 45def84da: row tests/App/LaunchTrustDialogTest.php 0.283/6 (single-file
phpunit at merged tip: 6T/28A), inserted inside the 0.283 tie block after
CommandRowsReachDriversTest, before AgentManagerRetentionTest 0.282. Set-equality
1489→1490, gained exactly this file, lost none; 4 pre-existing desc-violations unchanged.

CARRIES (lead rulings, recorded here): `tui.trust.*` keys land EN-ONLY; the strict
11-locale parity flip is C-TUI-6.h's job (§6.3-h). Optional docblock cure — taken.

ABSORPTION: git cherry master cc/C-SEC-5.d → `-` (patch-id identity, no '+' rows);
worktree /home/sites/cc-cmp-C-SEC-5.d removed and branch cc/C-SEC-5.d deleted after
this commit.
