# C-MCP-2.b — command.list/exec carry MCP prompts; mcp.resources

VERDICT: DONE. command.list/exec now read the session host's merged command map (disk + MCP, disk-wins), `mcp.resources` serves the launch-frozen resource roster from the memoised client only, source enum grew `mcp`.

## Files
- new src: candy-code/src/Protocol/Methods/McpMethods.php (Scope::Read; memo-peek via McpDecision::mcpConfigMemoKey + LaunchContext; never builds/starts; per-row bounding 512/200/128/400, control-run flatten + house ellipsis).
- mod: CommandMethods.php (list/exec over SessionHost::customCommands(); source ternary via McpPromptCommands::isMcpPrompt; files() seam retired — exec/list parity now structural), Dispatcher.php (McpMethods::register after CommandMethods), MethodSchemas.php ('mcp' enum + mcp.resources entry order-matched), SessionHost.php (customCommands() accessor), McpClient.php (SURFACE_RESOURCE_ROWS_KEPT=200 slice at prime; resourcesRoster(); startedSnapshot keeps int via \count — McpPanel contract unchanged), docs/MCP.md (web-popup sentence; §Resources mcp.resources paragraph; no campaign-ID jargon).
- new tests: tests/Protocol/CommandMethodsMcpPromptsTest.php (6), tests/Protocol/McpMethodsTest.php (4).
- in-step: ConformanceTest + ProtocolSchemaDriftTest + ServedProjectCommandTrustTest command.list legs now pass sessionId (method became session-scoped); trust-test docblock names the host-map seam.

## Gates (plain-pipe, cwd candy-code, against REGENERATED trio)
- regen: php scripts/gen-protocol-schema.php --write + npm run gen:protocol; then trio restored before commit (welder regenerates).
- Protocol dir + ServerProtocolDocumentationDrift + ServedProjectCommandTrust + Conformance: 38T/459A OK.
- McpPanelTest|McpPromptCommandsTest|McpClient: 179T/671A OK, 1 skip.
- DocFigureProseDrift|GlobDialect|ReadmeRosterDrift|StdinReaderCensus: 114T/4414A OK.
- New files: 10T/89A OK; DuplicatedTestHelperDrift green after FETCHED_MARK→SERVED_FETCH_MARK rename (collided with C-MCP-2.a's).
- Mutations: M1 force source 'file' → exactly test1 red; M2 drop clip → bounded-roster red; M3 drop slice → slice test red only (StartedSnapshot family stayed green — int contract).
- Base-reds disclosed, not mine (files byte-clean at 4b771634d): StderrEmitterCensusTest x3 (ArgvParser 35→36, NonInteractive 10→11/14→15 — from C-AGT-7.b/C-MCP-2.a lineage), TreeWideGuardRosterTest x2 (JsonSchemaEnvelopeTest, MemoryRecallBlockTest walkers). Gate owes: 2 durations rows (new files above); suite-figure re-pin (+10T).

## Disclose
- command.exec MCP leg runs the real stdio fixture in-test: the fork child re-derives Bootstrap::mcpClient under a new pid (memo is pid-keyed), so the test seeds BOTH the memo fake (list/roster legs) and a trusted project .mcp.json fixture (exec materialisation leg).
- dormantRoster.spec.ts not addable (file owned by C-WEB-3.g, doesn't exist at base); mcp.resources has no web caller in this step.
- No Lang keys added (wire answers are machine rows); no composer/dep touches.
