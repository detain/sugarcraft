# C-AGT-3.a — Preset `mcpServers` scopes `mcp_call`/`mcp_list` — CLOSED

- commit: `b3082a646967d12cb71356cb99e2929bc6a46a58` on `cc/C-AGT-3.a` (base b24e81154, never pushed; author/committer detain@interserver.net verified, zero trailers)

## What landed (dormant-wire, per design block)
- `src/MCP/McpClient.php`: `forAgentPreset(AgentPreset): self` — clone + the dormant `setAgentPreset()`, its first production caller. Shallow clone shares the `servers` map BY HANDLE (stdio children never duplicated); arrays copy; `router()` is built fresh per call so no cached server-map state rides the clone; the clone never starts servers. No `__clone` needed (verified).
- `src/MCP/McpToolShape.php`: `scopedTo(array $mcpServers): self` — synthetic `AgentPreset` routed through `forAgentPreset`, `dispatchedDescriptors` frozen with the same `McpRouter::serverAllowed()` law the bridge filter uses (raw list passed through — identical throw semantics); eager half + lazy flag pass through.
- `src/Tools/BuiltIn/McpCallTool.php` / `McpListTool.php`: `scopedTo()` rebuild from the scoped shape (name/schema byte-identical).
- `src/Agents/AgentManager.php`: `resolveGrantedTools()` swaps each dispatcher for its scoped copy via new private `scopeDispatchersFor()` on BOTH the declared and the silent-tools path when `$agent->mcpServers !== []`; identity preserved when empty.
- `docs/AGENTS.md`: the `mcpServers` paragraph now states the allowlist narrows bridges AND the `mcp_list`/`mcp_call` dispatchers, shared launch client untouched.

## Executor finding (brief's verification ask)
Both `resolveGrantedTools()` consumers dispatch through the granted INSTANCES: `grantedToolsFor()` feeds the in-process TaskTool roster, and the worker-pool path encodes name+specs while the fork child runs nothing itself (E647) — the PARENT-side executor executes the granted objects. No by-name registry lookup on the grant path, so no extra scoping site was needed. `grantRefusalFor()` scans the registry for `McpToolBridge` by wire name; `mcp_call`/`mcp_list` are not bridges → no change there; enforcement lands via the scoped instances (frozen roster refusal + preset-branch `callTool`).

## Tests
- CREATED `candy-code/tests/Agents/McpDispatcherPresetScopeTest.php` — 6T/33A: preset-[docs] `mcp_call` git refused via frozen roster; allowed docs call runs on the SHARED server handle; `mcp_list` shows only docs; silent path swaps too; empty `mcpServers` → registry identity; parent client unscoped after child runs (agentPreset null, both servers visible; scoped clone throws 'not allowed for this agent').
- MODIFIED `candy-code/tests/MCP/McpToolShapeTest.php` — +2T: dispatched-only filter + untouched launch shape; `scopedTo([])` allow-all view.
- RED-before-fix proven: `git stash push -- src/` → 4 of the new pins fail; restored.
- Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS):
  - `--filter 'McpToolShape|McpDispatcher|AgentManager|Mcp'` → OK: Tests: 1607, Assertions: 9127, (2 pre-existing AccessorDeprecationTest provider deprecations, 1 pre-existing skip).
  - five-guard `--filter 'ReadmeSuiteFigureDrift|DocFigureProseDrift|GlobDialectDifferential|ChildWallClockBudget|DuplicatedTestHelperDrift'` → 133T, ONLY the staleness pair red (25,537 pinned vs 25,962 live — wave-wide drift incl. this lane's +8T; weld/gate re-pin duty).

## DURATIONS duty (gate)
NEW test file needing a `scripts/parallel-tests-durations.tsv` row: `candy-code/tests/Agents/McpDispatcherPresetScopeTest.php` (~0.1s class). No other new test files.

## Seam for weld collection (out of this lane's ownership — [docs:MCP.md] serialized elsewhere)
`candy-code/docs/MCP.md` ~:627–640 claims the client-side preset arm "still has no production caller: per-preset mcpServers allowlists are enforced one layer up instead… drops bridges…". The bridge half stays true; the "no production caller" clause is now false (`forAgentPreset()` calls it). No guard pins the sentence (grep 'production caller' across tests/Config + tests/MCP: zero hits). Needs a one-sentence flip in whichever step owns the docs/MCP.md serialization slot.
