# C-AGT-5.f REVIEW-2 (round-2, reviewer fresh session)

Tree judged: worktree /home/sites/cc-cmp-C-AGT-5.f, branch cc/C-AGT-5.f. CLAIMED fix-r1 tip 05f8c745e DOES NOT EXIST (`git cat-file -t` fatal; not in any ref, not dangling). Actual tip = 4c1dbe12d, byte-identical state r1 judged; FIX-1.md absent from evidence dir (only BRIEF/REPORT/REVIEW-1). Repair never ran.

## VERDICT: FINDINGS (1 CRITICAL / 1 MAJOR / 1 MINOR — all r1 carry-overs, none cured in committed state)

- CRITICAL candy-code/src/Chat.php:2966 (committed tip): `->name()` fatal on armed TUI permission prompt still shipped. Verified root cause persists: src/ToolCall.php has NO name()/__call. The one-line drop-parens fix exists ONLY as uncommitted working dirt (git status: M src/Chat.php). FIX: commit it on cc/C-AGT-5.f.
- MAJOR candy-code/tests/Automation/OutboundWebhookWiringTest.php: still UNTRACKED (git status ??); committed branch has zero lane tests. File on disk = 433L/10T DoD set intact. FIX: commit it; welder carries +10T re-pin + durations row.
- MINOR (step-local) test :173-174, :301-302: r1 duration cure NOT taken — still `assertIsFloat` + `assertGreaterThanOrEqual(0.0, …)`; completedAt→0.0 mutation reddens NO pin (mutation-reasoned, fixtures carry no spaced stamps/positive derived assert).

## Gates (working tree, with the uncommitted fixes)
- --filter 'OutboundWebhook|Webhook|BackgroundEvents|Automation|PermissionPrompt': Tests 228, Assertions 3864, OK (2 PHPUnit deprecations = foreign AccessorDeprecationTest non-public providers, base fingerprint). OutboundWebhookWiringTest alone: 10T/53A green.
- Mutation-reason: name() restored → armed-approval dial red (Code\ToolCall Error); mount neutered → settle-dial + served-leg red, dark test stays green (correct polarity); 0.0-stamp re-slip → nothing red (confirms MINOR).

## Carry-forward — HOLDS at actual tip
Dialer mounted at Bootstrap::workspace() (:2173-2174); service callers Serve.php:624, Server/Workspace/WorkspaceHostProcess.php:137 (brief's Workspace/ path = src/Server/Workspace/), chat() handoff; ask dispatch AFTER armAsk null guard (Chat.php:2935-2968 read); lang consumed (Bootstrap.php:2209, ServerSettings.php:172-175); E646 clean (no timers in diff); scope = 9 committed files, ALL BRIEF-declared, + the declared test — no unenumerated extras (the 18-vs-16 question resolves 10-on-disk, though the FIX-1 enumeration owed never existed). Authors 2/2 detain@interserver.net %ae and %ce separate, zero Co-Authored.
