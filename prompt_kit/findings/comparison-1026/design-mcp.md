# design-mcp — implementation steps for C-MCP-1 … C-MCP-6

Design pass, read-only, HEAD `5e9195687` (2026-10-26). Paths are repo-root-relative. Every MODIFY path below was checked with `ls`; every CREATE path was checked as absent. Line cites are from the live tree at the time of the pass.

## Summary

| step id | title | effort | serialized tags | deps |
|---|---|---|---|---|
| C-MCP-1.a | User-tier stdio servers spawn in $HOME, project tier at its root | M | [docs:MCP.md §Two tiers + §`.mcp.json` table] [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php] | none |
| C-MCP-1.b | Deferred `mcp_call` keeps `mcp__*` hooks and exact Allow grants | M | [docs:MCP.md §Deferred schemas] [docs:HOOKS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Hooks/HookRegistry.php] [hot:candy-code/src/Permissions/PermissionRule.php] | none |
| C-MCP-6.a | MCP hygiene: `mcp_list` lookup, login-URL scan, SSE fork TLS | S | [docs:MCP.md §Deferred schemas] [hot:candy-code/src/Tools/BuiltIn/McpListTool.php] [hot:candy-code/src/Support/McpLoginFork.php] | C-MCP-1.b (same doc section) |
| C-MCP-4.a | User-tier rows + liveness in `context.sources` (data step) | M | [protocol] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue] | none |
| C-MCP-2.a | `@mcp:` mentions in serve and `-p`; MCP prompt commands in serve | M | [headless:NonInteractive] [docs:MCP.md §Resources + §Prompts] [hot:candy-code/src/Host/SessionHost.php] | C-SEC-3 |
| C-MCP-2.b | `command.list`/`command.exec` carry MCP prompts; `mcp.resources` method | M | [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/MCP/McpClient.php] | C-MCP-2.a |
| C-MCP-2.c | Web composer `@mcp:` resource picker | S | [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/views/SessionView.vue] | C-MCP-2.b |
| C-MCP-3.a | Server-request seam: ping, roots, elicitation, progress (library + core) | M | [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php] | C-MCP-1.a (same two files) |
| C-MCP-3.b | Wire server requests into turns (ask channel, progress, headless) | M | [headless:NonInteractive] [lang:mcp.elicitation.] [docs:MCP.md §Server requests (new)] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php] | C-MCP-3.a, C-MCP-2.a |
| C-MCP-3.c | HTTP/SSE server requests + streamable-HTTP GET channel | M | [docs:MCP.md §Supported transports + §Server requests] | C-MCP-3.b |
| C-MCP-3.d | Long MCP calls continue in background | M | [lang:mcp.background.] [docs:MCP.md §Server requests] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php] | C-MCP-3.b |
| C-MCP-4.b | Runtime `/mcp enable|disable <server> [--persist]` | M | [lang:cmd.mcp-auth.] [docs:MCP.md §Server auth + §Liveness] [docs:COMMANDS.md] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Commands/McpAuthCommand.php] | none |
| C-MCP-4.c | Served `/mcp login` + paste-back finish from a remote browser | M | [lang:cmd.mcp-auth.login] [docs:MCP.md §Server auth + §Authorization-code login] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Support/McpLoginFork.php] | C-MCP-6.a, C-MCP-4.b (same doc section) |
| C-MCP-4.d | `mcp import --write user` | S | [headless:Subcommands,ParsedArgs] [lang:cli.mcp.import.] [docs:README.md] [docs:MCP.md §Foreign spellings] | none — ruling |
| C-MCP-5.a | `mcp serve` core: stdio JSON-RPC host over read-only tools (LATE) | M | none | none — ruling |
| C-MCP-5.b | `candy-code mcp serve` verb, help, completion, docs (LATE) | S | [headless:Subcommands] [lang:cli.] [docs:MCP.md §Serving MCP] [docs:README.md] | C-MCP-5.a |

---

### C-MCP-1.a — User-tier stdio servers spawn in $HOME, project tier at its root
- item: C-MCP-1 (sources: MCP-3-P1)
- kind: fix · MAJOR · effort M
- approach: Root cause: the library spawns with `proc_open(..., null, $env)`, so the child inherits candy-code's cwd (`sugar-mcp-client/src/StdioMcpServer.php:373-383`, arg 4 `null`). The adapter passes only argv and env (`candy-code/src/MCP/StdioMcpServer.php:103-123`). `McpClient::startServer()` knows `$tierPath` (`candy-code/src/MCP/McpClient.php:623`) but `buildServer()` never receives it (`:653`, `:711-741`). So a user-tier `npx`/`python -m` entry runs inside an untrusted checkout and can load that checkout's code. Fix:
  - the library gains `?string $cwd = null`, passed as `proc_open` arg 4;
  - the adapter forwards it;
  - `buildServer()` takes the tier: a user-tier stdio entry spawns in the owned home (`HomeDirectory::owned()`, the root `McpDecision::mcpUserConfigPath()` derives from);
  - a project-tier entry spawns at the canonical project root (`dirname($configPath)`), which also fixes secondary-root `serve` clients that inherit the server's cwd;
  - an explicit absolute `cwd` key on either tier wins; a relative or non-directory `cwd` is a per-entry config error, reported through the existing `startServers()` aggregate.
  The user sees no prompt change, and a repo can no longer feed code to the user's own servers.
- files.create: `sugar-mcp-client/tests/StdioMcpServerCwdTest.php`, `sugar-mcp-client/tests/Fixtures/cwd_server.php`, `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php`
- files.modify: `sugar-mcp-client/src/StdioMcpServer.php` (ctor, `start()` proc_open arg 4), `sugar-mcp-client/README.md` (spawn section: cwd parameter), `candy-code/src/MCP/StdioMcpServer.php` (ctor), `candy-code/src/MCP/McpClient.php` (`startServer()` → `buildServer()` tier arg; `'stdio'` arm reads `$config['cwd']`), `candy-code/docs/MCP.md` (§"Two tiers, one precedence": cwd per tier; §`.mcp.json` type table: stdio Keys cell gains `cwd`)
- tests: create `sugar-mcp-client/tests/StdioMcpServerCwdTest.php` (the fixture echoes `getcwd()` in its `tools/call` reply; null cwd still inherits). Create `candy-code/tests/MCP/StdioMcpUserTierCwdTest.php`:
  - a user-tier entry reports the home;
  - a project-tier entry reports the root, even when the process cwd is a subdirectory;
  - an explicit absolute `cwd` wins;
  - a relative `cwd` is refused for that entry only.
- docs/guards: `candy-code/tests/Config/DocFigureProseDriftTest.php::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` reads the stdio arm's `$config[...]` reads against the table's Keys cell, so the doc edit is mandatory in-step. `tools/check-child-lifetimes.php` row `sugar-mcp-client/src/StdioMcpServer.php::start` is unchanged (same fd spec); re-run `php tools/check-child-lifetimes.php` to confirm. Project-tier pins (`McpTrustPins::pin`) change only for entries that add `cwd`, which is expected.
- serialized: [docs:MCP.md §Two tiers + §`.mcp.json`] [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php]
- deps: none
- phase: normal
- ruling: none
- risks: a TUI launched in a subdirectory now runs project-tier servers at the root rather than the subdir; that is where `.mcp.json`'s relative args are written from, but say so in the doc.

