# 15a — Defect audit: agent engine, fork protocol, Runtime, providers, messages, support

Scope: `sugar-crush/src/Backend/`, `src/Runtime.php`, `src/Providers/` (all providers, ProviderFactory, TransientFailure, usage parsing, SSE parsing, ToolCallParser/*), `src/Messages/`, `src/Support/`, `src/Usage.php`.
Method: read every file in scope with comments stripped (line numbers preserved), then wrote throwaway repro scripts for each candidate under `/home/sites/crush-research-repos/_audit-scratch/15a/`. They use mocked Guzzle handlers, real forks and a fake MCP server. No source was changed.
Known items from `99-synthesis.md` Part II are not repeated. Where a finding sharpens one of them, it says so.

**STATUS: FINAL.** Every file in scope was read. Every lead from the earlier checkpoint was either confirmed (and written up) or dropped with a reason. The `## Coverage` section at the end records what was and was not verified, and why.

Confidence key: **Verified-by-repro** (a repro script showed the behaviour), **Verified-by-reading** (the code path is unambiguous but was not run), **Suspected** (verification still needed; the check that remains is named).

---

## A. Providers: error handling, SSE parsing, wire format

### A8 — When textual (DSML or MiniMax-XML) tool calls are recovered, the markup stays in the assistant content: it is painted to the UI and replayed alongside the structured call
- **Severity:** Medium · **Confidence:** Verified-by-repro (`repro_parsers.php`, second half)
- **Where:** `src/Providers/SglangProvider.php:785-802` (content is streamed and accumulated) and `:851-871` (calls are recovered afterwards, but the already-yielded content is never retracted). Also `Runtime.php:1365` (`$buffer .= $response->content`).
- **Failure scenario:** this happens when the server runs without `--tool-call-parser` (the fallback path these parsers exist for). The streamed text `"Let me read it.\n\n<｜DSML｜tool_calls>…</｜DSML｜tool_calls>"` is shown verbatim in the TUI. It also becomes `AssistantMessage::content` and the recovered `tool_calls` are attached as well. On the next request, `formatMessages()` sends the DSML text and the structured `tool_calls`, which the chat template renders as DSML a second time. The model sees every call twice in its own history.
- **Fix:** after recovery, remove the envelope span from the assistant content. The parser could return the cleaned content, with Runtime replacing `$buffer` when a final chunk carries a "content override". Optionally, hold back display of text that follows an envelope prefix.
- **Test:** stream DSML content with `DsmlToolCallParser`. Assert that the final `AssistantMessage::content()` does not contain `｜DSML｜`, and that exactly one call reaches the wire on the next request.

### A9 — The MiniMax XML fallback turns any JSON-looking parameter value into a PHP array, regardless of the tool schema
- **Severity:** Medium · **Confidence:** Verified-by-repro (`repro_parsers.php`, first line)
- **Where:** `src/Providers/ToolCallParser/MinimaxXmlFallbackToolCallParser.php:278-297`.
- **Code:**
  ```php
  $decoded = json_decode($trimmed, true);
  return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : $value;
  ```
- **Failure scenario:** `<invoke name="Write"><parameter name="path">composer.json</parameter><parameter name="content">{"name":"acme/x",...}</parameter>` gives a `content` of type `array` (repro). Writing or editing any JSON file (composer.json, package.json, a .json fixture) through this parser either fails or writes the wrong value. `DsmlToolCallParser` avoids this because the model declares `string="true"`.
- **Fix:** pass the tool schema (name → `inputSchema()['properties'][param]['type']`) into the parser, and decode only when the declared type is `object` or `array`. Without a schema, keep the value as a string.
- **Test:** parse the repro content. Assert that `content` is a string equal to the raw JSON text.

### A10 — `CustomProvider` sends a literal top-level `extra_body` key on the wire
- **Severity:** Medium · **Confidence:** Verified-by-reading. Server rejection was not reproduced, because that needs a live strict endpoint.
- **Where:** `src/Providers/CustomProvider.php:172` and `:240`.
- **Code:** `'extra_body' => ['separate_reasoning' => true],`
- **Failure scenario:** `extra_body` belongs to the OpenAI Python SDK, which merges it into the body before sending. Sent raw, it is an unknown top-level field. Strict OpenAI-compatible servers (api.openai.com answers "Unrecognized request argument supplied: extra_body", and so do several hosted gateways) return 400 on every request. (Before the A1 fix that 400 became a silent empty reply; it now surfaces as an error.) Servers that ignore unknown fields never see `separate_reasoning` either.
- **Fix:** send `'separate_reasoning' => true` at the top level, and only for providers known to accept it, or make it a config flag.
- **Partly fixed on master in `4f8869c63`.** `complete()` and `completeStream()` no longer send `extra_body`, and by default the body carries no `separate_reasoning` at all (SGLang never read the nested form, so nothing changes there). A server that wants extra top-level fields can opt in through a new last constructor parameter, `array $extraBody = []`, also passed through `openAiCompatible()`; one helper merges it into both bodies, and the constructor refuses the key `extra_body`, keys the provider writes itself, and keys that are not non-empty strings. **Remaining:** no config key feeds `extraBody` yet. Adding one needs plumbing through `ProviderFactory`, a `docs/SETTINGS.md` row, and the `TrustKeyDocumentationDriftTest` roster.
- **Test:** capture the outgoing body with history middleware. Assert that it has no `extra_body` key.

### A11 — Malformed tool-call argument JSON runs the tool with `[]`, and the model is never told why
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `CustomProvider.php:449-451` and `:628` (`json_decode(...) ?? []`, with no warning at all), `SglangProvider.php:2221-2260` (warns the UI through `RuntimeNoticeSink` but still returns `[]`).
- **Failure scenario:** truncated or invalid JSON arguments mean the tool runs with no arguments. The model gets a misleading "missing parameter" error from the tool instead of "your arguments were not valid JSON". That invites a repeat loop (which also connects to known #10).
- **Fix:** return a synthetic error `ToolResultMessage` such as "arguments were not valid JSON: <excerpt>" without calling the tool.
- **Test:** a stream with `arguments: '{"path": "a'` should produce an error tool result that names the JSON problem, and the tool's `execute()` should not be called.

### A12 — The `claude-code` provider's streaming path (the only one Runtime uses, since `supportsStreaming()` is true) cannot work
- **Severity:** Medium (non-default provider; it is completely broken) · **Confidence:** Verified-by-repro for the argument error; Verified-by-reading for the framing and argv-size problems.
- **Where:** `src/Providers/ClaudeCodeProvider.php:99-310`, `src/Providers/ClaudeCodeInvocation.php:40-98`.
- **Code:**
  ```php
  $cmd = array_merge([$this->invocation->claudePath()], $this->invocation->baseArgs() /* --output-format json */, $args /* -p <prompt> --output-format stream-json --bare ... */);
  ...
  if (str_starts_with($line, 'data: ')) { ... yield $this->parseChunk($data); }
  ```
- **Failure scenario (three independent faults):**
  1. **Repro:** with the CLI pointed at a dead local base URL, `claude --output-format json -p hi --output-format stream-json --bare --system-prompt x` exits 1 with "Error: When using --print, --output-format=stream-json requires --verbose". Every turn fails.
  2. Even with `--verbose`, stream-json is NDJSON with no `data: ` prefix, so every line is dropped and the reply is empty. Token deltas also need `--include-partial-messages`.
  3. The whole transcript and the system prompt are passed as single argv strings. Linux `MAX_ARG_STRLEN` is 128 KiB per argument, so `exec` fails with E2BIG once the history passes about 128 KiB.
- **Fix:** add `--verbose --include-partial-messages`, drop the duplicate `--output-format`, parse raw JSON lines (`type: stream_event`), and pass the prompt on stdin (`-p` with input from stdin) instead of argv.
- **Test:** use a fake `claude` script that prints NDJSON `stream_event` lines and assert that deltas are yielded. Add a test asserting the argv contains `--verbose` exactly when the format is `stream-json`.

### A13 — `OpenAIProvider::contextWindow()` returns 8,192 for models it can price (gpt-4o-mini, gpt-4.1, gpt-4.1-mini)
- **Severity:** Medium · **Confidence:** Verified-by-reading
- **Where:** `src/Providers/OpenAIProvider.php:103-112` compared with the `PRICE_TABLE` at `:55-63`.
- **Code:** `match ($this->defaultModel) { 'gpt-4o' => 128_000, 'gpt-4-turbo' => 128_000, 'gpt-4' => 8_192, 'gpt-3.5-turbo' => 16_385, default => 8_192 }`
- **Failure scenario:** with `model: gpt-4o-mini` (128k context) or `gpt-4.1` (1M context), Chat's context tiers are computed against 8k, so compaction warnings and prompts fire after a few messages on every turn.
- **Fix:** add the missing ids. Return `0` for unknown models: by the `ProviderInterface::contextWindow()` contract (`ProviderInterface.php:19-31`), 0 means unknown, and `ContextWindow::resolve()` then applies the one named fallback. The hard-coded `default => 8_192` is a guess the contract forbids. Also add a config override.
- **Partly fixed on master in `58d25cb3b`.** `gpt-4o-mini` is sized 128k, `gpt-4.1` and `gpt-4.1-mini` 1,047,576, and an unknown model answers `0`, so `ContextWindow::resolve()` applies its named fallback. **Remaining:** there is still no config override for the context window. Adding one needs a key in `LayeredSettings`, plumbing through `ProviderFactory::createOpenAI()`, a `docs/SETTINGS.md` row, and the `TrustKeyDocumentationDriftTest` roster.
- **Test:** a data provider covering each `PRICE_TABLE` key should get a window of at least 128k.

### A15 — Claude on Vertex is always priced at $0.00, which disables the spend cap; Bedrock invents a $0.01/1k price for unknown models
- **Severity:** Medium · **Confidence:** Verified-by-reading
- **Where:** `src/Providers/VertexProvider.php:278-282` (`return 0.0;`, a placeholder), used by `cost()` at `:1294-1298` and by every usage fold. Bedrock: `BedrockProvider.php:158-169` (`default => 0.01`).
- **Failure scenario:** a paid Claude model on Vertex reports `costUsd = 0.0`. The usage carrier has `unpricedModel = null`, so the UI shows a confident `$0.0000` rather than "unknown", which breaks the "unpriced ≠ zero" contract documented on `Usage`. `EngineBackend`'s mid-turn spend cap (`:922-940`) and Chat's pre-flight cap never trip. Bedrock models outside the table get an invented price.
- **Fix:** return `?float` with `null` for unknown models (the interface already allows `?float`, `ProviderInterface.php:49`), propagate `unpricedModel` the way `OpenAIProvider::parseUsage` does, and add a `modelPrices` config like OpenAI's.
- **Test:** `VertexProvider::parseAnthropicUsage([...], 'claude-x')` should give either a non-zero cost or `unpricedModel === 'claude-x'`.

### A16 — Bedrock does not merge consecutive same-role turns and sends blank text blocks
- **Severity:** Low (non-default provider) · **Confidence:** Verified-by-repro for the wire shape (`repro_bedrock_roles.php`). Server-side rejection was not run live (no AWS credentials). It rests on the Converse API's documented validation rules ("must alternate between user and assistant roles"; "text field … is blank").
- **Where:** `src/Providers/BedrockProvider.php:316-332`. Compare `VertexProvider::formatAnthropicMessages` at `:708-737`, which does merge.
- **Failure scenario:** **Repro:** `[User, User, Assistant(''), User]` is sent unchanged as `user, user, assistant{text:""}, user`. Nothing merges the two user turns, and the blank text block goes out as-is. Two everyday paths produce `user, user`. One is a turn that failed: Bedrock *throws* on errors, so the user row gets no assistant reply. The other is `HistorySanitizer` (`HistorySanitizer.php:115-118`) dropping an empty assistant reply. Because the whole history is replayed, every later request in that Bedrock session sends the same invalid shape.
- **Fix:** merge adjacent same-role messages into one content array, and skip empty text blocks, as Vertex does.
- **Test:** a history of `[User a, Assistant '', User b]` should produce strictly alternating roles with no empty `text`.

### A18 — New evidence for known #27/#28: the default provider sends `max_tokens: 4096` with DeepSeek-V4 `reasoning_effort: max`
- **Severity:** (sharpens known High/Medium items) · **Confidence:** Suspected — verification pending. Measure `usage.reasoning_tokens` and `finish_reason` on the live SGLang server for a few agentic prompts at effort `max`.
- **Where:** `SglangProvider.php:1030` (`'max_tokens' => $request->maxTokens ?? 4096`) and `:165` (`DEEPSEEK_V4_REASONING_EFFORT = 'max'`). `EngineBackend::maxOutputTokens()` (`:1251-1269`) is null unless the user sets `maxOutputTokens`.
- **Why this is materially new:** Part II #27 places the 4096 default on Custom/OpenAI. It is also the default path. In SGLang, generated reasoning tokens count against `max_tokens`, so a max-effort think can use the whole budget. That gives `finish_reason: length` with empty content, which is exactly known #28's "thinking-only reply ends turn", now with a likely root cause on the default configuration.
- **Fix:** set a model-aware default (for example 32k+ for V4 at effort max), or omit `max_tokens` so the server's default applies.

### A19 — Vertex transport errors are never classified as transient: 429 `RESOURCE_EXHAUSTED` and 503 `UNAVAILABLE` get no retry
- **Severity:** Medium · **Confidence:** Verified-by-repro (`repro_vertex_transient.php`)
- **Where:** `src/Providers/TransientFailure.php:197-237` and `:405-422`. The callers are `VertexProvider.php:318-334`, `:404-415` and `:1384-1391`, each of which sets `errorTransient: TransientFailure::isTransient($e)`.
- **Code:**
  ```php
  if ($error instanceof RequestException) { ... }
  if (method_exists($error, 'getStatusCode')) { ... }   // Google\ApiCore\ApiException has none
  return null;
  // ...and no instanceof arm for ApiException, so the chain walk ends with `false`
  ```
- **Failure scenario:** the vendored gax REST transport (`gax/src/Transport/RestTransport.php:176-177`) converts every Guzzle `RequestException` into `Google\ApiCore\ApiException::createFromRequestException()`. That exception carries the **gRPC** code (`getCode()` gives 8, 14 or 13) and `getStatus()` gives `RESOURCE_EXHAUSTED` and so on. It has **no `previous`** and no `getStatusCode()`. **Repro:** HTTP 429, 503, 500 and 401, and a gRPC-style `UNAVAILABLE(14)`, all return `isTransient=false`. Vertex quota 429s are routine on shared projects, and so are model-overloaded 503s. Every one is reported as a permanent `isError`, with no retry, so the turn fails on the first quota error. (Before the A1 fix the user then saw an empty reply; the error is now shown, but still not retried.) `VertexProvider.php:1551-1555` documents that `ApiException` "is caught by complete() and classified by TransientFailure::isTransient()". That claim is false.
- **Fix:** add an arm `if ($link instanceof \Google\ApiCore\ApiException) return in_array($link->getStatus(), ['RESOURCE_EXHAUSTED','UNAVAILABLE','DEADLINE_EXCEEDED','INTERNAL','ABORTED'], true);`, guarded by `class_exists`. Also map `ApiException` without a status to network errors.
- **Test:** the repro as a data provider in `TransientFailureTest`. Add a Vertex `complete()` test with a predictor that throws `ApiException(…, 8, 'RESOURCE_EXHAUSTED')`, asserting `errorTransient === true`.

### A20 — Bedrock's window and price tables match only bare model ids; every real versioned or inference-profile id gets an 8,192-token window and an invented $0.01/1k
- **Severity:** Medium (non-default provider) · **Confidence:** Verified-by-reading for the matching. The model-id format and the on-demand rule come from AWS documentation and were not run live.
- **Where:** `src/Providers/BedrockProvider.php:146-169` (exact-string `match` in `contextWindow()` and `costPer1kTokens()`), and `:46` (`DEFAULT_MODEL = 'anthropic.claude-sonnet-4-6'`).
- **Failure scenario:** Bedrock model ids carry a version suffix (`…-v1:0`). Claude 4.x on-demand calls must also use an inference-profile id with a region prefix (`us.` / `eu.` / `global.` + `anthropic.claude-…`) or an ARN. None of these equal a table key, so `contextWindow()` returns `default => 8_192`. Chat's context tiers (reminder, auto-compaction, blocking refusal) then fire against 8k on a 200k model: auto-compaction runs after a few messages, on every turn. This also contradicts the `ProviderInterface` contract that unknown means `0`. `costPer1kTokens()` invents `$0.01/1k` both ways, which the interface docblock (`ProviderInterface.php:34-45`) explicitly calls the pre-billing-fix bug. The bare-id `DEFAULT_MODEL` is also probably refused for on-demand throughput on Claude 4.x (suspected; needs a live call).
- **Fix:** normalise the id before lookup: strip a region-profile prefix and an ARN path, and match on `str_starts_with` of the family key. Return `0` and `null` for unknown models, and set `unpricedModel`. Ship an inference-profile `DEFAULT_MODEL`.
- **Test:** `new BedrockProvider($client, model: 'us.anthropic.claude-sonnet-4-6-v1:0')` should report a window of at least 200k, and `costPer1kTokens('us.meta.x', 'input')` should be `null`.

### A21 — Gemini 2.5 on Vertex thinks by default: thinking tokens are left out of Usage and share the 4,096 `maxOutputTokens` default
- **Severity:** Medium (non-default provider) · **Confidence:** Suspected. Verification pending: one live `streamGenerateContent` against `gemini-2.5-pro`/`-flash` with a long agentic prompt, reading `usageMetadata.thoughtsTokenCount` and `finishReason`.
- **Where:** `VertexProvider.php:1770-1793` (`parseUsageMetadata` folds only `promptTokenCount` and `candidatesTokenCount`; `thoughtsTokenCount` is dropped, as its own docblock at `:1762-1764` says). `:1459-1462` (`maxOutputTokens => $request->maxTokens ?? 4096`). `:1558-1562` (the docblock reasons that "no thought part is ever produced" because no `thinkingConfig` is sent).
- **Failure scenario:** Gemini 2.5 Pro and Flash think by default when `thinkingConfig` is absent. Omitting it hides the thought *parts* (`includeThoughts` defaults to false) but not the thinking itself. Thought tokens are billed as output, and per Google's documentation they count against `maxOutputTokens`. Two consequences follow. (1) `Usage.totalTokens` and `outputTokens` under-count every Gemini turn; cost is already $0 because of A15. (2) A hard prompt can spend most of the 4,096 budget thinking, ending with `finishReason: MAX_TOKENS` and little or no text. That is the same mechanism as A18 and known #28, on a different provider.
- **Fix:** fold `thoughtsTokenCount` into a reasoning or output bucket, add a `thinkingConfig.thinkingBudget` setting, and raise the default `maxOutputTokens` for 2.5 models (for example 32k), or omit it.
- **Test:** `parseUsageMetadata(['promptTokenCount'=>10,'candidatesTokenCount'=>5,'thoughtsTokenCount'=>900], 'gemini-2.5-pro')` should account for the 900 tokens.

### A23 — Replayed tool-call arguments send a nested empty map as `[]`
- **Severity:** Low-Medium · **Confidence:** Verified-by-reading (found while fixing A7)
- **Where:** `src/Providers/Concerns/ToolSchema.php:195-196` (`formatToolCalls()`: `json_encode((object) $call->arguments(), …)`), used by the Sglang, Custom and OpenAI history formatters. The arguments reach it as a PHP array, because the providers decode the model's argument JSON with `json_decode(…, true)`.
- **Failure scenario:** A7's fix (`d2911641e`) forces an object only at the top level, so lists now replay correctly. Below the top level, PHP cannot tell an empty map from an empty list once the JSON was decoded as an associative array. The model calls a tool with `{"opts":{}}` (an options bag, a filter, a headers map), and every later step and turn replays that call as `{"opts":[]}`. The model sees its own earlier call in a shape that contradicts the tool's schema, and tends to copy it. This is the same class as 15e MCP-9 (nested empty maps on the MCP wire), which was fixed there by walking the arguments against the tool's `inputSchema`.
- **Fix:** keep the provider's raw `function.arguments` JSON string on the `ToolCall` when it arrives, and replay that string verbatim (re-encoding only calls that have no raw string, such as recovered textual calls). Alternatively, decode with objects (`json_decode` without `assoc`) end to end, so both shapes round-trip.
- **Test:** replay an assistant tool call whose arguments arrived as `{"opts":{},"paths":[]}`, and assert the outgoing `arguments` string is byte-equal to that input.

---

## B. Engine fork protocol and process lifecycle

### B4 — Task sub-agent spend is invisible to the parent turn, to the session total and to the spend cap
- **Severity:** Medium (High on paid providers) · **Confidence:** Verified-by-reading
- **Where:** `src/Tools/BuiltIn/TaskTool.php:604-608`. Usage is written only to `$subAgent->tokensUsed/costUsd`, which lives in the forked child or, for parallel Tasks, in a grandchild whose memory is discarded. `src/Tools/ToolResult.php:26-35` has no usage field. `src/Backend/EngineBackend.php:890-941` sums and caps only `$assistant->usage()` of the parent's own steps. The docblock at `TaskTool.php:87` says the sub-agent runs with the same "spend cap … as the turn that called it".
- **Code:**
  ```php
  $subAgent->tokensUsed += $reply->usage?->totalTokens ?? 0;
  $subAgent->costUsd += $reply->usage?->costUsd ?? 0.0;
  return new ToolResult(toolCallId: $toolCallId, content: $content, isError: false, durationMs: ...);   // usage dropped
  ```
- **Failure scenario:** a turn fans out 5 parallel Tasks, each running up to 50 steps (`DEFAULT_MAX_TURNS`). None of those tokens or dollars reach `Message::usage`, so `/cost`, the token tracker and the context calibration all under-report. The sub-agent's own cap check starts from `sessionSpendAtStartUsd` with an empty `$stepUsages`, so it ignores the parent's spend so far this turn and every sibling's. The parent's boundary check ignores all sub-agent spend. A `/budget` cap can therefore be exceeded by sub-agent spend that is never counted.
- **Fix:** add `?Usage $usage` to `ToolResult`, and carry it through `encodeResult`/`decodeResult` (`Runtime.php:2524-2557`) and `ToolResultMessage`. Fold tool-reported usage into `$stepUsages` in `runTurn()`, and pass a shared running total into the sub-agent's spend cap.
- **Test:** a Task over a stub provider that reports a cost of $1 per step should make the parent's `Message::usage->costUsd` include the sub-agent's dollars, and a cap of $0.5 should stop the sub-agent.

### B5 — `withPermissionApprover()` and `withMemoryStore()` silently drop the spend cap
- **Severity:** Low (latent) · **Confidence:** Verified-by-reading
- **Where:** `src/Backend/EngineBackend.php:540-543` and `:573-576`. Both call `new self(...)` with 14 arguments, so `$spendCapUsd` and `$sessionSpendAtStartUsd` fall back to null and 0.0.
- **Failure scenario:** today `Chat.php:9269` applies `withSpendCap()` last, so nothing breaks yet. Any embedder, or future code, that calls either wither after `withSpendCap()` loses the cap without any signal. This is the bug class the `mutate()` convention exists to prevent.
- **Fix:** route every wither through one private `mutate()` with named arguments.
- **Test:** `withSpendCap(1.0)->withMemoryStore(null)->withPermissionApprover(fn() => true)` should preserve the cap (assert through reflection, or behaviourally with a costing stub).

---

## C. Runtime

These are covered above: B4 (no usage channel on tool results). B2 (deadline kill orphans) was fixed in `c54372b2a`.

### C3 — Project instructions (`CLAUDE.md`/`AGENTS.md` + every `@import`) have no byte budget, while rules have 64 KiB
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `src/Runtime.php:3009-3041` adds every `loadRoot()`/`loadForced()` document in full. Compare `:2963-2999` and `:3050+`, where user and project rules share `MAX_STANDING_RULE_BYTES = 65_536` (`:161`) with pointer deferral. `src/Context/InstructionFileLoader.php:841-871` expands `@imports` through `ImportResolver` with a depth cap but no size cap. Ancestor `CLAUDE.md`/`AGENTS.md` files (`:401-461`) are added on top.
- **Failure scenario:** a checked-in `CLAUDE.md` that writes `@docs/ARCHITECTURE.md` or `@README.md`, or a parent-directory `AGENTS.md`, puts hundreds of KB into **every** request's system prompt, with no notice. Known #21 says the token estimate ignores the system prompt, so Chat's tiers never see the cost. On a 128k or 200k window this overflows on every request. On this monorepo, the root `CLAUDE.md` with its two imports is already 25,110 B (`InstructionFileLoader.php:176`).
- **Fix:** charge instruction documents against the same per-build budget (or their own, for example 64 KiB). When a document doesn't fit, defer it to a pointer line the way rules are deferred, and report the overflow as a runtime notice.
- **Test:** a fixture repo whose `CLAUDE.md` imports a 200 KB file should produce a system prompt under the budget, containing a pointer to the deferred import.

### C4 — The notice sink's clip and overflow strings, and TROUBLESHOOTING.md, still send the user to stderr for the full text
- **Severity:** Low (docs and UI wording) · **Confidence:** Verified-by-reading (found while fixing C2 in wave 3)
- **Where:** `src/Diagnostics/RuntimeNoticeSink.php:202` (`CLIP_SUFFIX = '… (clipped; full text on stderr)'`) and `:267` (`OVERFLOW_FORMAT = '… and %d more runtime notice%s this session; see stderr for the full text.'`); `docs/TROUBLESHOOTING.md:85-91`, `:135` and `:326-328`.
- **Detail:** since C2's fix (`9a827f4e3`), the TUI points `error_log` at `~/.sugar-crush/logs/sugarcrush.log` (new `Diagnostics\TuiErrorLog`), so in the TUI the full text of a clipped or overflowed notice is in that log file, not on stderr. The transcript row still tells the user to look at stderr, where nothing is written, and TROUBLESHOOTING.md still describes stray stderr lines inside the frame as the expected failure. The wording is still right for `-p` and the other non-TUI entry points, where the destination stays stderr.
- **Fix:** make both strings name the actual destination (for example, a `%s` filled from `TuiErrorLog`'s path when it is armed, else "stderr"), and update the TROUBLESHOOTING.md passages to describe the log file.
- **Test:** a TUI-armed sink's clipped notice names the log path; an unarmed one still says stderr.

---

## Summary table (by severity)

| ID | Severity | Confidence | Title | Location |
|---|---|---|---|---|
| A8 | Medium | repro | Recovered DSML/XML markup stays in content (painted, then sent twice) | SglangProvider.php:785-871 |
| A9 | Medium | repro | MiniMax fallback makes JSON-looking strings into arrays (Write of composer.json breaks) | MinimaxXmlFallbackToolCallParser.php:278-297 |
| A10 | Medium | reading | `CustomProvider` sends a literal `extra_body` key. Partly fixed (`4f8869c63`: no literal key; opt-in constructor `array $extraBody`); remaining: no config key feeds `extraBody` | CustomProvider.php:172, 240 |
| A12 | Medium | repro+reading | claude-code streaming cannot work (no `--verbose`, wrong framing, argv > 128 KiB) | ClaudeCodeProvider.php:99-310 |
| A13 | Medium | reading | OpenAI window is 8k for gpt-4o-mini/4.1. Partly fixed (`58d25cb3b`: ids sized, unknown → 0); remaining: no context-window config override | OpenAIProvider.php:103-112 |
| A15 | Medium | reading | Vertex priced at $0 (spend cap inert); Bedrock invents $0.01 | VertexProvider.php:278; BedrockProvider.php:158 |
| **A19** | Medium | repro | Vertex `ApiException` (429/503/500) never classified transient: no retry, then empty reply | TransientFailure.php:197-237, 405-422; VertexProvider.php:318, 404, 1384 |
| **A20** | Medium | reading | Bedrock tables match only bare ids: real versioned/profile ids get an 8k window and an invented $0.01/1k | BedrockProvider.php:46, 146-169 |
| **A21** | Medium | suspected | Gemini 2.5 default thinking: thought tokens missing from Usage and sharing the 4096 `maxOutputTokens` | VertexProvider.php:1459, 1770-1793 |
| B4 | Medium (High paid) | reading | Task sub-agent spend never reaches the parent, session total or cap | TaskTool.php:604-608; ToolResult.php; EngineBackend.php:890-941 |
| A18 | (sharpens #27/#28) | suspected | Default SGLang `max_tokens` 4096 with effort `max` | SglangProvider.php:1030, 165 |
| **A23** | Low-Med | reading | Replayed tool-call arguments send a nested empty map (`{"opts":{}}`) as `[]` (residual of A7) | ToolSchema.php:195-196 |
| A11 | Low | reading | Malformed argument JSON runs the tool with `[]`; model not told | CustomProvider.php:628; SglangProvider.php:2221 |
| A16 | Low | repro (shape) | Bedrock: no same-role merge, blank text blocks | BedrockProvider.php:316-332 |
| B5 | Low | reading | Two withers drop the spend cap (latent) | EngineBackend.php:542, 575 |
| **C3** | Low | reading | Project instructions and `@imports` have no byte budget (rules have 64 KiB) | Runtime.php:3009-3041; InstructionFileLoader.php:841-871 |
| **C4** | Low (docs) | reading | Notice-sink clip/overflow strings and TROUBLESHOOTING.md still say "full text on stderr"; in the TUI it is in the log file (residual of C2) | RuntimeNoticeSink.php:202, 267; TROUBLESHOOTING.md:85-91, 135, 326 |

New in the final pass: **A19, A20, A21, C2, C3**. Re-graded: B6 (Suspected → Verified-by-repro), A16 (Suspected → wire shape verified by repro), C1 (Low/Suspected → Info, not reachable). Sharpened: A13 (fix aligned with the `contextWindow()` contract). Items sharpened in that pass and since fixed are listed under **Fixed since audit**. Found while fixing A7 in wave 2: **A23**. Found while fixing C2 in wave 3: **C4**.

---

## Coverage

**Method.** Every line in scope was read with comments stripped (`/home/sites/crush-research-repos/_audit-scratch/15a/strip.php`, which keeps the original line numbers). Docblocks were consulted where they state a contract. Each candidate was confirmed with a throwaway script under `/home/sites/crush-research-repos/_audit-scratch/15a/` (real forks, mocked Guzzle handlers, a fake MCP server, reflection into private helpers), or confirmed by reading where the path is unambiguous. No source was modified, no phpunit run was needed, and every process the scripts started has exited.

**Audited in full:**
- `src/Backend/`: `EngineBackend.php`, `CommandBackend.php`, `StreamingCommandBackend.php`, `EchoBackend.php`, `ObservesReasoning.php`, `ReportsContextWindow.php`, `CancellationToken.php`, `TranscriptTurn.php`, `TurnInterrupted.php`.
- `src/Runtime.php` (all code, including `systemPromptSections()`).
- `src/Providers/`: `SglangProvider.php`, `CustomProvider.php`, `OpenAIProvider.php`, `VertexProvider.php` (all 2512 lines, including the gax predictor/streamer, the SSE decoder and the protobuf builders), `BedrockProvider.php` (all), `ClaudeCodeProvider.php`, `ClaudeCodeInvocation.php`, `ProviderFactory.php`, `TransientFailure.php`, `ProviderException.php`, `ProviderInterface.php`, `CompleteRequest.php`, `CompleteResponse.php`, `EchoProvider.php`, `EmbeddingsRequest.php`, `EmbeddingsResponse.php`, `CacheBreakpoints.php`, `Concerns/*` (HttpClientDefaults, ReasoningExtractor, SessionAffinity, ToolSchema), and `ToolCallParser/*` (OpenAiArray, Dsml, MinimaxXmlFallback, MarkupScanner).
- `src/Messages/`: all six files.
- `src/Support/`: all thirteen files (`AtomicFileWriter`, `ContainedPath`, `EnumSpelling`, `ForkedChild`, `Frontmatter`, `HomeDirectory`, `HookContextFiles`, `ParentProcessGuard`, `ProcessContainment`, `ProcessReaper`, `SystemClipboard`, `TimedFileLock`, `ToolIpcFiles`).
- `src/Usage.php`.
- Read outside scope, where a finding depended on it: `src/Agents/EngineExecutor.php`, `TaskTool.php:440-630`, `Tools/Concerns/CapturesProcessOutput.php`, `MCP/StdioMcpServer.php` with `sugar-mcp/src/StdioMcpServer.php`, `LSP/LspConnection.php` (id counter), `Context/InstructionFileLoader.php`, `Diagnostics/RuntimeNoticeSink.php`, `Cli/Bootstrap.php:6585-6627`, `Chat.php` (notice pump, 9240-9340), `candy-pty/src/SignalForwarder.php`, and `vendor/google/gax` (`ApiException`, `RestTransport`).

**Leads from the checkpoint, and how each was resolved:**
1. LSP fork hazard: **same flaw, latent.** `LspTool` is wired with a null client and nothing calls `LspConnection::connect()`. Folded into B1 (now fixed; the LSP exchange lock that remained, B7, was fixed later in `d0f7cb6f6`).
2. `ProviderException` exit code read as an HTTP status: **dropped.** `ProviderException` exposes `exitCode` as a property, not `getStatusCode()`, so `TransientFailure::statusCode()` returns null and a CLI failure is correctly non-transient. Following this lead turned up the real classification gap for Vertex `ApiException`, written up as **A19**.
3. Vertex `defaultStreamer()` SSE parsing: **no new framing defect.** The streamer accepts `data:` without a space, CRLF via `trim`, and a trailing unterminated event. Errors arrive as `isError` chunks and so run into A1. The missing end-of-stream check was added to A3, and the classification gap is A19.
4. Uncapped project instructions: **confirmed** as C3. The other half of the lead (`RuleLoader` re-reading disk every step) is a performance cost only and was not reported. `InstructionFileLoader::loadRoot()` is cached per session.
5. A16 and A18 live validation: A16's wire shape is now verified by repro; the server rejection is not (no AWS credentials). A18 and A21 still need a live model. The SGLang and Vertex endpoints were not called from this audit, so both stay **Suspected**.
6. C1 `SIGCHLD` reaper: **dropped to Info.** The only SIGCHLD handler in the monorepo (`candy-pty` `SignalForwarder::attachSigchld`) is never installed by sugar-crush. C1 was hardened later anyway (`c10717d8c`).
7. `error_log()` from `flagTruncationRiskInLatestToolResults()`: **confirmed** and broadened to C2.

**Checked and found sound (no finding):** the A1 fix placement versus `ReasoningProgressTest` (compatible if the throw goes after the retry loop); Bedrock `AwsException` classification (`getStatusCode()` exists, the chain is preserved, 429/5xx retry); `ContainedPath` (realpath on both sides, separator-anchored prefix); `HookContextFiles` (owner/mode/symlink checks, umask, atomic rename; retention is deliberate); `SystemClipboard` (bounded write, terminate ladder); `HomeDirectory::owned()`; `Frontmatter` repair pass; `EchoProvider`/`EchoBackend`; `CancellationToken`; `TurnInterrupted`.

**Not reported, because they are already known or out of scope:**
- `CacheBreakpoints.php` was read in full. It is **dormant**: there are no callers outside its own test. That is already reported in `00-sugar-crush-baseline.md` §3.5 and in reports 01/02/07/08/10/11, so it is not repeated. No defect was found in the class itself, apart from the wiring note that its `role: system` turn has to be split out to Anthropic's top-level `system` by the caller.
- Vertex's legacy `:predict` arm (PaLM / `chat-bison`) drops `parameters` (`VertexProvider.php:2126-2139`). The code itself admits this (docblock `:2429-2436`), and those models are retired by Google, so it was left as dead-arm debt.
- Gemini tool calling is absent, but deliberately: `supportsFunctionCalling()` honestly returns false (`:241-256`).

**Not verified, and why:**
- Live provider behaviour: Bedrock's Converse rejection (A16), the bare-id on-demand refusal (A20, last sentence), SGLang reasoning-token use (A18) and Gemini thinking budget use (A21). They need credentials or a live model; this audit made no outbound model calls.
- The real gax REST server-stream decoding of `streamRawPredict` SSE bodies. It is exercised only through `VertexProviderTest`'s seams, not against Google.
- C2's on-screen corruption was established from code plus the fd-2 destination check, without a PTY capture of the running TUI.

---

## Fixed since audit

These findings were fixed on master after the audit. Their sections and table rows were removed.

- **A1** Provider `isError` responses became silent empty replies — fixed on master in `ea81820fb`. Residual: `AgentManager::executeSubAgent()` swallowed provider errors the same way (15e AG-4), fixed later in `48e9a3f65`.
- **A2** SGLang/Custom in-stream `{"error":…}` events ignored — fixed on master in `4c22a9b7a`.
- **A3** A stream that ends without a finish signal treated as complete — fixed on master in `9105a64ae`. A5 (no-space `data:` framing) surfaced through this path as a premature-end error until it was fixed in `5781eb9f8`.
- **A6** One non-UTF-8 byte in tool output failed every later request — fixed on master in `f33cd55fd` (scrub at `Runtime::settle()`, shared with 15c F-T1). Residual: the two command backends (A22), fixed later in `987c8d87f`.
- **B1** MCP ids reset per fork; a killed call shifted every later result — fixed on master in `ea6e178fd` (process-unique ids, locked exchanges, shared read buffer); LSP ids in `e6f6aee54`. Residual: the LSP exchange lock (B7), fixed later in `d0f7cb6f6`; a lock file per MCP server is left behind if the TUI is killed.
- **A5** `data:` with no space after the colon was ignored — fixed on master in `5781eb9f8` (new `Providers\SseData`, used by SglangProvider and CustomProvider; VertexProvider already accepted it). ClaudeCodeProvider's `data: ` check is A12's NDJSON framing fault, not this one.
- **A22** `CommandBackend`/`StreamingCommandBackend` encoded history without `JSON_INVALID_UTF8_SUBSTITUTE` — fixed on master in `987c8d87f` (`CommandBackend::encodeHistory()` substitutes; `StreamingCommandBackend` reuses it).
- **B7** The LSP client had unique ids but no cross-process exchange lock — fixed on master in `d0f7cb6f6` (per-process `LspExchangeLock` flock with bounded polling, `LspExchangeState` sidecar, shared readahead, recovery from a holder killed mid-read or mid-write, notifications journaled and replayed, server-to-client requests answered -32601, only the connecting process stops the server).
- **A4** Streamed tool calls were dropped on `finish_reason=stop` or `delta:null` — fixed on master in `90dc99dfc` (a null-delta finish frame is parsed with an empty delta; at end of stream a still-buffered call is flushed whatever the finish reason, with the decode-or-drop rule of the truncated flush; `CustomProvider` gains `flushBufferedToolCalls()`). Residual: the `error` finish reason deliberately does not flush (the server disowned its generation).
- **A7** `formatToolCalls()` replayed every list argument as an object (`JSON_FORCE_OBJECT` is recursive) — fixed on master in `d2911641e` (only the top level is forced to an object, via an `(object)` cast; invalid UTF-8 is substituted instead of the old `?: '{}'` fallback; anything else `json_encode()` cannot represent throws). Remaining: nested empty maps in replayed arguments (`{"opts":{}}`) now go out as `[]`, because PHP cannot tell an empty map from an empty list after an associative `json_decode`. Lists are correct, maps are not; filed as **A23**.
- **A14** OpenAI cost billed cached prompt tokens at the full input rate — fixed on master in `58d25cb3b` (fresh × input + cached × cached-input + completion × output, from a new `CACHED_INPUT_TABLE`; models with no cached rate bill cache hits at the input rate; an operator `modelPrices` entry may declare a `cached` rate, documented in `docs/SETTINGS.md`).
- **B2** Esc or watchdog teardown killed only the turn process; the setsid'd shell command kept running — fixed on master in `c54372b2a` (new `ProcessContainment::killTree()`: freeze the root, walk `/proc` via the new `Support\ProcessTree` stopping every descendant, then SIGKILL every member's process group and pid, bounded to about 250 ms; wired into `EngineBackend` teardown and the `Runtime` parallel-deadline kill). Residual: `killTree()` blocks the event loop for about 110 ms on Escape. The remaining kill sites are 15c F-E2.
- **B3** Both ends of the frame socketpair leaked into every process the turn spawned — fixed on master in `53da0a291` (the child closes the parent's end first; new `ProcessContainment::closeOnExec()` sets `FD_CLOEXEC` via FFI `fcntl` on both ends; `completeAsync` also polls the turn pid with `WNOHANG`, so a dead turn is noticed without waiting for EOF).
- **B6** The frame writer gave up silently mid-frame and the reader resynced without teardown — fixed on master in `ee2e59b08` (the child's end carries a year-long write timeout and a failed write ends the child with `exitNow(1)`; `drainFrames()` reports corruption, and `completeAsync` delivers the frames decoded before it, then tears the turn down with "Provider worker frame stream corrupted").
- **C1** `waitpid -1` never settled an exempt parallel job — fixed on master in `c10717d8c` (both wait sites go through `parallelJobHasExited()`, which treats any non-zero answer as gone; the payload file is still read, so the real result arrives).
- **A17** `embeddings()` swallowed transport errors and returned an empty list — fixed on master in `106ea5253` (both providers throw `\RuntimeException` with the provider's own error text and the Guzzle exception as `previous`, so `TransientFailure` still classifies 5xx, 429 and connect failures as transient and 400 as permanent; a 2xx body that is not JSON, has no `data` list, or has an item without an `embedding` also throws; `data: []` still returns an empty result).
- **C2** Engine-side `error_log()` diagnostics painted over the TUI frame — fixed on master in `9a827f4e3` + `520298b79` + `0c2bbd0a7` (new `Diagnostics\TuiErrorLog` points `error_log` at `~/.sugar-crush/logs/sugarcrush.log`, directory 0700 and file 0600, refusing a symlinked file, rotating one generation past 5 MiB and keeping an operator's own destination; `bin/sugarcrush` arms it on the TUI path just before `Program::run`; `RuntimeNoticeSink::warn()` skips `error_log()` while armed with a transport and the destination is still stderr) and `080fac09d` (the SGLang `</parameter>` truncation-risk warning is logged once per tool-call id per provider instance, an empty id keyed by a content hash, at most 1024 entries). Residual: if the log cannot be set up, the parsers' direct `error_log()` calls can still reach the tty; the notice-sink strings and TROUBLESHOOTING.md still say "full text on stderr" (C4).
