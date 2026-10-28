# C-MCP-2.b FIX-1 (round 2) — 2026-10-28

Tip: ec48dc189 → 1b4b8b73f94ee6a5b7789a8d428d1a94ce162ba0 (my round-2 amend, both pins + laws verified) → **final 5b3c8cfe80cc9db6a7cd405056186c9c19e3f120** — a concurrent same-lane round-2 agent amended my tip, preserving both my pins verbatim and adding their own cures (MAJOR-1 as contract truth: command.list declares sessionId required via MethodSchemas $sessionOnly; NIT-1: McpMethodsTest arms McpLaunchEnabledTrait). That agent evidently holds the real REVIEW-1.md. Laws re-verified on 5b3c8cfe8: single parent 4b771634d (NOT rebased), %ae/%ce separate greps =1 detain, zero Co-Authored, 12 files, suite-figure/durations/dist untouched, generated trio byte-identical to base (stale by-design per restore-3 law; their message confirms "regen at weld carries it"). All gate figures below were run against trees containing my pins (file1 md5 ef7f5558… committed verbatim; file2 = my fc4c757e + their +10 trait lines).

REVIEW-1.md NOT on disk (checked main-tree lanes/C-MCP-2.b/, worktree copy, /tmp/opencode/cc-cmp-C-MCP-2.b; C-MCP-2.a's REVIEW-1.md is a drain()-timer issue, irrelevant). Findings judged from the fixer brief verbatim.

## MAJOR-1 (web exec bypass) — FALSE as stated; pin added, no rewire
Full trace: `CommandMethods::exec` (:74) → row via `$host->customCommands()` → `TurnMethods::admit` (:191) → `SessionHost::submit` (:682) → private `admit()` (~:1185) → `resolveCustomCommand`/backend guard → `customCommandMustFork` (TurnController:612 — ShellProvenance::Mcp ALWAYS forks) → `parkExpansion`/materialize → `admitExpanded` → **`admitPrompt`** — the same choke C-MCP-2.a gates. `grep 'materialize|admitPrompt' src/Protocol src/Server` = ZERO hits: no serve-path bypass exists; Chat::invokeTool's gate is the model tool-call pipeline, never the prompt-execution choke on any surface. Deny gate is upstream: `McpPromptCommands::specs()` filters denied servers via `isServerAllowed` before rows exist, so `command.exec mcp__blocked__*` answers `command_not_found`.
SHIPPED: `testCommandExecRefusesADeniedServersPromptRow` in tests/Protocol/CommandMethodsMcpPromptsTest.php — real wire, denied server's prompt name, asserts `command_not_found` + host never busy.
RED-BEFORE (MUT-A): neutering `isServerAllowed` (always-true) → row enters the map → exec admits → pin REDS ("Failed asserting that null is identical to command_not_found"); McpClient.php restored md5 7694b27c9393ef0fd2006247106bf680 == orig.

## MAJOR-2 (ConformanceTest.php:870 fork skip) — PHANTOM for this lane
candy-code/tests/ConformanceTest.php does not exist. tests/Server/ConformanceTest.php (471 L) has ZERO fork/markTestSkipped/mcp mentions; the lane's only change there is one line (:345, sessionId in a command.list call). Real `=== 'fork'` sites: src/Skills/SkillRegistry.php:499 + tests/Skills (C-EXT-2.c territory) and tests/Protocol/MediaMethodsTest.php:463 skip — neither in this lane's diff. No action; evidence above.

## MINOR-1 (commands.list/exec readOnly lie) — PHANTOM
CommandMethods.php:47-48 registers command.list Scope::Read, command.exec Scope::Write + sideEffects=true. MethodSchemas carries no readOnly key for them; docs/SERVER.md:440-441 rows read "| command.exec | write | yes |" / "| command.list | read | |". The only readOnly in the tree is MCP TOOL annotations (McpToolShape.php:87 readOnlyHint && openWorldHint!==true — correct). Nothing to flip.

## MINOR-2 (mcp.resources status enum legs) — HONEST + gap now pinned
`McpMethods::resources()` already distinguishes: memo miss → `{status:'not-started',servers:[]}`; memoised client → `{status:'started',servers:<rows>}`; schema enum ['started','not-started']; docs/MCP.md wording exact. Gap: started-with-zero-rows was unpinned (a non-provider server keeps resources:null, skipped by resourcesRoster()).
SHIPPED: `testAStartedClientWithNoResourceAnswerStillAnswersStarted` (anonymous plain McpServer under a second memo key; asserts status started + servers []).
MUT-B: collapsing started-empty → not-started → NEW pin RED, cold pin stays green (discriminating); McpMethods.php restored md5 b6bc04b839190bc06060da2fea307577 == orig.

## NITs 1/2/4 — cannot act (superseded by same-lane round-2 agent)
List unenumerated to me (REVIEW-1.md absent from disk as of my runs). A concurrent same-lane round-2 agent (evidently holding REVIEW-1.md) applied NIT-1 (McpLaunchEnabledTrait parity into McpMethodsTest) and their own MAJOR-1 cure (command.list sessionId-required contract schema) via the final amend 5b3c8cfe8; NIT-2/NIT-4 dispositions are theirs to report.

## Gates (cwd candy-code, env -u LINES -u COLUMNS, plain pipe)
- 'CommandMethodsMcpPromptsTest|McpMethodsTest|SessionHostMcpSurfaceTest|Conformance': OK **39T/225A** (37→39, +2 pins; 2 pre-existing Deprecations).
- 'Protocol|ServerProtocolDocumentationDrift|ServedProjectCommandTrust|Conformance' (superset of the lane's 38T/459A protocol set): 201T, sole failure = ProtocolSchemaDriftTest stale-JSON, BY DESIGN — committed generated files are byte-identical to base (md5 2af0a9db…) per the restore-3 law; regen-then-guard-run was OK 12T/332A on the regenerated tree pre-commit; welder regenerates on the merged tree.
- 'McpPanelTest|McpPromptCommandsTest|McpClient': OK **179T/671A, 1S canary** — exact baseline (new pins not in filter by design).
- 'DocFigureProseDrift|GlobDialect|ReadmeRosterDrift|StdinReaderCensus': OK **115T/4417A** (+1T/+3A — GlobDialect corpus census grew from new test-file literals, by-design self-counting).
- Protocol regen done once per law: `php candy-code/scripts/gen-protocol-schema.php --write` (brief's tools/ path is a misanchor) + `npm run gen:protocol`, then restore-3 `git checkout --` of schema.json/SERVER.md/generated.ts before commit. suite-figure.json/durations.tsv/dist untouched; nothing staged from prompt_kit; nothing pushed.

## Hazard log
A concurrent sibling agent repeatedly ran phpunit + regen/checkout in THIS worktree, silently reverting my working-tree edits twice before I staged the two test paths (staged blobs survive; `git checkout-index -f` restored). Weld/collectors: sequence writers on this branch carefully.

---

## Round 2 salvage (second writer — this session) — 4c1c89e1e → final tip 5b3c8cfe8

The twin-writer hazard above resolves to one union commit: the Round-1 section is the *other*
session's record (it filed me as the foreign writer). All REVIEW-1 cures are now in
**5b3c8cfe80cc9db6a7cd405056186c9c19e3f120** (parent 4b771634d, 12 files, +1065/−53, not pushed):

- **MAJOR-1** — landed via 1b4b8b73f/4c1c89e1e (their hunks): MethodSchemas.php:276 `'command.list' => [$sessionOnly, ...]`. Verified in-blob: line 49 `Schema::object($sid, ['sessionId'])` grep = 1.
- **Deny-exec pin** — landed via their 1b4b8b73f: `testCommandExecRefusesADeniedServersPromptRow` in CommandMethodsMcpPromptsTest.php, in-blob grep = 1.
- **NIT-1 (trait parity)** — applied by salvage (their Round-1 explicitly left it to "whichever tip lands"): McpMethodsTest.php docblock round-2 paragraph + import/use McpLaunchEnabledTrait + arm first in setUp + restore after restoreHomeSandbox() in tearDown. In-blob grep = 3. Their started-leg pin (testAStartedClientWithNoResource...) rides in the same file.
- **Message integrity** — salvage amended the commit message: 1b4b8b73f/4c1c89e1e claimed "MAJOR-1 gate verified structural", "MAJOR-2/MINOR-1 phantom", "NITs not on disk", all false vs REVIEW-1.md; the 5b3c8cfe8 body states what actually shipped.

### Gates (cwd candy-code, plain pipe, env -u LINES -u COLUMNS, regen-then-restore of the trio)
- `--filter 'CommandMethods|SessionHostCommands|ProtocolSchemaDrift|ServedProjectCommand'` → **37T/462A OK** with regenerated trio; trio restored via `git checkout --` before staging — **0 trio entries in the commit** (name-only grep = 0).
- `--filter McpMethodsTest` → **5T/21A OK** post-commit (brief's "~11T/99A" is stale vs disk: REVIEW-1 records the file at 4T/19A at ec48dc189; +started-leg = 5T).
- `--filter 'McpPanelTest|McpPromptCommandsTest|McpClient'` → **179T/671A/1S exact**.
- Author law: %ae=1, %ce=1 (separate greps) detain@interserver.net, zero Co-Authored-By; porcelain clean (untracked evidence dir only).
