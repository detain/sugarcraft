# BRIEF — C-MCP-3.b (wave 16)

## Wire server requests into turns (ask channel, progress, headless)
- area: mcp · kind: gap · effort M · effort:  · phase: normal
- item: C-MCP-3 (sources: GAP-MCP-08, OC-new#1, CC-new#2)
- deps: C-MCP-3.a, C-MCP-2.a (owner order C-MCP-2 → C-MCP-3: the served path carries the same provider)
- serialized: [headless:NonInteractive] [lang:mcp.elicitation.] [docs:MCP.md §Server requests (new)] [hot:candy-code/src/Backend/EngineBackend.php] [hot:candy-code/src/Tools/McpToolBridge.php]
- ruling: none

## Files to create
`candy-code/tests/Tools/McpToolProgressTest.php`, `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php`

## Files to modify
`candy-code/src/Tools/McpToolBridge.php` (interfaces, `executeWithHeartbeat`), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (same), `candy-code/src/Tools/BuiltIn/AskUserTool.php` (hoist the reply reader), `candy-code/src/Backend/EngineBackend.php` (`turnTools` binding arms), `candy-code/src/Cli/NonInteractive.php` (`withoutInteractiveUser`), `candy-code/lang/en.php` (`mcp.elicitation.*`), `candy-code/docs/MCP.md` (new §"Server requests": ping, roots, elicitation as questions, progress, what is refused)

## Tests
create `candy-code/tests/Tools/McpToolProgressTest.php` (progress becomes a ToolProgress frame; an elicitation reaches the bound approver and the accepted content returns to the server). Create `candy-code/tests/Cli/NonInteractiveMcpToolsHeadlessTest.php` (a `-p` elicitation is cancelled and the model is told why). Modify `candy-code/tests/Tools/BuiltInToolCorpus.php` if it pins `McpCallTool`'s interface set.

## Docs / guards
the `LangParityTest` keys (prefix `mcp.elicitation.`); the new MCP.md section gets no DocFigure arm unless the lane adds one (recommended: pin the advertised capability set against `McpServerRequests`).

## Approach
MCP tools never see the turn's approver: `EngineBackend::turnTools()` binds `RelaysPermissionAsks` only inside the `DelegatesToEngine` arm (`candy-code/src/Backend/EngineBackend.php:3209-3283`), and `McpToolBridge` is `Tool, AcceptsHeartbeat` only (`candy-code/src/Tools/McpToolBridge.php:88`).
- `McpToolBridge` and `McpCallTool` implement `RelaysPermissionAsks` and `AcceptsToolProgress`, and build a per-call `McpServerRequests` from the bound approver and progress emitter.
- `turnTools()` binds the approver in the `AcceptsToolProgress` arm and the plain-tool arm too, still excluding `TaskTool`. `Runtime::executeConcurrently()` already relays `RelaysPermissionAsks` members (`candy-code/src/Runtime.php:2568`), so Runtime is untouched.
- An elicitation reuses `AskUser`'s ask shape (the same `ToolCall` and `HookResult::ask`, question text "MCP server `<name>` asks: …"), so the TUI 1.C modal and the web PermissionCard render options with no UI change. `AskUserTool`'s reply reading is hoisted into a public static for reuse.
- Progress frames become `ToolProgress` tails (web `tool.progress`, TUI running row).
- `-p`: `NonInteractive::withoutInteractiveUser()` also closes MCP tools, so elicitations answer `cancel`.

## Risks
a multi-field form becomes a short series of questions rather than one form (see Notes); C-SEC-8 also edits `McpToolBridge`/`McpCallTool` (result fence), so serialize.