### C-MCP-1.b — Deferred `mcp_call` keeps `mcp__*` hooks and exact Allow grants
- item: C-MCP-1 (sources: MCP-7-P1, MCP-7-P2)
- kind: fix · MAJOR · effort M
- approach: Root cause (hooks): `HookRegistry::findMatches()` tests each matcher against the bare `$toolName` (`candy-code/src/Hooks/HookRegistry.php:191-211`), fed `$context->toolName` by `executeHooks()` (`:875`) and `chainBudgetSeconds()` (`:637-641`). A deferred call arrives as `mcp_call`, so `mcp__srv__.*` hooks stop firing. That includes the built-in `ProtectFilesHook`, whose matcher is `^(…|mcp__.*)$` (`src/Hooks/BuiltIn/ProtectFilesHook.php:430`) — design-pass finding: deferred MCP calls also escape the secret-file guard.
  Fix (hooks): when the subject is `mcp_call` and `McpToolBridge::dispatchedWireName($args)` resolves, a matcher matches on either spelling. `findMatches()` gains an optional `array $toolArgs = []`, and both internal callers pass `$context->toolArgs`. `ProtectFilesHook`'s `default => leafSpellings($args)` already walks nested `arguments`. The script payload keeps `tool_name: mcp_call` with server and tool inside `tool_input`, and the docs say so.
  Root cause (grants): `PermissionRule::namesCall()` returns false for every Allow before the MCP parity leg (`candy-code/src/Permissions/PermissionRule.php:516-518`). Fix (grants): an EXACT (glob-free, argument-free) `Allow mcp__s__t` also matches `mcp_call{server:s,tool:t}`. Glob Allows and `Allow mcp_call` stay untranslated ("a grant covers what it names").
- files.create: `candy-code/tests/Hooks/McpCallHookParityTest.php`
- files.modify: `candy-code/src/Hooks/HookRegistry.php` (`findMatches`, `executeHooks` → `runChain` call at :875, `chainBudgetSeconds`), `candy-code/src/Permissions/PermissionRule.php` (`namesCall` Allow leg), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (docblock :25-32 disclosure), `candy-code/docs/MCP.md` (§"Deferred schemas": the hook paragraph at :684-692 and the grants paragraph at :715-721), `candy-code/docs/HOOKS.md` (§"`matcher:` delimiters": dual-subject note), `candy-code/docs/PERMISSIONS.md` (§"Pattern matching" :776-781 "Allow covers only what it names" sentence; the ProtectFiles row at :973)
- tests: create `candy-code/tests/Hooks/McpCallHookParityTest.php`:
  - a `mcp__srv__.*` deny hook blocks `mcp_call(srv, t)`;
  - a Claude-imported matcher does the same;
  - `ProtectFilesHook` refuses an `mcp_call` whose nested `arguments` name `.env`;
  - a hook matching only `mcp_call` still fires;
  - non-dispatched names are unaffected.

  Modify `candy-code/tests/Permissions/McpDispatchParityTest.php`: flip `testAllowTranslatesInNeitherDirection` so an exact Allow covers the args form, while a glob Allow and `Allow mcp_call` do not. Modify `candy-code/tests/Hooks/HookRegistryTest.php` (the `findMatches` signature).
- docs/guards: no drift arm pins the replaced sentences (grep `additionally match`/`translate in neither` in tests: 0). Check `tests/Hooks/ProtectFilesPolicySurfacesTest.php` if the PERMISSIONS.md ProtectFiles row text moves.
- serialized: [docs:MCP.md §Deferred schemas] [docs:HOOKS.md] [docs:PERMISSIONS.md] [hot:candy-code/src/Hooks/HookRegistry.php] [hot:candy-code/src/Permissions/PermissionRule.php]
- deps: none
- phase: normal
- ruling: none
- risks: a hook script that switches on `tool_name` still sees `mcp_call`; only the matcher is dual, so document the payload shape plainly.

