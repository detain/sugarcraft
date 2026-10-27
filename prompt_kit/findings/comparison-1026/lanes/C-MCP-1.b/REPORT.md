# C-MCP-1.b — DONE, committed af1eb01e6 on cc/C-MCP-1.b (base 351726864)

Deferred mcp_call now keeps mcp__* hook matchers and exact argument-free Allow
grants; globs, argument-scoped allows and Allow mcp_call stay literal.

Files (9): src/Hooks/HookRegistry.php (findMatches + optional $toolArgs dual
subject, threaded via executeHooks/chainBudgetSeconds/scan),
src/Permissions/PermissionRule.php (exact-Allow leg above the blanket Allow
return + MCP-7 comment truth-flip), src/Tools/BuiltIn/McpCallTool.php
(docblock disclosure), docs/MCP.md (hooks + grants paragraphs), docs/HOOKS.md
(matcher-delimiters dual-subject note), docs/PERMISSIONS.md (translation
sentence + outrank .env row), tests/Hooks/HookRegistryTest.php (signature pin),
tests/Permissions/McpDispatchParityTest.php (allow test flipped),
tests/Hooks/McpCallHookParityTest.php (NEW).

Red-before-fix proven: HookRegistry leg neutered → McpCallHookParity 3/5 red
(cases 1-3, dispatcher/subjects cases stayed green); PermissionRule leg
neutered → flipped allow test red at its exact-Allow-args assertion. cp+md5
restores both (0c873178… / ed09200b…).

Gates (cwd candy-code, plain pipe, -u LINES -u COLUMNS):
- task+docs battery 'McpCall|McpDispatch|HookRegistry|ProtectFiles|McpHook|
  PermissionsIntro|TrustKeyDocumentation|ReadmeRoster|GlobFigure|
  HookEnvironment|ScriptHook|ClaudeSettingsHooks': 598T/4991A OK (pre+post commit).
- five-guard: 133T/15341A, only-reds = expected staleness pair
  (pinned 25,537 vs live 25,624 — gate owes re-pin; lane figure incl. ~87 inherited).
- 2 PHPUnit Deprecations = pre-existing house noise.

GATE DUTY: new file candy-code/tests/Hooks/McpCallHookParityTest.php needs a
scripts/parallel-tests-durations.tsv row; suite-figure.json re-pin at closeout.
No forbidden files staged; not pushed. Doc-guard check: greps for every replaced
sentence = 0 test pins; DocFigure's `mcp__git__*` needle preserved.
