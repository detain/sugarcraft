# REVIEW-1 — C-AGT-3.a (cc/C-AGT-3.a @ b3082a646, base b24e81154)
[lead-persist: reviewer sandbox static-only (phpunit+writes denied, git allowed); delivered verbatim @1006@. Weld re-runs --filter 'McpToolShape|McpDispatcher|AgentManager|Mcp' (builder claim 1607T/9127A) + five-guard pair-only; DUR row duty: tests/Agents/McpDispatcherPresetScopeTest.php; docs/MCP.md ~:627-640 "setAgentPreset has no production caller" now-false sentence = weld/closeout doc collection (serialized file, out of lane).]

## Axis results — all PASS
1. Scope enforcement end-to-end: McpCallTool::dispatch :129+ refuses at EXECUTE time on scoped instance (findDispatched null → isError "limited to the deferred roster"), then dials shape->client->callTool (preset-bound clone). Double enforcement: McpClient::callTool :968-993 → isServerAllowed :1000-1007, preset branch precedes inherited unrestricted=true. Bypass hunt closed: Runtime::executeToolCalls parent-side only; findTool Runtime.php:4299-4301 scans $app->tools built from turnTools() over $this->tools bound at TaskTool.php:1964 ->withTools($tools) from GRANTED (scoped) list — execution resolves by granted instance not registry name. Fork children rehydrate DATA-ONLY doubles whose execute() throws (E647, AgentManager.php:1661-1662 comment true) — provider advertisement only. McpCallTool not ParallelSafe → barrier → sequential in-process; AcceptsHeartbeat threads heartbeat as argument, never re-wraps, scoped shape survives turnTools mapping.
2. List mirrors call: both through McpToolShape::scopedTo dispatch-half; McpListTool prints frozen descriptors; no list-of-refusable / hide-of-allowed asymmetry.
3. Shallow-clone safety: clone shares McpServer objects by handle (assertSame pinned; stdio children never duplicated); private arrays by value; nothing on scoped path calls startServers(); launch client provably untouched (test 6).
4. Router per call: McpRouter rebuilt per call (McpClient.php:1248-1251), caches nothing.
5. Both grant paths: silent :1534 + declared :1671 route scopeDispatchersFor :1765; mcpServers===[] preserves identity (test 5).
6. Non-vacuity: 8 new cases assert real behavior; red-before math consistent (tests 1,3,4,6 fail under src-stash).
7. Scope/law: file set == declared; docs/AGENTS.md in-step; docs/MCP.md untouched (seam honored); generated files absent from diff; author detain/detain zero trailers; conventions hold.
Empty-allowlist semantics consistent (McpRouter::serverAllowed :211 / resolveAllowedServers :71/:89 empty=allow-all) and unreachable from grant path (early passthrough).

## VERDICT: CLEAN (no step-local findings)
[NIT out-of-scope] McpToolShape::scopedTo eagerDescriptors pass through unfiltered — harmless today (AgentManager filters eager bridges independently :1529/:1666); one-line docblock note suffices (record).
[MINOR out-of-scope] docs/MCP.md ~:627-640 now-false sentence — disclosed builder seam, weld doc duty.