### C-MCP-6.a — MCP hygiene: `mcp_list` lookup, login-URL scan, SSE fork TLS
- item: C-MCP-6 (sources: MCP-7-P3, MCP-5-P1, MCP-4-P1, VIS-3-P1)
- kind: fix · MINOR (the login-URL leg is upgraded, see the first bullet) · effort S
- approach: Three legs, one lane, disjoint files.
  - **Login URL (upgrade).** `McpLoginFork::poll()` keeps a 1500-byte tail (`candy-code/src/Support/McpLoginFork.php:35,116`) and takes the FIRST `https?://\S+` (`:120`). The real child prints ``Open this URL in a browser to authorize `<serverUrl>`:`` BEFORE the authorize URL (`candy-code/src/MCP/OAuthLoopbackFlow.php:158-160`). So the TUI announces the server URL (with a trailing `` `: ``), not the login link, on every real flow. The existing test stubs print only the URL (`tests/Chat/McpLoginForkTest.php:90`). Fix: accumulate an un-truncated scan buffer (capped at 64 KiB) until announced, and take the first line consisting solely of one URL (`^\s+(https?://\S+)\s*$`m, the shape `OAuthLoopbackFlow` prints at :160). Expose the parsed `redirect_uri` port for C-MCP-4.c.
  - **`mcp_list`.** It truncates at 64 KiB with only a `server` filter (`candy-code/src/Tools/BuiltIn/McpListTool.php:79-92,131`). Add a `tool` argument (exact name → that schema only). When an unfiltered or server-filtered listing would exceed the cap, return a names-plus-one-line overview that says how to fetch each schema.
  - **SSE fork.** `SseMcpServer::openStream()` overwrites `$this->socket` (`candy-code/src/MCP/SseMcpServer.php:413`). In a forked child this frees the inherited TLS resource, whose `SSL_shutdown` puts `close_notify` on the parent's connection. Fix: when the current pid is not the opener, retain the inherited resource (never close it) before replacing it.
  - **VIS-3-P1.** The stale COMPARISON.md:788 sentence is already absent from the rewritten `candy-code/COMPARISON.md` (grep `launch-time entry bytes` = 0 at design time). Re-grep only; COMPARISON.md is orchestrator-owned, so do not edit it.
- files.create: `candy-code/tests/MCP/SseMcpServerForkTlsTest.php`
- files.modify: `candy-code/src/Support/McpLoginFork.php` (`poll`, slot shape), `candy-code/src/Tools/BuiltIn/McpListTool.php` (`description`, `inputSchema`, `execute`), `candy-code/src/MCP/SseMcpServer.php` (`openStream`), `candy-code/docs/MCP.md` (§"Deferred schemas" `mcp_list` bullet)
- tests: modify `candy-code/tests/Chat/McpLoginForkTest.php`: a stub printing the exact `OAuthLoopbackFlow` shape (server URL in backticks, then a 2 KB authorize URL on its own line) announces the authorize URL intact. Modify `candy-code/tests/MCP/McpDispatcherToolsTest.php`: a roster above the cap stays reachable through `tool`, and the overview names every tool. Create `candy-code/tests/MCP/SseMcpServerForkTlsTest.php`: a forked child's `openStream` leaves the parent's inherited resource open (plain-tcp stub; the TLS alert itself is PLAUSIBLE, not observable in-suite).
- docs/guards: `candy-code/tests/Tools/BuiltInToolCorpus.php` and `candy-code/tests/Tools/Catalog/ToolDocDriftTest.php` read `mcp_list` (externally wired, not in the plain roster). Re-run both. No catalog position change (39 stays).
- serialized: [docs:MCP.md §Deferred schemas] [hot:candy-code/src/Tools/BuiltIn/McpListTool.php] [hot:candy-code/src/Support/McpLoginFork.php]
- deps: C-MCP-1.b (edits the same §Deferred schemas paragraphs; sequence, or run as one docs owner)
- phase: normal
- ruling: none
- risks: the `mcp_list` description and schema are model-facing, so any PromptStability pin over externally wired schemas must move in-step.

### C-MCP-4.a — User-tier rows + liveness in `context.sources` (data step)
- item: C-MCP-4 (sources: k2-dorm `McpDecision` user servers, GAP-MCP-10 web-liveness half); feeds P-CTX-BREAKDOWN
- kind: dormant-wire · effort M
- approach: Dormant data: `McpDecision::mcpServerInventory()` already computes `userStatus/userPath/userServers/userSkipped/userError` (`candy-code/src/Cli/McpDecision.php:294-340, 648-680`). `ContextSources::answer()` maps only the project `servers` (`candy-code/src/Host/ContextSources.php:158-172`). The fix:
  - `mcp` gains `userStatus`, `userError` and `userSkipped`;
  - every server row gains `tier: project|user` (user rows appended, shadowed names already excluded by the census);
  - every row gains a nullable `liveness {up: bool|null, tools: int}` from `Bootstrap::mcpLivenessSnapshot($root)`, which is memo-only and NEVER launches (null when this process holds no client);
  - `answer()` takes an injectable `?array $mcpLiveness` for tests;
  - the schema lives in `Definitions.php` (the `mcp` object at :215-224);
  - regenerate the chain;
  - the web ContextPanel lists user-scope rows under their own heading with a liveness dot.
  Self-contained: P-CTX-BREAKDOWN reads these rows (web) and `mcpServerInventory()` (TUI) without further MCP work.
- files.create: `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php`
- files.modify: `candy-code/src/Host/ContextSources.php` (`answer`), `candy-code/src/Protocol/Schema/Definitions.php` (context-sources `mcp` object), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code-web/src-web/components/panels/ContextPanel.vue` (MCP section :144-158)
- tests: create `candy-code/tests/Protocol/ContextSourcesMcpUserTierTest.php`:
  - user rows carry `tier: user`;
  - a shadowed user name is absent;
  - `userError` is surfaced for an undecodable user file;
  - liveness is null with no memoised client, and filled from an injected snapshot.

  Modify `candy-code/tests/Protocol/ContextSourcesTest.php` (inventory fixture shape). Modify `candy-code-web/src-web/components/panels/__tests__/contextPanel.spec.ts` (user rows render; liveness dot states).
- docs/guards: `scripts/gen-protocol-schema.php` → `candy-code-web/scripts/gen-protocol.mjs` regen in-step. `candy-code/tests/Protocol/ProtocolSchemaDriftTest.php` and `candy-code/tests/Config/ServerProtocolDocumentationDriftTest.php` go green on the regen. The `vitest --no-file-parallelism` web leg.
- serialized: [protocol] [hot:candy-code/src/Host/ContextSources.php] [hot:candy-code-web/src-web/components/panels/ContextPanel.vue]
- deps: none
- phase: normal
- ruling: none
- risks: ContextPanel.vue is also P-CTX-BREAKDOWN's and C-SEC-5's surface; keep this lane's markup to one section, so the breakdown lane restyles rather than conflicts.

### C-MCP-2.a — `@mcp:` mentions in serve and `-p`; MCP prompt commands in serve
- item: C-MCP-2 (sources: GAP-MCP-06, GAP-MCP-07, GAP-CMD-09, MCP-1-P1, p1-miss "MCP surfaces in serve/web and -p", k2-dorm `ContextMentions`/`McpPromptCommands`)
- kind: dormant-wire · effort M
- approach: Root cause: only Chat passes an MCP provider. `TurnController::userTurnMessage()` already accepts `$mcpProvider` and arms `withMcpReader` (`candy-code/src/Host/TurnController.php:1032-1075`), but `SessionHost::admitPrompt` omits it (`candy-code/src/Host/SessionHost.php:1702-1710`). NonInteractive builds `ContextMentions` without a reader (`candy-code/src/Cli/NonInteractive.php:448-458`). `McpPromptCommands::specs()` is merged only in the Chat ctor (`candy-code/src/Chat.php:1795-1801`). Wire:
  1. SessionHost gets a lazy provider `static fn () => Bootstrap::mcpClient($root)`. It is injectable through `SessionHost::new(..., ?\Closure $mcpProvider = null)` for tests, and on serve the workspace tool build has already memoised the client.
  2. The ctor merges `McpPromptCommands::specs($provider)` UNDER the disk rows through a new public `McpPromptCommands::mergeUnderDisk()` that spells the same `array_merge($mcpSpecs, $diskSpecs)` order as `Chat::mergeCustomCommandRows` (Chat.php untouched).
  3. Both expansion paths call `McpPromptCommands::materialize()` before `expandCustomCommand`: the sync path at :906-918 and the forked `parkExpansion` `$run` at :950-990, so the network fetch stays in the child. A null result expands to '' and hits the existing empty-expansion refusal.
  4. `admitPrompt` passes `mcpProvider`.
  5. NonInteractive chains `->withMcpReader(...)` over `Bootstrap::mcpClient($mentionRoot)`.
  Users see `@mcp:srv:uri` resolve in web and `-p`, and `/mcp__srv__prompt` typed in a web session runs. The `/` popup listing comes in C-MCP-2.b.
- files.create: `candy-code/tests/Host/SessionHostMcpSurfaceTest.php`
- files.modify: `candy-code/src/Host/SessionHost.php` (`__construct`, `new`, `submit` custom-command branch, `parkExpansion`, `admitPrompt`), `candy-code/src/Cli/NonInteractive.php` (`run` mention chain :448-458), `candy-code/src/Commands/McpPromptCommands.php` (new `mergeUnderDisk()`), `candy-code/docs/MCP.md` (§"Resources as `@mcp:` mentions": replace the dated "TUI-only for now" paragraph at :833-837; §"Prompts as slash commands": serve parity sentence)
- tests: create `candy-code/tests/Host/SessionHostMcpSurfaceTest.php` (fake provider):
  - a served submit resolves `@mcp:` into a fenced attachment;
  - a served `/mcp__srv__p` materialises and is shadowed by a same-name disk file;
  - a failed fetch is refused, not sent empty.

  Modify `candy-code/tests/Cli/NonInteractiveMentionsTest.php` (`-p` resolves `@mcp:`) and `candy-code/tests/Host/TurnControllerMcpSeamTest.php` (both hosts reach the one arming site).
- docs/guards: `candy-code/tests/Config/DocFigureProseDriftTest.php`:
  - `testMcpResourceMentionProseReadsTheKeywordLegItself` flips: the absence legs over `mcpProvider`/`withMcpReader` in NonInteractive.php and SessionHost.php become presence legs, and the 'TUI-only for now' pin becomes the parity sentence.
  - `testMcpPromptCommandProseReadsTheMergeAndTheFetch` also pins `McpPromptCommands::mergeUnderDisk()` spelling the same merge order, and the SessionHost specs site.
- serialized: [headless:NonInteractive] [docs:MCP.md §Resources + §Prompts] [hot:candy-code/src/Host/SessionHost.php]
- deps: C-SEC-3 (provenance-aware shell gate; serving MCP prompt bodies widens the `!cmd` hole until it lands)
- phase: normal
- ruling: none
- risks: served disk rows come from `loadAll($root)` without the trust flag (C-SEC-4's defect); the MCP merge must not paper over it, so leave that leg to C-SEC-4.

### C-MCP-2.b — `command.list`/`command.exec` carry MCP prompts; `mcp.resources` method
- item: C-MCP-2 (sources: GAP-MCP-07, GAP-CMD-09, CLAW-new#9)
- kind: dormant-wire · effort M
- approach: `CommandMethods::list/exec` read only `workspace->commandLoader->loadAll()` (`candy-code/src/Protocol/Methods/CommandMethods.php:49-62, 66-91, 127-138`), so served MCP prompts are neither listed nor executable via `command.exec` (→ `command_not_found`). The fix:
  - `command.list`/`exec` read the session host's command map (new `SessionHost::customCommands()` accessor);
  - MCP rows carry `source: "mcp"`: the `command.list` `source` enum in `MethodSchemas.php:263-269` grows `mcp`;
  - a new `mcp.resources` (Scope::Read, `sessionId`) answers the launch-frozen per-server resource rows (uri, name, mimeType, bounded description). `McpClient::primeSurfaceRosters()` keeps bounded rows instead of a count (counts stay derived for `McpPanel`);
  - `mcp.resources` reads the memoised client only (`{status: "not-started"}` otherwise), so a popup never launches servers.
  The web `/` popup then lists MCP prompts with no web change; the composer picker is C-MCP-2.c.
- files.create: `candy-code/src/Protocol/Methods/McpMethods.php`, `candy-code/tests/Protocol/CommandMethodsMcpPromptsTest.php`, `candy-code/tests/Protocol/McpMethodsTest.php`
- files.modify: `candy-code/src/Protocol/Methods/CommandMethods.php` (`list`, `exec`, `files`), `candy-code/src/Protocol/Dispatcher.php` (`methods()` roster), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`command.list` source enum; `mcp.resources`), `candy-code/src/Host/SessionHost.php` (public `customCommands()`), `candy-code/src/MCP/McpClient.php` (`primeSurfaceRosters`, new `resourcesRoster()`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen + method prose), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code/docs/MCP.md` (§"Prompts as slash commands": web popup sentence; §Resources: `mcp.resources`)
- tests: create `candy-code/tests/Protocol/CommandMethodsMcpPromptsTest.php` (an MCP row listed with `source: mcp`; `command.exec` admits it; a disk file shadows it). Create `candy-code/tests/Protocol/McpMethodsTest.php` (rows from a memoised fake client; `not-started` without one; denied servers absent). Modify `candy-code/tests/Server/ConformanceTest.php` if it enumerates the method roster.
- docs/guards: protocol regen; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`; `candy-code/tests/Tui/McpPanelTest.php` (the resources count still renders).
- serialized: [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Protocol/Methods/CommandMethods.php]
- deps: C-MCP-2.a
- phase: normal
- ruling: none
- risks: C-SEC-4 also edits served command routing (CommandMethods trust); land in different waves and rebase on whichever lands first.

### C-MCP-2.c — Web composer `@mcp:` resource picker
- item: C-MCP-2 (sources: CLAW-new#9, p1-miss "web composer `@mcp:` completion")
- kind: feature · effort S
- approach: The composer's `@` popup is file-only (`searchFiles` prop, `candy-code-web/src-web/components/Composer.vue:17-18, 72-110`). When the trailing partial starts with `mcp:`, the popup lists `mcp.resources` rows (fetched once per session and cached; filtered by server/uri/name). Accepting inserts `@mcp:<server>:<uri> `, the keyword form the server already resolves. SessionView passes a `searchMcpResources` prop the way it passes `searchFiles`. With no rows (`not-started`, no servers), the popup falls back to files.
- files.create: `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts`
- files.modify: `candy-code-web/src-web/components/Composer.vue` (mention popup), `candy-code-web/src-web/views/SessionView.vue` (Composer props at :175)
- tests: create `candy-code-web/src-web/components/__tests__/composerMcpMentions.spec.ts` (an `@mcp:` partial lists resource rows; accept inserts the two-colon form; an empty roster falls back to files). Run vitest with `--no-file-parallelism`.
- docs/guards: none (MCP.md parity sentence landed in C-MCP-2.a/2.b)
- serialized: [hot:candy-code-web/src-web/components/Composer.vue] [hot:candy-code-web/src-web/views/SessionView.vue]
- deps: C-MCP-2.b
- phase: normal
- ruling: none
- risks: Composer.vue is a central web component (other WEB and image-menu lanes), so keep the change inside the mention-popup computed and helpers.

### C-MCP-3.a — Server-request seam: ping, roots, elicitation, progress (library + core)
- item: C-MCP-3 (sources: GAP-MCP-08, p1-miss "MCP elicitation/sampling/roots/logging/ping", OC-new#1, CC-new#2)
- kind: gap · effort M
- approach: Today the client advertises `capabilities: {}` (`sugar-mcp-client/src/StdioMcpServer.php:408-414`), and `readResponse()` skips every method-bearing frame unanswered (`:1106-1160`), so a server's `ping`, `roots/list` or `elicitation/create` waits forever.
  Library:
  - a ctor `array $capabilities = []` (still encoded as an object);
  - an instance-level `?\Closure $onServerMessage`, plus a per-call override on `callTool()`/`request()`;
  - inside the exchange, a request frame (method + id) goes to the handler and its result or error is written back under the lock with `McpMessage::success/error`, preserving the wire id;
  - notifications go to the handler too;
  - with no handler, `ping` answers `{}` and every other request answers -32601 (spec-compliant default);
  - the per-call deadline is extended by the handler's wall time, so a human answering an elicitation never burns `toolTimeout` (E646 spirit).
  candy-code core:
  - `McpServerRequests` dispatches `ping` → `{}`, `roots/list` → the project root as a `file://` root, `elicitation/create` → `McpElicitation` (an injected approver, else `cancel`), sampling and the rest → -32601, and `notifications/progress` → an injected progress sink;
  - `McpElicitation` maps the flat requested schema to per-field questions (enum → options; boolean → yes/no; string/number/integer → typed answer validated against the type) and returns `accept + content`, `decline` or `cancel`;
  - `AnswersServerRequests` is a narrowed-instanceof capability (the `pumpStderr` precedent), so `McpServer` stays unchanged;
  - the stdio adapter implements it;
  - `McpClient::callTool()` takes `?McpServerRequests` and builds the instance-level roots/ping answerer at `buildServer()`, advertising `roots` and `elicitation`.
- files.create: `candy-code/src/MCP/AnswersServerRequests.php`, `candy-code/src/MCP/McpServerRequests.php`, `candy-code/src/MCP/McpElicitation.php`, `sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php`, `sugar-mcp-client/tests/Fixtures/server_request_server.php`, `candy-code/tests/MCP/McpServerRequestsTest.php`, `candy-code/tests/MCP/McpElicitationTest.php`
- files.modify: `sugar-mcp-client/src/StdioMcpServer.php` (ctor, `start` handshake capabilities, `callTool`/`callToolWaiting`, `request`, `readResponse`), `sugar-mcp-client/README.md` (Wire shape: capabilities; a new "Server requests" section; drop the "Phase 2" exclusion wording that no longer holds), `candy-code/src/MCP/StdioMcpServer.php` (ctor capabilities, `callTool` per-call answers), `candy-code/src/MCP/McpClient.php` (`callTool`, `buildServer` stdio arm)
- tests: create `sugar-mcp-client/tests/StdioMcpServerServerRequestTest.php` (fixture `server_request_server.php`):
  - a ping mid-call is answered;
  - an unknown request gets -32601;
  - the call deadline is paused while the handler runs;
  - configured capabilities reach `initialize`.

  Modify `sugar-mcp-client/tests/StdioMcpServerSdkWireShapeTest.php` (the default still sends `{}`). Create `candy-code/tests/MCP/McpServerRequestsTest.php` (roots answer, sampling -32601, progress sink). Create `candy-code/tests/MCP/McpElicitationTest.php` (schema→questions, type validation, decline/cancel).
- docs/guards: `sugar-mcp-client/tests/McpMessageWireShapeTest.php` unchanged; `tools/check-child-lifetimes.php` unchanged (no new spawn); `php tools/check-one-type-per-file.php` (three new types, three files).
- serialized: [hot:sugar-mcp-client/src/StdioMcpServer.php] [hot:candy-code/src/MCP/McpClient.php]
- deps: C-MCP-1.a (same two files; C-MCP-1.a adds the ctor `cwd` param this lane extends)
- phase: normal
- ruling: none
- risks: an elicitation holds the server's cross-process exchange lock while the human answers, so other calls to that one server queue; document it.

### C-MCP-3.b — Wire server requests into turns (ask channel, progress, headless)
- item: C-MCP-3 (sources: GAP-MCP-08, OC-new#1, CC-new#2)
- kind: gap · effort M
- approach: MCP tools never see the turn's approver: `EngineBackend::turnTools()` binds `RelaysPermissionAsks` only inside the `DelegatesToEngine` arm (`candy-code/src/Backend/EngineBackend.php:3209-3283`), and `McpToolBridge` is `Tool, AcceptsHeartbeat` only (`candy-code/src/Tools/McpToolBridge.php:88`).
  - `McpToolBridge` and `McpCallTool` implement `RelaysPermissionAsks` and `AcceptsToolProgress`, and build a per-call `McpServerRequests` from the bound approver and progress emitter.
  - `turnTools()` binds the approver in the `AcceptsToolProgress` arm and the plain-tool arm too, still excluding `TaskTool`. `Runtime::executeConcurrently()` already relays `RelaysPermissionAsks` members (`candy-code/src/Runtime.php:2568`), so Runtime is untouched.
  - An elicitation reuses `AskUser`'s ask shape (the same `ToolCall` and `HookResult::ask`, question text "MCP server `<name>` asks: …"), so the TUI 1.C modal and the web PermissionCard render options with no UI change. `AskUserTool`'s reply reading is hoisted into a public static for reuse.
  - Progress frames become `ToolProgress` tails (web `tool.progress`, TUI running row).
  - `-p`: `NonInteractive::withoutInteractiveUser()` also closes MCP tools, so elicitations answer `cancel`.
- files.create: `candy-code/tests/Tools/McpToolProgressTest.php`, `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php`
- files.modify: `candy-code/src/Tools/McpToolBridge.php` (interfaces, `executeWithHeartbeat`), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (same), `candy-code/src/Tools/BuiltIn/AskUserTool.php` (hoist the reply reader), `candy-code/src/Backend/EngineBackend.php` (`turnTools` binding arms), `candy-code/src/Cli/NonInteractive.php` (`withoutInteractiveUser`), `candy-code/lang/en.php` (`mcp.elicitation.*`), `candy-code/docs/MCP.md` (new §"Server requests": ping, roots, elicitation as questions, progress, what is refused)
- tests: create `candy-code/tests/Tools/McpToolProgressTest.php` (progress becomes a ToolProgress frame; an elicitation reaches the bound approver and the accepted content returns to the server). Create `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php` (a `-p` elicitation is cancelled and the model is told why). Modify `candy-code/tests/Tools/BuiltInToolCorpus.php` if it pins `McpCallTool`'s interface set.
- docs/guards: the `LangParityTest` keys (prefix `mcp.elicitation.`); the new MCP.md section gets no DocFigure arm unless the lane adds one (recommended: pin the advertised capability set against `McpServerRequests`).
- serialized: [headless:NonInteractive] [lang:mcp.elicitation.] [docs:MCP.md §Server requests (new)] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php]
- deps: C-MCP-3.a, C-MCP-2.a (owner order C-MCP-2 → C-MCP-3: the served path carries the same provider)
- phase: normal
- ruling: none
- risks: a multi-field form becomes a short series of questions rather than one form (see Notes); C-SEC-8 also edits `McpToolBridge`/`McpCallTool` (result fence), so serialize.

### C-MCP-3.c — HTTP/SSE server requests + streamable-HTTP GET channel
- item: C-MCP-3 (sources: GAP-MCP-03, GAP-MCP-08, OC-new#1)
- kind: gap · effort M
- approach: `HttpMcpServer` handshakes with `capabilities: {}` (`candy-code/src/MCP/HttpMcpServer.php:518-552`), and `replyFromEventStream()` (:828-851) looks only for its own id, dropping server requests and progress that ride the POST reply stream. `SseMcpServer` ignores requests on its GET stream.
  - Both implement `AnswersServerRequests`: a request frame found while reading a reply (or the legacy GET stream) is answered by a POST of the JSON-RPC response, carrying the `Mcp-Session-Id` header; progress notifications go to the per-call sink.
  - New opt-in streamable-HTTP GET channel (entry key `stream: true`, http only): after `initialize`, reuse `SseMcpServer`'s deadline-bounded raw-socket reader and the shared `SseLineParser`. It is drained at each exchange start and on `McpClient::pumpStderr()`'s idle pump, so pings between calls are answered.
  - The buffered POST path stays the default.
- files.create: `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php`, `candy-code/tests/MCP/SseMcpServerServerRequestTest.php`
- files.modify: `candy-code/src/MCP/HttpMcpServer.php` (`handshake`, `replyFromEventStream`, `callTool`, GET channel), `candy-code/src/MCP/SseMcpServer.php` (stream frame routing, handshake capabilities), `candy-code/src/MCP/SseLineParser.php` (shared reader, if hoisting is needed), `candy-code/src/MCP/McpClient.php` (`buildServer` http arm `stream` key; idle pump), `candy-code/docs/MCP.md` (§"Supported transports" / §"How the legacy SSE transport runs": requests, `stream`; §"`.mcp.json`" http Keys cell gains `stream`)
- tests: create `candy-code/tests/MCP/HttpMcpServerGetStreamTest.php` (a ping on the GET channel is answered; an elicitation inside a POST reply stream reaches the answerer; the default path is unchanged). Create `candy-code/tests/MCP/SseMcpServerServerRequestTest.php` (a request on the legacy stream is answered via the endpoint POST). Modify `candy-code/tests/MCP/HttpMcpServerStreamableHttpTest.php` (capabilities in `initialize`).
- docs/guards: `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the http Keys cell gains `stream`) and `::testMcpTransportCensusRowsAreTheFactoryArms`; no proc_open, so the child-lifetime rosters are unaffected.
- serialized: [docs:MCP.md §Supported transports + §Server requests] [hot:candy-code/src/MCP/McpClient.php]
- deps: C-MCP-3.b
- phase: normal
- ruling: none
- risks: the GET channel is read only when candy-code pumps (no event loop on the turn path), so server pushes between turns wait for the next pump.

### C-MCP-3.d — Long MCP calls continue in background
- item: C-MCP-3 (sources: CC-new#2)
- kind: feature · effort M
- approach: Claude Code moves MCP calls running past 2 minutes to background tasks; candy-code has no equivalent (MCP-N2).
  - A bridged or dispatched call runs in a forked grandchild (the house `Support/ForkedChild` helper). The library's fork safety already lets any forked process exchange on a shared server.
  - The turn waits with the existing `onWait` beats.
  - At 120 s (fixed, CC parity) the tool returns "still running as background MCP job `<id>`; the result will arrive as a message". The grandchild keeps the exchange and writes its result payload under a registry directory (`McpBackgroundCalls`).
  - A `McpBackgroundInbox` (`TurnInbox`) composed at `EngineBackend.php:5022` delivers finished results at the next step boundary, or the next turn.
  - Fast calls are byte-identical to today.
  - `-p` never backgrounds: it waits for the result, as now.
- files.create: `candy-code/src/MCP/McpBackgroundCalls.php`, `candy-code/src/Backend/McpBackgroundInbox.php`, `candy-code/tests/MCP/McpBackgroundCallTest.php`
- files.modify: `candy-code/src/Tools/McpToolBridge.php` (`executeWithHeartbeat`), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (`executeWithHeartbeat`), `candy-code/src/Backend/EngineBackend.php` (composite inbox at :5022), `candy-code/lang/en.php` (`mcp.background.*`), `candy-code/docs/MCP.md` (§"Server requests": long calls)
- tests: create `candy-code/tests/MCP/McpBackgroundCallTest.php`:
  - a slow stub call returns the background placeholder at the threshold (the threshold is injectable in-test);
  - the result arrives through the inbox;
  - a fast call is unchanged;
  - `-p` waits;
  - the grandchild is reaped (poll-then-reap).
- docs/guards: the fork census (`candy-code/tests/Support/ForkedChildExitConventionTest.php`) for the new fork site. Any `proc_open` must go through `ProcessContainment::spawnSpec` and get a `candy-code/tests/Support/DescriptorInheritanceGuardTest.php` roster row. `LangParityTest`.
- serialized: [lang:mcp.background.] [docs:MCP.md §Server requests] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php]
- deps: C-MCP-3.b
- phase: normal
- ruling: Background stdio calls hold that server's exchange lock until they finish, so later calls to the same server queue behind them. Accept, or background http/sse servers only?
- risks: a backgrounded call that never answers is bounded only by `toolTimeout`, which keeps its meaning; the turn no longer blocks on it.

### C-MCP-4.b — Runtime `/mcp enable|disable <server> [--persist]`
- item: C-MCP-4 (sources: GAP-MCP-10)
- kind: gap · effort M
- approach: Today a server can be disabled only through `disabledMcpServers` plus a relaunch (E696 deny map, applied at start and in `isServerAllowed()`, `candy-code/src/MCP/McpClient.php:204-207, 893-901`).
  - `McpClient` gains `disableForSession()`/`enableForSession()`/`sessionDisabled()`: a session deny set consulted by `isServerAllowed()` and the router.
  - The tool roster is unchanged (cache law): calls are refused with "disabled for this session (/mcp enable <server>)".
  - `startedSnapshot()` rows carry `disabled`.
  - `McpAuthCommand::execute()` grows `enable` and `disable` arms (:167-174).
  - `--persist` adds or removes the exact name in the user `disabledMcpServers` via `Bootstrap::writeUserConfig()` (static call; Bootstrap.php untouched).
  - The TUI panel shows a "disabled" suffix.
  - Forked turns and sub-agents inherit the parent's memoised client, so the next turn sees the change.
  - The served path works through `McpAuthHostCommand` → `command.exec` with no protocol change.
  - The web disabled state is not shown (C-MCP-4.a's liveness has no `disabled` field). Optional follow-up.
- files.create: `candy-code/tests/Commands/McpAuthCommandEnableDisableTest.php`, `candy-code/tests/MCP/McpRuntimeDisableTest.php`
- files.modify: `candy-code/src/MCP/McpClient.php` (`isServerAllowed`, `router`, `startedSnapshot`, new session-disable API), `candy-code/src/Commands/McpAuthCommand.php` (`execute` arms), `candy-code/src/Tui/McpPanel.php` (liveness suffix), `candy-code/lang/en.php` (`cmd.mcp-auth.enable.*`, `cmd.mcp-auth.disable.*`, `cmd.mcp-auth.unknown`, `cmd.mcp.hint`, `cmd.mcp.description`), `candy-code/docs/MCP.md` (§"Server auth (`/mcp`)": the five-command fence becomes seven, plus the lead-in count; §"Liveness in a running session": suffix row), `candy-code/docs/COMMANDS.md` (regen via `candy-code/tools/gen-command-docs.php`)
- tests: create `candy-code/tests/MCP/McpRuntimeDisableTest.php` (a disabled server's call is refused with the hint; the roster is unchanged; enable restores; a forked copy inherits). Create `candy-code/tests/Commands/McpAuthCommandEnableDisableTest.php` (the arms; `--persist` writes and removes exactly one name; an unknown server is refused). Modify `candy-code/tests/Tui/McpPanelTest.php` (suffix).
- docs/guards: `DocFigureProseDriftTest`:
  - `::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` (match-arm order pin at :4050-4061, "five sub-commands" → "seven", the `cmd.mcp-auth.unknown` roster);
  - `::testMcpLivenessSuffixesAndTheNeverLaunchingSnapshotReadThePanel` (new suffix);
  - `::testCommandsMcpPaletteSentenceTracksTheTruthfulLabel` if `cmd.mcp.description` moves.

  Regen `docs/COMMANDS.md` in-lane. `builtin-commands/0900-mcp.php` is untouched (its text is Lang keys), so this step is not `[commands]`.
- serialized: [lang:cmd.mcp-auth.] [docs:MCP.md §Server auth + §Liveness] [docs:COMMANDS.md] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Commands/McpAuthCommand.php]
- deps: none
- phase: normal
- ruling: none
- risks: a turn already forked before `/mcp disable` keeps its pre-disable copy, so document "takes effect from the next turn".

### C-MCP-4.c — Served `/mcp login` + paste-back finish from a remote browser
- item: C-MCP-4 (sources: GAP-MCP-04)
- kind: gap · effort M
- approach: In the TUI, `Chat::handleMcpAuthCommand` intercepts `login <server>` into `McpLoginFork` (`candy-code/src/Chat.php:19810-19843`). Served `/mcp login` reaches `McpAuthHostCommand` → `printLoginGuidance()`, which only prints shell guidance (`candy-code/src/Host/Commands/McpAuthHostCommand.php:20-27`). And a remote browser can never reach the child's `127.0.0.1` callback.
  - (1) `McpAuthHostCommand` intercepts `login <server>` like Chat, arms `McpLoginFork`, and registers the slot with a new `ServedMcpLogins` watcher (server → sessionId). `ServerContext::pump()` polls it and appends uiOnly rows (link, done/failed) through a new `SessionHost::appendNotice()`, which emits `message.created`.
  - (2) Paste-back: `login <server> --code <redirected-url>` is handled inside `McpLoginFork::arm()` when a slot is live. It accepts only `http://127.0.0.1|localhost:<the slot's redirect port>/…`, then issues a local GET to that loopback URL, so the waiting child finishes its own state and PKCE exchange. That works in the TUI too with no Chat change (Chat already forwards `rest`).
  - Anything else is refused (no SSRF). Tokens still attach at the next launch (existing honesty).
- files.create: `candy-code/src/Host/ServedMcpLogins.php`, `candy-code/tests/Host/McpLoginServedTest.php`
- files.modify: `candy-code/src/Host/Commands/McpAuthHostCommand.php` (`run` intercept), `candy-code/src/Support/McpLoginFork.php` (`arm` paste-back leg, redirect-port accessor), `candy-code/src/Host/SessionHost.php` (public `appendNotice`), `candy-code/src/Protocol/ServerContext.php` (`pump`), `candy-code/lang/en.php` (`cmd.mcp-auth.login-*`: served armed, paste-back delivered/refused), `candy-code/docs/MCP.md` (§"Server auth (`/mcp`)" + §"Authorization-code login": served flow and paste-back)
- tests: create `candy-code/tests/Host/McpLoginServedTest.php` (a served `/mcp login` arms and the watcher appends the link row; done row; a second session's login is independent). Modify `candy-code/tests/Chat/McpLoginForkTest.php` (paste-back reaches a stub loopback listener; a non-loopback host, wrong port or no live slot is refused).
- docs/guards: `DocFigureProseDriftTest::testTheLoginCommandWordRoutesThroughTheVerbGateAndGuidanceArm` (the guidance arm stays for bare `login`; re-pin the narrative and the `candy-code mcp auth login <server>` occurrence count of 2) and `::testLoginWaitBudgetIsTheConstantTheProsePrints`. No new `proc_open` (the paste-back is a client socket); `Support/McpLoginFork.php::arm` keeps its `DescriptorInheritanceGuardTest` row.
- serialized: [lang:cmd.mcp-auth.login] [docs:MCP.md §Server auth + §Authorization-code login] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/Protocol/ServerContext.php] [hot:candy-code/src/Support/McpLoginFork.php]
- deps: C-MCP-6.a (fixes the URL scan this announces and exposes the redirect port), C-MCP-4.b (same §Server auth doc section and lang prefix)
- phase: normal
- ruling: none
- risks: any client with Write scope can start a login child on the server host, the same power as `command.exec` today; state that in docs/MCP.md.

### C-MCP-4.d — `mcp import --write user`
- item: C-MCP-4 (sources: GAP-MCP-15)
- kind: gap · effort S
- approach: `mcp import` is print-only by law (`candy-code/src/Cli/Subcommands.php:2348-2440`, "nothing_written").
  - Opt-in `--write user` appends the translated entries into `~/.candy-code/mcp.json` through `Support/AtomicFileWriter` (0600; the owned-home gate `HomeDirectory::owned()`).
  - A name already present is refused, and the whole write aborts listing the collisions (never a merge).
  - Per-project `~/.claude.json` `projects[<root>].mcpServers` is added as an import source (`McpForeignTranslate::CONTAINERS`).
  - The project tier is never written.
- files.create: `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php`
- files.modify: `candy-code/src/Cli/Subcommands.php` (`mcpImport`, `importUsage`), `candy-code/src/Cli/ParsedArgs.php` (`SUBCOMMAND_FLAGS` gains an `mcp` `--write` entry), `candy-code/src/MCP/McpForeignTranslate.php` (`CONTAINERS` + project-scoped source), `candy-code/lang/en.php` (`cli.mcp.import.*`, `cli.mcp.usage`), `candy-code/README.md` (import block at :2111), `candy-code/docs/MCP.md` (§"Foreign spellings that are read": write mode)
- tests: create `candy-code/tests/Cli/SubcommandsMcpImportWriteTest.php` (writes the user file only; collision refusal writes nothing; mode 0600; an unowned home refuses). Modify `candy-code/tests/Cli/SubcommandsMcpImportTest.php` (the print path is unchanged). Modify `candy-code/tests/Cli/StderrEmitterCensusTest.php` (DIRECT_SITES re-pin for the new lines).
- docs/guards: `candy-code/tests/Support/ReadPathCensusTest.php` row for the new read of the existing user file (or reuse `McpDecision`'s read); `DocFigureProseDriftTest::testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory` (the alias rows stay); the README import block.
- serialized: [headless:Subcommands,ParsedArgs] [lang:cli.mcp.import.] [docs:README.md] [docs:MCP.md §Foreign spellings]
- deps: none
- phase: normal
- ruling: Relax the "import never writes" law for an explicit `--write user` (user tier only, collisions refused)?
- risks: the import adds servers to every future launch with no repo ceremony, which is the user tier's existing posture, so the doc must say so.

### C-MCP-5.a — `mcp serve` core: stdio JSON-RPC host over read-only tools
- item: C-MCP-5 (sources: GAP-MCP-13, p1-miss "MCP server mode")
- kind: gap · effort M
- approach: candy-code cannot act as an MCP server: no server-mode code exists, and `docs/MCP.md:880-890` says `serve` "speaks no MCP".
  - `McpServeHost` runs an NDJSON JSON-RPC loop on stdin/stdout, framed with `SugarCraft\McpClient\McpMessage`. It answers `initialize` (advertising `tools` only), `notifications/initialized`, `ping`, `tools/list` and `tools/call`; everything else gets -32601; EOF exits 0.
  - `McpServeRoster` builds exactly Read, Glob, Grep, Ls and RepoMap through `ToolCatalog::build(ToolBuildContext)` for `--root`, exposing their schemas.
  - Every call goes through the launch's `PermissionGate` and the PreToolUse chain (`ProtectFilesHook` included), built the way `-p` builds its gate.
  - An `Ask` verdict is refused (nobody to ask); exec-class and write-class tools are never listed or callable.
  - Results are text content; tool errors come back as `isError: true`.
  - No session, prompt or memory exposure.
- files.create: `candy-code/src/MCP/Serve/McpServeHost.php`, `candy-code/src/MCP/Serve/McpServeRoster.php`, `candy-code/tests/MCP/Serve/McpServeHostTest.php`
- files.modify: none
- tests: create `candy-code/tests/MCP/Serve/McpServeHostTest.php` (in-memory streams):
  - handshake;
  - `tools/list` holds exactly the five;
  - Read inside root succeeds;
  - Read of `.env` is refused by ProtectFiles;
  - `Bash` is unknown;
  - an unknown method gets -32601;
  - EOF exits.
- docs/guards: `php tools/check-one-type-per-file.php`; no new `proc_open` (Grep's `rg` spawn is an existing site).
- serialized: none
- deps: none
- phase: LATE
- ruling: Confirm scope: stdio only, a read-only roster (Read/Glob/Grep/Ls/RepoMap), Ask → refuse, and no session or prompt exposure?
- risks: the gate's path containment must hold for an external caller with no TUI, so pin a `../` read refusal.

### C-MCP-5.b — `candy-code mcp serve` verb, help, completion, docs
- item: C-MCP-5 (sources: GAP-MCP-13)
- kind: gap · effort S
- approach: The `mcp` verb gate (`candy-code/src/Cli/Subcommands.php:1952-1970`) routes `serve` to `McpServeHost::run()`. The completion roster `SUBCOMMAND_ACTIONS['mcp']` (:2587) gains `serve`. The help screen and `cli.mcp.usage` gain the row. docs/MCP.md §"Serving MCP" is rewritten: `candy-code mcp serve` IS an MCP server, while `candy-code serve` (WebSocket) still is not, plus a client recipe (`{"command":"candy-code","args":["mcp","serve","--root","…"]}`). README's `mcp list|trust|import` line becomes `…|serve`. The subcommand count stays eleven (a verb, not a subcommand).
- files.create: `candy-code/tests/Cli/SubcommandsMcpServeTest.php`
- files.modify: `candy-code/src/Cli/Subcommands.php` (`mcp` verb gate, `SUBCOMMAND_ACTIONS`), `candy-code/lang/en.php` (`cli.mcp.usage`, `cli.help.screen`, `cli.completion.*` if a gloss is keyed), `candy-code/docs/MCP.md` (§"Serving MCP" :880-955, the `mcp list|trust|import` roster at :940), `candy-code/README.md` (:1551)
- tests: create `candy-code/tests/Cli/SubcommandsMcpServeTest.php` (`bin/candy-code mcp serve` as a child: an initialize round trip; EOF exits 0). Modify `candy-code/tests/Cli/HelpTest.php` (the new row).
- docs/guards: `DocFigureProseDriftTest`:
  - `::testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm` (help block rows, the "`candy-code serve` is not one of them" claim, the "eight answer and exit" sentence);
  - `::testTheLoginCommandWordRoutesThroughTheVerbGateAndGuidanceArm` (the `['list','auth','import','trust']` roster pin at :4229 gains `serve`).

  `candy-code/tests/Config/ReadmeRosterDriftTest.php` if the README subcommand line is rostered.
- serialized: [headless:Subcommands] [lang:cli.] [docs:MCP.md §Serving MCP] [docs:README.md]
- deps: C-MCP-5.a
- phase: LATE
- ruling: none (the scope ruling is on C-MCP-5.a)
- risks: `mcp serve` is the first long-running `mcp` verb, so the help line's "each answers and exits except serve" wording needs the qualifier.

---

## Notes for the orchestrator

- **docs/MCP.md is the bottleneck.** 13 of 16 steps edit it, and several DocFigure arms pin its prose, so a lane cannot defer its doc edit to the weld. Each step above names its MCP.md section. I recommend section-disjoint region claims, like Chat.php's. The claims that collide:
  - §Deferred schemas: C-MCP-1.b and C-MCP-6.a (chained).
  - §Server auth: C-MCP-4.b and C-MCP-4.c (chained).
  - §Server requests: C-MCP-3.b, 3.c and 3.d (chained).
  - §Prompts: C-MCP-2.a and 2.b (chained).

  Other areas' MCP.md edits to schedule around: C-SEC-3 (§Prompts trust sentence), C-SEC-5 (pin re-approval), C-SEC-8 (result fence), C-AGT-3 (preset scope).
- **Hot files across areas:**
  - `candy-code/src/MCP/McpClient.php`: C-MCP-1.a, 2.b, 3.a, 3.c and 4.b, plus C-AGT-3.
  - `candy-code/src/Host/SessionHost.php`: C-MCP-2.a, 2.b and 4.c, plus C-SEC-4, P-SESSION-TABS and C-SES-*.
  - `candy-code/src/Tools/McpToolBridge.php` and `candy-code/src/Tools/BuiltIn/McpCallTool.php`: C-MCP-3.b and 3.d, plus C-SEC-8 and any MCP-image leg.
  - `candy-code/src/Backend/EngineBackend.php`: C-MCP-3.b and 3.d.
  - `candy-code/src/Hooks/HookRegistry.php`: C-MCP-1.b, plus C-EXT-1 and C-EXT-6.
  - `candy-code/src/Permissions/PermissionRule.php`: C-MCP-1.b, plus C-SEC-6 and C-SEC-7.
  - `candy-code/src/Tools/BuiltIn/McpListTool.php`: C-MCP-6.a, plus C-AGT-3.
  - `candy-code/src/Protocol/Methods/CommandMethods.php`: C-MCP-2.b, plus C-SEC-4.
  - `candy-code-web/src-web/components/panels/ContextPanel.vue`: C-MCP-4.a, plus P-CTX-BREAKDOWN and C-SEC-5.
  - `candy-code-web/src-web/components/Composer.vue` and `views/SessionView.vue`: C-MCP-2.c.
  - `sugar-mcp-client/src/StdioMcpServer.php`: C-MCP-1.a and 3.a.
- **Protocol lanes:** only C-MCP-4.a and C-MCP-2.b. C-MCP-3 deliberately reuses the AskUser ask shape, so elicitation needs no protocol change. Put C-MCP-4.a in an early wave: P-CTX-BREAKDOWN consumes its user-tier rows and liveness.
- **Wave 0 candidates (no deps):** C-MCP-1.a and C-MCP-1.b (security), C-MCP-4.a (protocol), C-MCP-4.b. C-MCP-6.a follows 1.b; C-MCP-2.a follows C-SEC-3.
- **Rulings:** C-MCP-3.d (background stdio holds the exchange lock), C-MCP-4.d (never-write import), C-MCP-5.a (serve scope).
- **Possible follow-up:** if the owner wants one multi-field elicitation FORM rather than a short series of AskUser-shaped questions, that is an extra protocol lane (`PENDING_ASK.elicitation` schema + `permission.respond` content) plus a TUI form modal in Chat. I did not design it as a step, to keep C-MCP-3 to four steps.
- **Design-pass findings beyond the sources (verified by reading source, not executed):**
  - (a) Deferred `mcp_call` also escapes the built-in `ProtectFilesHook` (matcher `^(…|mcp__.*)$`, `src/Hooks/BuiltIn/ProtectFilesHook.php:430`). C-MCP-1.b covers it.
  - (b) `McpLoginFork` announces the SERVER URL instead of the authorize URL on every real login, because `OAuthLoopbackFlow.php:158` prints the server URL in backticks first and `McpLoginFork.php:120` takes the first match. C-MCP-6.a covers it; consider MAJOR (functional).
- **Not verified:**
  - that the serve process always holds a memoised MCP client before `mcp.resources` or liveness is asked. Reasoned from `Bootstrap::mcpToolShape()`/`mcpTools()` at the workspace tool build, not traced end to end. Both readers fall back honestly (`not-started` / null).
  - that VIS-3-P1 is already gone: grep 0 on the uncommitted rewritten COMPARISON.md.
  - the audit's `AtomicJsonFile` does not exist. The real writer is `candy-code/src/Support/AtomicFileWriter.php`.
- **New candy-code test files for the weld's durations TSV (14):**
  - `tests/MCP/StdioMcpUserTierCwdTest.php`
  - `tests/Hooks/McpCallHookParityTest.php`
  - `tests/MCP/SseMcpServerForkTlsTest.php`
  - `tests/Protocol/ContextSourcesMcpUserTierTest.php`
  - `tests/Host/SessionHostMcpSurfaceTest.php`
  - `tests/Protocol/CommandMethodsMcpPromptsTest.php`
  - `tests/Protocol/McpMethodsTest.php`
  - `tests/MCP/McpServerRequestsTest.php`
  - `tests/MCP/McpElicitationTest.php`
  - `tests/Tools/McpToolProgressTest.php`
  - `tests/Cli/NonInteractiveMcpToolsHeadlessTest.php`
  - `tests/MCP/HttpMcpServerGetStreamTest.php`
  - `tests/MCP/SseMcpServerServerRequestTest.php`
  - `tests/MCP/McpBackgroundCallTest.php`

  Then three more:
  - `tests/MCP/McpRuntimeDisableTest.php`
  - `tests/Commands/McpAuthCommandEnableDisableTest.php`
  - `tests/Host/McpLoginServedTest.php`

  Plus `tests/Cli/SubcommandsMcpImportWriteTest.php` and, LATE, `tests/MCP/Serve/McpServeHostTest.php` and `tests/Cli/SubcommandsMcpServeTest.php` (all under `candy-code/`). The sugar-mcp-client tests (`StdioMcpServerCwdTest.php`, `StdioMcpServerServerRequestTest.php`) and the web spec do not go in the TSV.
