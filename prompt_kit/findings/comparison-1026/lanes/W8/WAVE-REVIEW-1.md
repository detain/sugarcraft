# W8 wave review (fresh reviewer) — master @ ca41ff405 "weld: wave close W7"

NOTE: brief's tip dbba6a317 does NOT exist on disk (git: not a valid object). Live tip = ca41ff405.
Live §3 W8 row (10 steps): P-MENU.d P-AGENT.d C-CTX-2.a C-EXT-2.b C-SES-4.b C-SES-9.c C-SES-9.d C-WEB-3.a C-WEB-3.b C-WEB-3.c.
Brief's "W8 includes C-MCP-4.c/C-MCP-6.b/C-SEC-5.b/c/C-PRV-5.a" contradicts the live row. Actual placement:
C-MCP-4.c=W27 UNWELDED · C-SEC-5.b=W11 UNWELDED · C-SEC-5.c=W10 welded 2a24daeba · C-PRV-5.a=W9 welded aa0d35002 ·
C-CTX-6.c=W9 welded 16266186d · C-MCP-6.a=W9 welded 6ae2c9e36 · C-MCP-1.b=W3 welded 9c3857a5b · C-MCP-6.b = ID NOT IN PLAN (phantom).
These reviewed below as cross-lane neighbors, not W8 members.

## Axis 1 — claims vs landing: PASS

Live §4 W8 block: 9 of 10 steps carry ✅ master hashes; C-EXT-2.b has NO mark (expected mid-flight; git log shows no
allowed-tools/disallowed-tools landing — outstanding, not a finding).

All 9 ✅ hashes verified against HEAD=ca41ff405:
- ancestry: all 9 found in git log HEAD = ancestors. PASS.
- subjects match §4 entries: 65335c6c4 P-MENU.d menu wiring ↔ five-menu keyboard/click item; ceed81ef0 P-AGENT.d
  web agents parity (colours/interrupt/running line); 6ce6df1f0 C-CTX-2.a drain served-session runtime notices;
  a3bf74a61 C-SES-4.b async OTLP export + carry-over retry tick; 5badf6d2f C-SES-9.c phar bundled presets
  (AgentPresetRegistry); fc5523777 C-SES-9.d release-workflow step env; e651724ca C-WEB-3.a memory history & undo;
  bb8878195 C-WEB-3.b Files panel + read-only viewer (files.read/fs.search); 5a9b2f2c6 C-WEB-3.c workflow status poll.
  All PASS.
- author identity: git show -s '%ae %ce' = detain@interserver.net on BOTH fields, 9/9 (separate greps).
  Co-Authored/Committed-by grep over all 9 full bodies = 0. PASS.

## Axis 2 — cross-lane coherence: 1 stale doc sentence (not W8's), rest PASS

a) docs/MCP.md :629-634 "the client-side arm still has no production caller" — FALSE on current master:
AgentManager.php:1774-1775 maps McpCall/McpListTool through scopedTo() → McpToolShape::scopedTo →
McpClient::forAgentPreset() → setAgentPreset() (C-AGT-3.a, 0c051ee95, C-AGT-3 chain — NOT a W8 step).
No W8 commit touched docs/MCP.md or MCP enforcement code (file-set census: only 65335c6c4 shared files =
README.md/MEDIA.md/lang/en.php; only 5badf6d2f touched src/ = AgentPresetRegistry preset LOADING, not scoping).
W8 did not make it MORE false; carried to closeout for the C-AGT-3 welder. [MINOR, cross-wave carry]

b) SETTINGS.md: C-PRV-5.a (aa0d35002, W9) hunk live — :526 family-ceiling prose + thinkingBudget rows :195/:1083/:1616.
C-CTX-6.c (16266186d, W9) never touched SETTINGS.md (its files: 3400-goal.php, CONTEXT.md, lang/en.php,
Runtime.php, MemoryRecallBlockTest.php) — the brief's union premise is muddled; the REAL shared file with
W8's P-MENU.d is lang/en.php, and the weld union holds: all P-MENU.d rows (keys.menu.close/run/submenu,
tui.menu.more) AND all C-CTX-6.c rows (chat.memory.recalled_notice.one/many, settings.compaction.idleAuto.help,
settings.instructionsExclude.help) present on master. PASS.

