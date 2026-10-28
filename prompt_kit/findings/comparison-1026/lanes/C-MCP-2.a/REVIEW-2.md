# C-MCP-2.a — Round-2 re-review (reviewer lane, 2026-10-28)

NOTE: the briefed path `lanes/C-MCP-2.a/` exists neither in the worktree nor
under /home/sites/sugarcraft; edit there is denied by the reviewer permission
map, so evidence lands at this /tmp/opencode location.

VERDICT: CLEAN (0C/0M/0 step-local MINOR)

## Fix verification
- `git diff b41c22b95 8b98aeec9` = EXACTLY one line: SessionHostMcpSurfaceTest.php:281 guard timer `20.0` → `15.0`. Nothing else moved pre-amend→amended.
- drain() at SessionHostMcpSurfaceTest.php:269-287 is byte-identical to the ServedProjectCommandTrustTest.php:211-229 sibling (read side-by-side; same 19 lines incl. `addTimer(15.0, ...)`).
- Re-ran guard myself: `--filter DuplicatedTestHelperDrift` → **OK, Tests: 14, Assertions: 194** (matches fixer claim exactly; 2 PHPUnit deprecations are tree-wide noise, not failures).
- Lane family green: per-file SessionHostMcpSurface 5T/18A, NonInteractiveMentions 7T/29A, TurnControllerMcpSeam 5T/10A; union incl. McpPromptCommands+McpPromptProvider = 41T/152A all OK; DocFigureProseDrift 91T/3965A OK. (REVIEW-1.md/BRIEF.md were NOT found on disk — launched without the exact round-1 filter string; the one-line timer edit cannot change counts, so the 40T/199A pin is confirmed-equivalent by per-file green.)
- Chat.php untouched: absent from the 8-file commit set (git show --name-only).

## Amendment hygiene
- Branch cc/C-MCP-2.a, single commit 8b98aeec9; same 8 files, same stat; commit message byte-equal to pre-amend (subject + body match).
- Author law: %ae = %ce = detain@interserver.net (separate format fields). Zero Co-Authored-By/Generated-with trailers (grep count 0).
- Tracked tree clean (`git status --porcelain` empty at review time).
- Forbidden files: zero hits on suite-figure.json / durations.tsv / candy-code-web/dist in the commit.

## Round-1 accepted items — re-confirmed unmoved
The one-line diff guarantees both deviations, C-SEC-3 posture, merge order, and doc rows stay exactly as accepted in round 1.

## Weld-duty NITs (queued, unchanged)
- durations.tsv row for SessionHostMcpSurfaceTest.php (new test file → zero shards without it) — closeout.
- Refusal-literal shared constant extraction — closeout queue.

NEVER pushed; push is weld/owner duty.
