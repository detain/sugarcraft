### C-AGT-3.a — Preset `mcpServers` scopes `mcp_call`/`mcp_list`
- item: C-AGT-3 (sources: GAP-MCP-11, k2-dorm (`setAgentPreset`), k2-sum (MCP-11 ✅→🟡))
- kind: dormant-wire · effort S
- approach: Today `AgentManager::resolveGrantedTools()` (`src/Agents/AgentManager.php:1496-1580`) filters `McpToolBridge`s by `mcpServers`, but the deferred dispatchers `McpCallTool`/`McpListTool` keep the launch `McpClient`. That client is built unrestricted (`Bootstrap.php:8015`), and `McpClient::setAgentPreset()` (`src/MCP/McpClient.php:178`) has no caller, so a scoped sub-agent can `mcp_call` any server. Wire the dormant setter without mutating the shared client:
  - `McpClient::forAgentPreset(AgentPreset): self` clones and calls `setAgentPreset()`; servers stay shared by handle;
  - `McpToolShape::scopedTo(array $mcpServers): self` uses that client and filters `dispatchedDescriptors` with `McpRouter::serverAllowed()`;
  - `McpCallTool::scopedTo()` and `McpListTool::scopedTo()` rebuild from the scoped shape.
  - In `resolveGrantedTools()`, when `$agent->mcpServers !== []`, swap each dispatcher for its scoped copy on both the declared and the silent-tools path.
  - Lane must verify the parent-side executor for fork children dispatches through the granted instances (`refuseCallOutsideGrant` region). If it looks tools up by name, apply the scope there too.
- files.create: none
- files.modify: `candy-code/src/MCP/McpClient.php` (`forAgentPreset`), `candy-code/src/MCP/McpToolShape.php` (`scopedTo`), `candy-code/src/Tools/BuiltIn/McpCallTool.php` (`scopedTo`), `candy-code/src/Tools/BuiltIn/McpListTool.php` (`scopedTo`), `candy-code/src/Agents/AgentManager.php` (`resolveGrantedTools`), `candy-code/docs/AGENTS.md` (mcpServers now scopes the dispatchers)
- tests: create `candy-code/tests/Agents/McpDispatcherPresetScopeTest.php` (a preset with `mcpServers: [docs]` cannot `mcp_call` server `git`; `mcp_list` lists only `docs`; a preset with no `mcpServers` is unchanged; the parent's client is unscoped after the child runs); modify `candy-code/tests/MCP/McpToolShapeTest.php` (`scopedTo` filter)
- docs/guards: `docs/AGENTS.md`. No roster guard moves.
- serialized: [hot:candy-code/src/Agents/AgentManager.php] [docs:AGENTS.md]
- deps: none
- phase: normal
- ruling: none
- risks: `clone` of `McpClient` must not duplicate stdio children or the lazy router state. The lane checks for `__clone` needs, since the router caches the server map.