c) Lang key census: exact-duplicate scan of en.php top-level literal keys (grep -oE + sort | uniq -d) = ZERO
dupes; trust/effort/menu families distinct (cli.mcp.trust.* :780-790, server.trust.ask.* :1148-1153,
cmd.effort.* :1207, keys.menu.* :1626-1630). PASS.

d) TrustTarget/ProjectTrustCensus vs gateway: census consumed ONLY by Tui/TrustDialog.php + Cli/Bootstrap.php
(TUI/CLI paths). Serve path (Workspace/Gateway.php zero trust refs; SessionHost uses workspace->
projectCommandsTrusted bool). TrustTarget::promptKey() maps to the 5 server.trust.ask.* keys but has ZERO
production callers and ZERO test pins — the intended landing pad is C-SEC-5.b (W11, UNWELDED), so the
gateway bypass is planned pre-weld state, not a W8 regression. Flag for the C-SEC-5.b welder: wire
promptKey+census (E646: wire, don't delete) + add test pins. [NIT, cross-wave note]

e) MCP §Deferred honesty: mcp_list para already carries C-MCP-6.a's tool-lookup (6ae2c9e36 edited MCP.md
:689-693 only); /mcp login prose (:773/:794) describes the shipped TUI detached-child fork + next-launch
attach, NO claim of serve-side login/paste-back (C-MCP-4.c unwelded scope) — honest. :822 client_id is
RFC 7591 registration prose for the shipped flow, not a C-MCP-4.c claim. PASS.
C-MCP-6.b: ID DOES NOT EXIST in the plan (phantom from the brief; §6 has only C-MCP-6.a).

f) C-EXT-2.b outstanding (W8 member, no ✅, unwelded, live lane) — expected mid-flight, not a finding.

## Axis 3 — gate battery: PASS (only allowed reds)

cwd=/home/sites/sugarcraft/candy-code, env -u LINES -u COLUMNS, plain pipe, targeted filters only:
- --filter 'Mcp': OK — 1390T / 8043A / 1 skipped / 2 deprecations, 0F.
- --filter 'Trust|ProjectTrust|TrustDialog': OK — 235T / 18339A, 0F.
- --filter 'Effort|ThinkingCeiling|thinkingBudget': OK — 161T / 515A, 0F.
- --filter 'Grep': OK — 223T / 134782A, 0F.
- five-guard (ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|
  DuplicatedTestHelperDrift): 133T / 2F = EXACTLY the allowed staleness pair (pinned 25,537 vs live 26,287;
  drift owned by the closeout welder's re-pin, not W8). No DuplicatedTestHelperDrift/Glob reds.

## Axis 4 — hygiene: PASS

- Master tip MOVED mid-review: ca41ff405 → f25a697c1 "weld: C-MCP-4.c" (+2 commits, foreign wave). All 9 W8
  ✅ hashes re-verified ancestors at f25a697c1 (endpoints 65335c6c4 & 5a9b2f2c6 checked).
- git worktree list: main + cc/{C-AGT-7.b, C-CTX-1.c, C-CTX-2.c, C-EXT-2.b, C-MCP-4.c, C-TOOL-3.a, C-TOOL-7.b,
  P-MENU.e}. ZERO W8-member residue (no P-MENU.d/C-AGENT.d/C-CTX-2.a/C-SES-4.b/C-SES-9.cd/C-WEB-3.* lanes).
  cc/C-EXT-2.b present = the live mid-flight W8 member (unwelded, expected). cc/C-MCP-4.c lingers one commit
  after its weld — that welder's own pending prune, not W8's.
- plan_comparison.md: dirty but UNSTAGED (git diff --cached empty) — mark-only edits by other lanes; never staged.

## Verdict: FINDINGS (all cross-wave carries; W8 itself clean)
- [MINOR carry] docs/MCP.md :629-634 client-side-arm sentence falsified by C-AGT-3.a (0c051ee95), not W8;
  C-AGT-3 welder/closeout must reword (per-preset scoping now lives BOTH layers: AgentManager:1774 + McpClient).
- [NIT carry] TrustTarget::promptKey() + server.trust.ask.* lang keys unwired, untested — C-SEC-5.b (W11) welder
  must consume them + the ProjectTrustCensus on the gateway path (wire, E646, don't delete).
- [NOTE] C-MCP-6.b in the brief is a phantom ID (plan has only C-MCP-6.a); brief's tip dbba6a317 never existed.
- Outstanding W8 member: C-EXT-2.b (live lane, no ✅) — not a finding.

