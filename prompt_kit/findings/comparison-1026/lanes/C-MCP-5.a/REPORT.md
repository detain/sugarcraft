# C-MCP-5.a — `mcp serve` core: stdio JSON-RPC host over the read-only roster (W33 LATE)

## Verdict
DONE. Final tip `1c8166eab` (2 commits off base `288b81f11`, branch `cc/C-MCP-5.a`, never pushed).
Tree clean. Both commits: author+committer Joe Huss <detain@interserver.net>, zero Co-Authored-By.

## Position at resume (owner-asked)
- HEAD was base `288b81f11`, picks=0; `git status --porcelain` dirty=2 exactly: untracked
  `candy-code/src/MCP/Serve/` (McpServeHost.php, McpServeRoster.php) + `candy-code/tests/MCP/Serve/`
  (McpServeHostTest.php). Lineage markers proved SINGLE-LINEAGE = mine (host grep
  `RpcError::of` ×3 / `new RpcError(` ×0; test `serveAsk` ×10 post-rename; no twin traces).
  No TWIN6 collision — continued per orders.

## Commits
1. `be7c88bd0` — "C-MCP-5.a: mcp serve core — stdio JSON-RPC host over the read-only roster"
   3 files +690 (src/MCP/Serve/McpServeHost.php, src/MCP/Serve/McpServeRoster.php,
   tests/MCP/Serve/McpServeHostTest.php). cat-file -t==commit same shell, %ae/%ce greps 1/1, Co-Authored 0.
2. `1c8166eab` — "C-MCP-5.a: HOOKS.md PreToolUse dispatch row + verbatim-deny note for the serve gate"
   candy-code/docs/HOOKS.md only, 7+/5-. Same author law, greps 1/1/0, porcelain empty.

## What shipped (design block honored verbatim; files.create only + the ONE guard doc row)
- `McpServeRoster`: NAMES=['Read','Glob','Grep','Ls','RepoMap']; build(root,...) via
  ToolCatalog::build(ToolBuildContext) with canonical loader/skills/rule-nudge wiring, rg/fd via
  existing `Bootstrap::capabilityPresent` memo (NO new child-spawn site); per name asserts
  ToolPermissionClass::Read in the live catalog — reclassification fails LOUD (serve refuses,
  never silently widens). Bash/Write/Edit/etc. are neither listed nor callable.
- `McpServeHost`: NDJSON JSON-RPC loop framed through `SugarCraft\McpClient\McpMessage`
  (parsePreservingId — integer ids echo as integers; the ACP adapter's wire discipline).
  Answers initialize (capabilities = `tools` ONLY), notifications/initialized (silence),
  ping, tools/list, tools/call; everything else -32601; malformed → -32700/-32600 with
  null id; response lines never echoed; host bug → -32603 keep-serving; EOF exits 0.
  Every tools/call rides the launch's PreToolUse chain (HookManager built by the caller with
  `Bootstrap::hooks()` over `Bootstrap::permissionGate(consoleSurface: PERMISSION_SURFACE_HEADLESS)`
  — the `-p` shape; gate LAST behind the built-ins incl. ProtectFilesHook). Ask verdict →
  REFUSE (`DenialKind::Unanswered` — nobody to ask); deny → `DenialKind::Hook`; refusal text =
  `$kind->reason($verdict->message)` house prefixes verbatim. Unknown tool = isError RESULT
  ("Error: unknown tool: Bash"), not a lost call. NO session/prompt/memory surface. E646 clean
  (zero timers/timeouts). Zero stderr emitters; write closure is the only stdout.
- `McpServeHostTest` (NEW file, 14 methods/77A): handshake+id-preservation, capability negative
  pin, silent notification, ping `{}`, tools/list EXACT five + schemas, Read inside root, `.env`
  refused via ProtectFiles (prefix + no secret echo), `Bash` unknown, `../` jail refusal without
  leaking content, -32601, null-id parse/invalid pair, response-no-echo, -32602 missing name,
  EOF→exit 0 one frame, roster structural Read-class pin. Real chain in-memory:
  registerBuiltIns() + PermissionGateHook(Default) — no Bootstrap HOME reads.

