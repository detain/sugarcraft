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

