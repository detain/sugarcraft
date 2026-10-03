# Impact: wave0-engine

Scope: Wave 0 engine/provider/tool items 0.1, 0.2, 0.4, 0.5, 0.10, 0.13, 0.14, 0.15, 0.16 plus Part II #31 (X-31). Verified against `sugar-crush` master @ `574e4cccb`. Line numbers are current (Runtime 4569, EngineBackend 2941, SglangProvider 3482, Bootstrap 9219, Chat 19220, Renderer 6000 lines).

## Summary table

| ID | Status | Size | Depends on | Hotspot regions (file::method :lines) | Other files modified | New files (proposed, incl. tests) | Docs + drift tests forced | Cross-lib suites |
|---|---|---|---|---|---|---|---|---|
| 0.1 | OPEN | S | none. Land before **1.A** or inside it (same method) | `SglangProvider::buildParams` ~:1660-1760 (pass `$request->model` into the `formatMessages` call at :1688); `SglangProvider::formatMessages` :2316-2360 (AssistantMessage arm: add `reasoning_content` when `toolCalls` is non-empty and the family flag is on) | `src/Providers/CustomProvider.php::formatMessages` :639-660 (optional: same field behind a config flag) | `tests/Providers/SglangProviderReasoningReplayTest.php` | none. **Live check:** `cached_tokens` before/after on skynet2 (SGLang needs `--enable-cache-report`) | — |
| 0.2 | OPEN | S | none | `Runtime::runStreaming` yield :1573 and `Runtime::runBatch` yield :1674 (rewrite ids **before** the AssistantMessage is yielded and before `executeToolCalls`, so history, events and results all agree); `Runtime::__construct` :744 (allocator per Runtime = per turn) | `src/Tools/ToolCall.php` (add `withId()`); `src/Messages/HistorySanitizer.php` :80-160 (optional: de-dup legacy duplicate ids in resumed `SuspendedDelegations` transcripts) | `src/Support/ToolCallIdAllocator.php` (`tc_<turnNonce>_<seq>`; rewrites empty, `dsml_call_N`, `minimax_xml_call_N` and ids repeated within the turn; keeps real server ids); `tests/Support/ToolCallIdAllocatorTest.php`; `tests/Runtime/ToolCallIdUniquenessTest.php` | none (parsers `DsmlToolCallParser` :399 and `MinimaxXmlFallbackToolCallParser` :267 can stay as they are) | — |
| 0.4-a | PARTIAL (`runCaptured($timeoutSeconds)` + `terminateGroup` 15→9 on the setsid group already exist, audit 15d-14; Bash passes no timeout) | S | none | — | `src/Tools/BuiltIn/Bash.php` `inputSchema` :170-191 (`timeout` seconds, default 120, max 600, clamped), `execute` :193-252 (pass to `runCaptured`/`runCapturedInteractive`; on `timedOut` add a "timed out after N s" line), `description` :81-110; `src/Tools/Concerns/CapturesProcessOutput.php::runCapturedInteractive` :387 (add a wall deadline next to its idle ceiling) | `tests/Tools/BuiltIn/BashTimeoutTest.php` | Bash `description()` text. README "Capabilities" :1292 if it lists Bash params. Settings **N-P4c** adds `tools.bash.timeoutSeconds` later; reconcile on 120/600, not the design's 300 | — |
| 0.4-b | OPEN (parallel groups already beat, `Runtime::executeConcurrently` :2251-2254; sequential tools are silent) | S-M | 0.4-a (the same select loop) | `Runtime::executeToolCalls` :1772-1800 (pass `$heartbeat` into the sequential arm as well); `Runtime::executeSequentially` :1870-1983 (bind the beat into a tool that `AcceptsHeartbeat` around `execute()` :1975); `EngineBackend::turnTools` :1537-1586 (the 1 s throttled beat closure is already built here; reuse it as the source) | `CapturesProcessOutput::runCaptured` select loop :208-241 (call the beat on each 200 ms slice); `Bash.php`, `Grep.php`, `src/Tools/McpToolBridge.php` (implement the interface); `sugar-mcp/src/StdioMcpServer.php::callTool` :496 / `readResponse` :792 (optional `?\Closure $onWait`) | `src/Tools/AcceptsHeartbeat.php`; `tests/Runtime/SequentialToolHeartbeatTest.php` (pcntl: a 130 s-silent fake tool survives the 120 s idle ceiling via a scaled clock, see `ScaledClockHelperSeamTest`) | `docs/ARCHITECTURE.md` "### `EngineBackend` forks" :194 (idle ceiling now covers sequential tools) | **sugar-mcp** (if the `onWait` seam lands) |
| 0.5 | OPEN (no result cap on the bridge; `callTool` has no deadline, by design E646) | S-M | none (shares the sugar-mcp `callTool` signature edit with 0.4-b, so **one owner**) | — | `src/Tools/McpToolBridge.php` `execute` :402-428 + `renderContent` :592-628 (`use TruncatesOutput`, 64 KiB cap with marker); `src/MCP/StdioMcpServer.php` ctor :64-79 + `callTool` :169 (pass `toolTimeoutSeconds`); `src/MCP/McpClient.php::buildServer` :407-450 (read per-server `toolTimeout`); `src/MCP/McpTrustPins.php` `UNPINNED_KEYS` :57 (add `toolTimeout`, or editing it re-prompts trust); `sugar-mcp/src/StdioMcpServer.php::callTool` :496 (optional `?float $timeoutSeconds` → deadline into the existing `request()` :559, plus `notifications/cancelled` on expiry) | `tests/Tools/McpToolBridgeResultCapTest.php`; `tests/MCP/StdioMcpServerToolTimeoutTest.php`; `sugar-mcp/tests/CallToolDeadlineTest.php` | `docs/MCP.md` trust-pin list :68, `.mcp.json` table :128, transports table :169; README :1321 ("a `tools/call` is deliberately **not** bounded") must be rewritten; check `tests/Config/DocFigureProseDriftTest.php` :522-533 (it pins MCP.md figures against constants) | **sugar-mcp** |
| 0.10 | OPEN | S | none. Land **before** 1.C-3, 1.C-4, P-B1 and P-D1, which all edit the same step loop | `EngineBackend::runTurn` :1033-1377, the step-loop exit `if ($toolResults === [])` :1207-1210 (empty or reasoning-only reply gets one `UserMessage` nudge and `continue`; a fully empty reply is re-requested up to ×2 without appending). It must still pass the spend-cap check :1212-1250 before any extra call | none | `tests/Backend/EngineBackendEmptyReplyTest.php` | README "## The agent loop" :1273; `docs/ARCHITECTURE.md` "## `Runtime` — the agentic loop" :212 | — |
| 0.13-a | OPEN (the `SessionAffinity` trait + `X-SugarCrush-Session` header ship dormant) | S | none | `EngineBackend` new `withSessionId()` among the withers :556-893; `EngineBackend::runTurn` App build :1082-1097 (`->withSessionId(...)`; today hooks on the engine path get `sessionId: ''`, Runtime :3050/:3064); `Runtime::run` CompleteRequest :1294-1311 (add `sessionId`); `Chat::scheduleBackendCompletion` :11393-11410 (set it per turn beside `withSpendCap`, so `/resume`, `/branch` and Ctrl+Tab are followed); `Bootstrap::backendFor` :3113 (optional `?string $sessionId` for the `-p` path) | `src/Providers/CompleteRequest.php` (`?string $sessionId`); `src/Providers/Concerns/SessionAffinity.php` (request id wins over the ctor id); `SglangProvider` post sites :1122/:1166/:1587 and `CustomProvider` :287/:366 (pass the request) | `tests/Backend/SessionAffinityWiringTest.php` | `docs/PROMPT_ENGINEERING.md` "## Session affinity — dormant-id state" :234 (rewrite); the SessionAffinity trait docblock "CONSUMER CONTRACT"; `tests/Backend/EngineBackendWitherPreservesStateTest.php` (new wither) | — |
| 0.13-b | PARTIAL (`Renderer::cacheIndicator` :2507-2555 exists but needs `promptTokens()`, which is null on every OpenAI-shaped provider because `cacheCreationTokens` is always null: `SglangProvider::parseUsage` :2545, `CustomProvider::parseUsage` :780) | S | none. Coordinate with **O-2c** (same meters) | `Renderer::cacheIndicator` :2507-2555 (fallback `read/(read+input)` when creation is unreported but read and input are reported; do **not** change `Usage::promptTokens()`, which E17 calibration reads) | `src/Usage.php` (optional `cacheHitShare(): ?float` helper) | extend `tests/Renderer/StatusLineSegmentTest.php` | README "### What you see while a turn runs" :1095 (if it describes the status bar) | — |
| 0.14-a | OPEN | S | none | — | `src/Context/PromptFence.php::escape` :232-255 (strip U+E0000–U+E007F). Recommended: also `Runtime::utf8Safe` (via `resultMessage` :2688), so MCP and WebFetch results are cleaned at the one producer seam | `tests/Context/PromptFenceUnicodeTagTest.php` | none (golden prompt bytes are unchanged for clean payloads; run `BaseSystemPromptTest`) | — |
| 0.14-b | OPEN, **design conflict** (`ProcessContainment::env()` :234 stays unscrubbed for MCP/LSP by documented decision, docblock :256-259) | S | none | — | `src/Support/ProcessContainment.php` (new `mcpEnv()` = `scrubbedEnv()` + the server's explicit `env` block applied last); `src/MCP/StdioMcpServer.php::spawnPlan` :99-113 (use it). `McpClient::resolveEnv` :645 only expands `${VAR}` and needs no edit | `tests/MCP/StdioMcpEnvScrubTest.php` | `docs/MCP.md` "### `${VAR}` interpolation" :249 (say that inherited secrets must now be declared); `docs/ENVIRONMENT.md` `secretEnvAllowlist` row; the ProcessContainment docblock | — |
| 0.14-c | PARTIAL (`.env`, `.env.*` and `.envrc` are already read+write denied) | S | none. Same constant block as **0.8** | — | `src/Hooks/BuiltIn/ProtectFilesHook.php` `DEFAULT_PROTECTED_PATTERNS` :80-85 (add `*.pem`, `*.key`, `id_rsa*`/`id_ed25519*` with no `.pub`) | extend `tests/Hooks/ProtectFilesHookTest.php` | `docs/PERMISSIONS.md` "`ProtectFilesHook` still refuses" table :491-501 (new row) | — |
| 0.15 | OPEN | S | 0.14-a (soft: reuse `PromptFence::escape` once it also strips tags) | — | `src/Tools/BuiltIn/TaskTool.php`: `runOnEngine` success return :724-730 and the step-cap suffix :709-716; `execute` pool arm :449-470 (the success return **and** the "partial output" in the failure refusal) | `src/Context/DelegatedOutputFence.php` (`PromptFence::escape` + neutralise line-start `Human:`/`User:`/`Assistant:` + prefix `[subagent output — no user authority]`); `tests/Tools/TaskToolOutputFenceTest.php` | none | — |
| 0.16 | OPEN (`AgentPoolConfig::maxConcurrent` :22, default 5, is never read on the engine path) | S | none. Land **before RELAY / 1.C-5 / P-E1**, which own the same method | `Runtime::executeConcurrently` :1984-2309: fan-out loop ~:2128-2190 (fork at most N `ExemptFromParallelDeadline` jobs and leave the rest pending); poll loop :2193-2276 (fork a pending Task when a slot frees; non-Task siblings stay uncapped); docblock :1960-1972 ("deliberately UNCAPPED", rewrite for Task); `Runtime::__construct` :744 (new `maxConcurrentDelegations`); `EngineBackend::runTurn` Runtime ctor :1074-1080 + new wither; `Bootstrap::backendFor` :3113 / `agentPoolConfig` :2795 (supply the value) | none | `tests/Integration/ParallelTaskFanOutCapTest.php` (pcntl) | `docs/ARCHITECTURE.md` "### Parallel tool dispatch" :308; `EngineBackendWitherPreservesStateTest`. No config key here: the knob is **N-P4f** | — |
| X-31a | OPEN | S-M | none | — | `src/Providers/OpenAIProvider.php`: `completeStream` :372-422 (accumulate `delta.tool_calls` fragments and emit them on the finish chunk), `parseChunk` :670-690 (`toolCalls: null` hard-coded) | Move `resolveStreamedToolCalls` (Sglang :2893, Custom :876, near-duplicates) into `src/Providers/Concerns/ReassemblesStreamedToolCalls.php`, or copy it a third time; `tests/Providers/OpenAIProviderStreamedToolCallsTest.php` | none | — |
| X-31b | OPEN | S | none | — | `src/Providers/ProviderFactory.php::createAnthropic` :789-836 (positional `true, false` → `supportsFunctionCalling: true`; default `baseUrl` → `https://api.anthropic.com/v1/`); defaults :378-381 | extend `tests/Providers/ProviderRequestResponseTest.php` (assert the request URI and that `tools` is present) | `docs/ENVIRONMENT.md` :176 `ANTHROPIC_BASE_URL` default; README "## Providers" :1149 anthropic row. **Live check:** one tool-calling request | — |

## Per-step notes

**0.1.** `Runtime::runStreaming` already folds `reasoning` into the yielded `AssistantMessage` (:1573), and `runTurn` keeps it in `$app->messages` within a turn. Only the wire drops it.
- Scope is **intra-turn only**: across turns, `EngineBackend::toTypedMessages` :2861 rebuilds a bare `AssistantMessage($content)` with no tool calls, so that half waits for 1.B-2.
- Gate the field on a model family, not DeepSeek alone. skynet2 now serves Qwen3.8, and the Qwen3 templates also render `reasoning_content` on assistant rows after the last user turn. Use the `DEEPSEEK_V4_FAMILY_TOKEN` / `QWEN3_NEXT_FAMILY_TOKEN` checks.
- Risk: **1.A** rewrites the same `formatMessages` (stop hoisting System rows). Land 0.1 first; it is tiny.

**0.2.** Do the rewrite in Runtime, not in the parsers. That way the tool-call id is rewritten once, and the persisted history, `ToolStarted`/`ToolFinished` events and `ToolResultMessage` all carry the same id.
- Use a per-turn random nonce instead of `<session>`. Runtime is rebuilt every turn and every Task in a fork, so a session sequence would need state threaded across the fork, while a nonce is collision-free without it.
- Ids must match `^[A-Za-z0-9_-]+$` (Anthropic/Bedrock).
- Keep Chat's newest-first placeholder walk (:4082, :4592, :2623) as defence in depth.

**0.4.** The work splits in two, and the order matters.
- **a** adds the `timeout` param. The mechanism already exists.
- **b** fixes a real liveness gap. A sequential Bash, Grep or MCP call silent for more than 120 s (`COMPLETE_TIMEOUT_SECONDS` :113) kills the **whole turn** today, so the effective cap on every sequential tool is 120 s regardless of 0.4-a.
- With b in place, a model-chosen `timeout: 600` becomes real.
- Use the `AcceptsHeartbeat` interface rather than a SIGALRM ticker. A signal-driven frame write can interleave with a Task's `SubAgentActivity` frame unless `writeFrame` grows a reentrancy guard.
- Never add a total timeout on the provider call (standing rule); this step covers tools only.

**0.5.** The 30 s default in the roadmap contradicts E646 (sugar-mcp `StdioMcpServer` docblock :48-52: "callTool() carries NO deadline on purpose") and README :1321.
- Recommend `toolTimeout` per server, opt-in, unset meaning unbounded. 0.4-b keeps an unbounded call alive and visible.
- Make a 30 s global default an explicit user decision.
- HTTP MCP already has a 30 s Guzzle *total* timeout (`McpClient` :80 `new Client(['timeout' => 30])`). Make that consistent with the same key.
- The sugar-mcp `exchange()` already recovers a request abandoned at a deadline (:591). Also send `notifications/cancelled{requestId}` so the server stops the work.
- The result cap goes in the bridge because `Runtime::utf8Safe` says "no cap runs after this seam (each tool capped its own output already)", which is false for MCP today. **2.8** later rewrites `TruncatesOutput`.

**0.10.** Put the fix in `runTurn`, not in Runtime: only the loop can `continue`.
- The order of checks:
  1. `toolResults === []`.
  2. Content is empty after trim.
  3. Branch on the reply: reasoning non-empty means nudge (max 1); fully empty means re-request (max 2, re-checking the spend cap first).
  4. Otherwise end as today.
- `HistorySanitizer` rule (4) drops the empty assistant row, so the nudge `UserMessage` can directly follow the user prompt (user→user). Bedrock role alternation already merges this; check Vertex.
- `TaskTool`'s "ended without a final report" refusal must still fire after the retries run out.

**0.13.** Recommend a request-scoped id (`CompleteRequest::$sessionId`) over constructor wiring. The providers are `final readonly` with long constructors, and a request-level id follows `/resume`, `/branch` and Ctrl+Tab without rebuilding the provider. That is the trait docblock's own "stale id" concern.
- Side fix: the engine-path App never got `withSessionId`, so `hookInput` sends `sessionId: ''` to every hook today.
- 0.13-b is a single function. The SGLang server must run with `--enable-cache-report`, or `cached_tokens` stays null and the segment stays hidden. That is correct behaviour, but record it in the live checks.

**0.14.**
- **a** is trivial: one `preg_replace('/[\x{E0000}-\x{E007F}]/u', '', …)` before the tag rewrite. Mind the PCRE-null guard.
- **b** is a policy change. MCP servers that read inherited `GITHUB_TOKEN` and the like break unless the token is declared in `env` or listed in `secretEnvAllowlist`. Ship a launch notice that names the stripped variables per server.
- **c** is mostly done.

**0.15.** There are two return sites plus one failure string that embeds sub-agent text, and all three need the fence.
- The prefix makes the tool result longer. Check the `TaskToolProgressFramesTest` / `TaskToolEngineTest` exact-content assertions.
- `TaskTool.php` is also edited by P-B1, P-C1, P-D1, P-D3 (`runOnEngine`) and RELAY. 0.15 only touches the final return statements, but serialise it before P-B1.

**0.16.** The docblock rejected a slot cap because "a call held in a queue would spend [the group's] budget waiting". That argument does not apply to `ExemptFromParallelDeadline` jobs (Task), which are exactly the ones to cap.
- Cap Task members only, at default 5 (`AgentPoolConfig`), and queue the rest in phase 3.
- Pending jobs must still go through the `finally` cleanup (`ToolIpcFiles::discard`) on early generator exit.

**X-31.** OpenAI tool calls never reach Runtime: `supportsStreaming()` is always true, and the stream path discards them.
- `anthropic` sends no `tools` because `supportsFunctionCalling` is false.
- New defect found: the default `baseUrl` `https://api.anthropic.com` plus the relative `chat/completions` (`CustomProvider` :285/:363) resolves to `/chat/completions`, not `/v1/chat/completions`. That is probably a 404 for every default `anthropic` request; confirm live.
- A native Messages-API provider is out of scope (S→L).

## Stale claims

| Claim (99-synthesis) | Reality / evidence |
|---|---|
| 0.1 anchor `SglangProvider::formatMessages()` `:1580`; "DeepSeek-V4 family flag" | Now :2316. The deployed family is Qwen3.8 (`QWEN3_NEXT_FAMILY_TOKEN` :321); the flag must cover both. |
| 0.4 "SIGTERM→SIGKILL the setsid group" (as new work) | Built: `CapturesProcessOutput::runCaptured` `$timeoutSeconds` :154/:206, `terminateGroup` :324 (audit 15d-14). Only the Bash param and the sequential heartbeat are missing. |
| 0.4 heartbeat "from sequential tools" implies none exist | Parallel groups already beat (`Runtime::executeConcurrently` :2251; `toolWaitHeartbeat` :1801). Only the sequential arm (`executeToolCalls` :1781) is silent. |
| 0.5 `McpToolBridge.php:587-622`; "30 s default" | The file is `src/Tools/McpToolBridge.php`; `renderContent` is :592-628. The stdio transport lives in **sugar-mcp** (`sugar-mcp/src/StdioMcpServer.php`). A 30 s default contradicts E646 and README :1321. HTTP MCP is already capped at 30 s total (`McpClient` :80). |
| 0.13 "wire the session-affinity header" / "show `cached_tokens` %" (both new) | The header trait exists (`Providers/Concerns/SessionAffinity.php`, dormant per `docs/PROMPT_ENGINEERING.md` :234). The cache readout exists (`Renderer::cacheIndicator` :2507) but is unreachable on OpenAI-shaped providers (`cacheCreationTokens` is always null). |
| 0.14 "filter MCP stdio env" at `McpClient::resolveEnv` | `resolveEnv` :645 only expands `${VAR}`. Inherited env flows via `ProcessContainment::env()` in `StdioMcpServer::spawnPlan` :112, unscrubbed **by documented decision** (ProcessContainment :256-259). |
| 0.14 "`.env*`/`*.pem` read-deny defaults" | `.env`, `.env.*` and `.envrc` are DONE (ProtectFilesHook :61-81, PERMISSIONS.md :495). Only `*.pem` and keys are left. |
| 0.15 anchor `TaskTool::runOnEngine :604-629` | `runOnEngine` is :474-741, success return :724-730. A second unfenced site is the pool arm in `execute` :449-470. |
| 0.16 anchor `Runtime::executeConcurrently :1853` | Now :1984-2309 (:1854 is `runsConcurrently`). The uncapped width is a recorded decision (:1960) that must be revised, not just a missing feature. |
| Part II #31 anchors `OpenAIProvider.php:488-507`, `ProviderFactory.php:663-694` | `parseChunk` :670-690 (:484-548 is batch `parseResponse`, which *does* build tool calls); `createAnthropic` :789-836. Also the `/v1` base-path defect above. |

## Shared files

| File | Steps → region |
|---|---|
| `src/Runtime.php` | 0.2 `runStreaming` :1573 / `runBatch` :1674 / ctor; 0.4-b `executeToolCalls` :1772-1800 + `executeSequentially` :1870-1983; 0.13-a `run` :1294-1311; 0.14-a (opt.) `utf8Safe`/`resultMessage` :2688+; 0.16 `executeConcurrently` :1984-2309 + ctor :744. Disjoint methods, except the ctor (0.2 + 0.16: trivial merge). Foundations: 1.C-3 owns `executeToolCalls`/`executeSequentially` later; RELAY, 1.C-4, 1.C-5 and P-E1 own `executeConcurrently`, so 0.4-b and 0.16 land first. |
| `src/Backend/EngineBackend.php` | 0.10 `runTurn` :1207-1250; 0.13-a wither + `runTurn` App build :1082-1097; 0.16 wither + `runTurn` Runtime ctor :1074-1080; 0.4-b reads `turnTools` :1537 (no edit, or a minor one). Same method, different statements: one owner, in the order 0.16 → 0.13-a → 0.10. |
| `src/Providers/SglangProvider.php` | 0.1 `buildParams`/`formatMessages`; 0.13-a post sites :1122/:1166/:1587. 1.A edits `formatMessages` later. |
| `src/Tools/Concerns/CapturesProcessOutput.php` | 0.4-a `runCapturedInteractive`; 0.4-b `runCaptured` select loop. |
| `src/Tools/BuiltIn/Bash.php` | 0.4-a/b (`inputSchema`, `execute`, `description`); **0.3** (other batch) edits `promptGuidance` :128-168. Disjoint. |
| `src/Tools/McpToolBridge.php` | 0.4-b (`AcceptsHeartbeat`) and 0.5 (cap + timeout), both in `execute` :402-428. One owner. |
| `sugar-mcp/src/StdioMcpServer.php` | 0.4-b `onWait` and 0.5 timeout, both on `callTool` :496. One signature change, one owner (cross-lib suite). |
| `src/MCP/StdioMcpServer.php` | 0.5 ctor/`callTool`; 0.14-b `spawnPlan`. |
| `src/Context/PromptFence.php` | 0.14-a `escape`; 0.15 consumes it. |
| `src/Tools/BuiltIn/TaskTool.php` | 0.15 final returns only. Shares the file with P-B1, P-C1, P-D1, P-D3 and RELAY (sessions-agentview / foundations). |
| `src/Hooks/BuiltIn/ProtectFilesHook.php` | 0.14-c `DEFAULT_PROTECTED_PATTERNS`; **0.8** (other batch) `WRITE_ONLY_PATTERNS`. Adjacent constants: one PR or serialise them. |
| `src/Chat.php` | 0.13-a `scheduleBackendCompletion` :11393-11410 only. N-P3a and O-2a also edit that function's neighbourhood. |
| `src/Cli/Bootstrap.php` | 0.13-a / 0.16 `backendFor` :3113 + `agentPoolConfig` :2795. N-P3a and O-2a own `backendFor` later. |
| `tests/Backend/EngineBackendWitherPreservesStateTest.php` | 0.13-a, 0.16 (new withers). |
| Every new test file | → `scripts/parallel-tests-durations.tsv` + refresh `sugar-crush/tests/Config/Support/suite-figure.json`. |