## The owner-flagged DocFigure seam — satisfied in-step
Guard = `DocFigureProseDriftTest::testHookEventsTableSurvivesTheLiveEnumAndDispatchSites`: it
re-derives docs/HOOKS.md's PreToolUse "Dispatched-from" cell from every `->preToolUse(` site in
src/. My host added `McpServeHost::toolsCall` → doc row re-worded IN COMMIT 2 (plus the :596
"live gates" bullet truthfully widened to the three verbatim-deny gates). DocFigure re-run:
92T/4192A 0F. No lang/README/roster touches needed — this step wires NO CLI verb.

## C-MCP-5.b boundary (refused widenings)
Design `files.modify: none` honored. The mcp `serve` VERB gate entry (Subcommands.php
`SUBCOMMAND_ACTIONS['mcp']`), `cli.mcp.usage` lang keys, MCP.md §Serving MCP + README :1551
rewrite, DocFigure roster-pin `['list','auth','import','trust']` gaining `serve`,
ReadmeRosterDrift — all C-MCP-5.b's per the design deps line. The 14-entry Subcommands
cascade does not move here (serve is a verb of the existing `mcp` subcommand, and this commit
makes zero user-facing strings — refusals ride DenialKind::reason + hook messages, tool errors
are tool-produced). SettingsWriterCensus/ConfigWriteProducer: no config door added — untouched.

## Gate figures (all targeted, cwd=candy-code -c phpunit.xml, env -u LINES -u COLUMNS plain pipe)
- `McpServeHostTest|ReadmeRosterDriftTest|EnvRosterDriftTest|SettingsWriterCensusTest`: 66T/46015A 0F.
- DocFigureProseDriftTest (post-doc-fix): 92T/4192A 0F.
- `TreeWideGuardRosterTest|GlobDialectDifferentialTest|DuplicatedTestHelperDriftTest|StderrEmitterCensusTest`
  at final tip: 139T/27310A **4F — all foreign, none names Serve files**:
  TreeWide ×2 accuse ONLY `Cli/LoginSubcommandTest.php => glob($this->dir.'/*')` (file tracked at
  base via welded C-TUI-5.b 117c948bb — the login lane owes the roster row:
  `'Cli/LoginSubcommandTest.php' => ['glob($this->dir.\'/*\')']` shape);
  DuplicatedTestHelperDrift ×2 = `titleBackend` Chat-pair divergence + `T0` Host/Tui const
  polity — pre-existing, my diff is absent from both full junit diffs.
- `HelpTest|BinCandyCodeDispatchTest|CliLangCatalogueTest` (substring pulled KeyHelpTest):
  313T/101574A 7F+1R = exactly the foreign baseline (KeyHelp 5F+1R, HelpTest[CANDY_CODE_SERVICE_TIER]
  1F, BinCandyCodeDispatchTest[--worktree] 1F). CliLangCatalogueTest itself green (live roster
  stays 14 entries — untouched by design).
- `php tools/check-one-type-per-file.php` at worktree root: 62 libs, 3317 files, 0 problems, rc=0.
- No protocol trio/dist/suite-figure/durations touches — nothing to regen-restore.

## §7 lists
- NEW test file (needs durations.tsv row): `candy-code/tests/MCP/Serve/McpServeHostTest.php` (14 methods).
- Foreign seams to collect (NOT mine to cure): LoginSubcommandTest walk-roster row;
  `titleBackend` ACCEPTED_DIVERGENCE; `T0` const polity row; suite-figure/README staleness pair.

## Residuals
- serve refuses ASK-class calls with "Permission required:" — by §6 design, not a bug.
- The `run()` loop reads line-delimited frames with no max-line cap; the MCP client half
  (StdioMcpClient) has none either — parity by construction, note for any future hardening step.

background=true honored (single worker, no sub-spawns, resumed per owner recovery order).
