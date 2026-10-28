# BRIEF C-MCP-2.b — `command.list`/`command.exec` carry MCP prompts; `mcp.resources` method

area: mcp  item: C-MCP-2 (sources: GAP-MCP-07, GAP-CMD-09, CLAW-new#9)  kind: dormant-wire · effort M  wave:   serialized: [protocol] [hot:candy-code/src/Host/SessionHost.php] [hot:candy-code/src/MCP/McpClient.php] [hot:candy-code/src/Protocol/Methods/CommandMethods.php]
deps: C-MCP-2.a

## approach
`CommandMethods::list/exec` read only `workspace->commandLoader->loadAll()` (`candy-code/src/Protocol/Methods/CommandMethods.php:49-62, 66-91, 127-138`), so served MCP prompts are neither listed nor executable via `command.exec` (→ `command_not_found`). The fix:
- `command.list`/`exec` read the session host's command map (new `SessionHost::customCommands()` accessor);
- MCP rows carry `source: "mcp"`: the `command.list` `source` enum in `MethodSchemas.php:263-269` grows `mcp`;
- a new `mcp.resources` (Scope::Read, `sessionId`) answers the launch-frozen per-server resource rows (uri, name, mimeType, bounded description). `McpClient::primeSurfaceRosters()` keeps bounded rows instead of a count (counts stay derived for `McpPanel`);
- `mcp.resources` reads the memoised client only (`{status: "not-started"}` otherwise), so a popup never launches servers.
The web `/` popup then lists MCP prompts with no web change; the composer picker is C-MCP-2.c.

## files.create
`candy-code/src/Protocol/Methods/McpMethods.php`, `candy-code/tests/Protocol/CommandMethodsMcpPromptsTest.php`, `candy-code/tests/Protocol/McpMethodsTest.php`

## files.modify
`candy-code/src/Protocol/Methods/CommandMethods.php` (`list`, `exec`, `files`), `candy-code/src/Protocol/Dispatcher.php` (`methods()` roster), `candy-code/src/Protocol/Schema/MethodSchemas.php` (`command.list` source enum; `mcp.resources`), `candy-code/src/Host/SessionHost.php` (public `customCommands()`), `candy-code/src/MCP/McpClient.php` (`primeSurfaceRosters`, new `resourcesRoster()`), `candy-code/docs/protocol/candy-code.v1.schema.json` (regen), `candy-code/docs/SERVER.md` (regen + method prose), `candy-code-web/src-web/protocol/generated.ts` (regen), `candy-code/docs/MCP.md` (§"Prompts as slash commands": web popup sentence; §Resources: `mcp.resources`)

## tests
create `candy-code/tests/Protocol/CommandMethodsMcpPromptsTest.php` (an MCP row listed with `source: mcp`; `command.exec` admits it; a disk file shadows it). Create `candy-code/tests/Protocol/McpMethodsTest.php` (rows from a memoised fake client; `not-started` without one; denied servers absent). Modify `candy-code/tests/Server/ConformanceTest.php` if it enumerates the method roster.

## docs/guards
protocol regen; `ProtocolSchemaDriftTest`, `ServerProtocolDocumentationDriftTest`; `candy-code/tests/Tui/McpPanelTest.php` (the resources count still renders).

## risks
C-SEC-4 also edits served command routing (CommandMethods trust); land in different waves and rebase on whichever lands first.

## ruling
none
