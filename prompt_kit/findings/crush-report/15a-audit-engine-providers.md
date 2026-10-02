# 15a — Defect audit: agent engine, fork protocol, Runtime, providers, messages, support

Scope: `sugar-crush/src/Backend/`, `src/Runtime.php`, `src/Providers/` (all providers, ProviderFactory, TransientFailure, usage parsing, SSE parsing, ToolCallParser/*), `src/Messages/`, `src/Support/`, `src/Usage.php`.
Method: read every file in scope with comments stripped (line numbers preserved), then wrote throwaway repro scripts for each candidate under `/home/sites/crush-research-repos/_audit-scratch/15a/`. They use mocked Guzzle handlers, real forks and a fake MCP server. No source was changed.
Known items from `99-synthesis.md` Part II are not repeated. Where a finding sharpens one of them, it says so.

**STATUS: FINAL.** Every file in scope was read. Every lead from the earlier checkpoint was either confirmed (and written up) or dropped with a reason. The `## Coverage` section at the end records what was and was not verified, and why.

Confidence key: **Verified-by-repro** (a repro script showed the behaviour), **Verified-by-reading** (the code path is unambiguous but was not run), **Suspected** (verification still needed; the check that remains is named).

---

## A. Providers: error handling, SSE parsing, wire format

### A1 — Provider errors returned as `isError` responses are silently turned into an empty assistant reply
- **Severity:** High · **Confidence:** Verified-by-repro (`repro_error_swallow.php`)
- **Where:** `src/Runtime.php:1419-1459` (streaming), `src/Runtime.php:1496-1567` (batch). Producers are `src/Providers/CustomProvider.php:201-213` and `:346-357`, plus `src/Providers/VertexProvider.php:318-334`, `:404-415`, `:874-889`, `:1132-1153`, `:1264-1279`, `:1384-1391` and `:1567-1584`.
- **Code:**
  ```php
  // Runtime::runStreaming
  if ($response->isError) { $errorChunk = $response; }
  ...
  if ($errorChunk === null || $lastAttempt || $emitted || !TransientFailure::responseIsTransient($errorChunk)) { break; }
  ...
  yield new AssistantMessage($buffer, $toolCalls ?: null, $reasoning, Usage::sum($usages), $lengthStopped);
  ```
  `$errorChunk->errorMessage` is never read anywhere in `src/Runtime.php`. `runBatch` behaves the same way: it `break`s and yields `$response->content` (which is `''`).
- **Failure scenario:** any `custom` provider, the `anthropic` type (which is a `CustomProvider`), or Vertex gets an auth failure (401), a 400, a 404 for a bad model, or a blocked prompt. **Repro output:** a 401 `{"error":{"message":"Incorrect API key provided"}}` comes back from `EngineBackend::complete()` with no exception: `content=''` and `stepsTruncated=false`, in both streaming and batch modes. The user sees a blank reply, and the error text is lost. Transient errors are retried (MAX_ATTEMPTS), but once retries run out they are swallowed the same way.
- **Fix:** after the retry loop, if `$errorChunk !== null` (streaming), or if `$response->isError` (batch), throw `new ProviderException($errorChunk->errorMessage ?? 'provider error')`. That lets `EngineBackend`/`TurnInterrupted` report it. Alternatively, providers could throw instead of returning `isError`.
- **Test:** use a `MockHandler` that returns 401 for `CustomProvider(stream=true/false)`. Assert that `EngineBackend::complete()` throws and that the message contains "Incorrect API key". Add a Vertex variant using a stub `streamer` that yields an `error` event.
- **Existing tests that pin the bug (they must change with the fix):** `tests/Integration/ProviderRetryWiringTest.php::testABatchUnclassifiedErrorResponseIsNotRetried` asserts `content() === ''` ("the pre-retry outcome is unchanged"). `::testAnUnclassifiedStreamErrorChunkLeavesTheOutcomeUnchanged` asserts a mid-stream non-transient error ends as a successful `'half '` reply. Both assertions describe the silent swallow, so they have to become `expectException`. **No conflict with `tests/Backend/ReasoningProgressTest.php`:** its retry cases (`testAStreamThatOnlyThoughtBefore…`, through `ThinkThenFailDouble`) put the error chunk on attempt 1, and attempt 2 is clean. The throw belongs **after** the retry loop, only when the final attempt still holds `$errorChunk`. The `$emitted` latch semantics are therefore unchanged. Throwing inside the loop would break these tests.

### A2 — SGLang's in-stream error event (`data: {"error":{...}}`) is silently ignored, so the turn "succeeds" with partial or empty text
- **Severity:** High · **Confidence:** Verified-by-repro (`repro_sglang_stream.php`, case `error-event`)
- **Where:** `src/Providers/SglangProvider.php:769-818`. The same shape is in `src/Providers/CustomProvider.php:291-317`.
- **Code:**
  ```php
  $data = json_decode(substr($line, 6), true);
  $streamFinishReason = $data['choices'][0]['finish_reason'] ?? $streamFinishReason;
  if ($data !== null && isset($data['choices'][0]['delta'])) { ... yield $chunk; }
  elseif ($data !== null && !isset($data['choices'][0]) && is_array($data['usage'] ?? null)) { ... }
  // anything else (an {"error":...} object) is dropped
  ```
- **Failure scenario:** SGLang's OpenAI server reports errors raised after the response has started (for example "input is longer than the model's context length", or abort/OOM) as HTTP 200 plus `data: {"error":{"message":...,"code":400}}` and then `data: [DONE]`. **Repro:** `Hel` followed by that error event gives one chunk `{"content":"Hel"}`, no exception and no `isError`. `SglangProvider` never sets `isError` at all, so the Runtime's error-chunk retry (A1) can never trigger on the default provider. This matters most together with known #5 (no overflow recovery): a session that grows past the window gets empty or truncated replies with no error shown.
- **Fix:** in the SSE loop, if `is_array($data['error'] ?? null)` (or `($data['object'] ?? null) === 'error'`), throw a `ProviderException` that carries the message and code. Mark it transient when the code is 5xx or 429. Apply the same change in `CustomProvider`.
- **Test:** stream a body containing an `error` event. Assert that `completeStream()` throws, and that `EngineBackend::complete()` rejects or throws with the server's message.

### A3 — A stream that ends without `finish_reason` (connection drop) is treated as a complete answer when it has no tool calls
- **Severity:** Medium · **Confidence:** Verified-by-repro (case `drop-text`)
- **Where:** `src/Providers/SglangProvider.php:836-904`. `CustomProvider.php:281-345` does no end-of-stream check at all, and neither does Vertex (Verified-by-reading). In `VertexProvider::completeStream()` (`:380-403`), a stream that ends before `message_delta`/`message_stop` falls out of the `foreach` with no "saw a stop" check. `streamGemini()` flags only `lengthStopped` and never a missing `finishReason`.
- **Code:**
  ```php
  $streamEndedTruncated = $streamFinishReason === null || in_array($streamFinishReason, self::TRUNCATED_FINISH_REASONS, true);
  $truncatedFlush = $streamEndedTruncated && $toolCallBuffer !== [] ? self::flushTruncatedToolCalls($toolCallBuffer) : null;
  ...
  } elseif (in_array($streamFinishReason, self::TRUNCATED_FINISH_REASONS, true)) {   // null is NOT in the list
      yield new CompleteResponse(content: '', truncated: true);
  }
  ```
- **Failure scenario:** a proxy idle-timeout, a server restart or a network reset happens mid-answer. The streaming path uses Guzzle's StreamHandler, where `eof()` simply becomes true and nothing is thrown. Result: `"The fix is to chan"` comes back as a final, untruncated answer. `lengthStopped` is false, there is no retry and no notice. The tool-call branch treats `null` as truncated, so the two branches disagree.
- **Fix:** if the stream reaches EOF without any `finish_reason` and without `[DONE]`, throw a transient "stream ended prematurely" `ProviderException`. It will be retried when nothing has been emitted yet. At minimum, yield `truncated: true`.
- **Test:** stream content chunks with no `finish_reason` and no `[DONE]`. Assert either an exception or `truncated === true`.

### A4 — Streamed tool calls are emitted only from a chunk where `finish_reason === 'tool_calls'` and `delta` is non-null; otherwise they are silently dropped
- **Severity:** Medium · **Confidence:** Verified-by-repro (cases `tc-finish-stop` and `tc-delta-null`)
- **Where:** `src/Providers/SglangProvider.php:779` and `:2048`, `src/Providers/CustomProvider.php:308-310` and `:620`.
- **Code:**
  ```php
  if ($data !== null && isset($data['choices'][0]['delta'])) { $chunk = $this->parseChunk(...); }   // delta:null -> never parsed
  ...
  if ($finishReason !== 'tool_calls' || $toolCallBuffer === []) { return null; }                     // 'stop' -> never flushed
  ```
  At end of stream, `$toolCallBuffer` is flushed only on the truncated path (`null`, `length`, `abort`).
- **Failure scenario:** some servers, parser versions and proxies (vLLM historically; certain SGLang `tool_choice`/reasoning-parser combinations) end a tool-call stream with `finish_reason: "stop"`, or send the final chunk with `"delta": null`. **Repro:** both cases produce zero tool calls and an empty reply. The model's tool request disappears and the turn ends with nothing.
- **Fix:** at end of stream, if `$toolCallBuffer !== []` and was never emitted, flush it, whatever the finish reason was. Read `finish_reason` independently of `delta`.
- **Test:** cover both repro bodies. Assert that one `Read` call with `{"path":"a.php"}` is yielded.

### A5 — `data:` with no space after the colon is ignored
- **Severity:** Low · **Confidence:** Verified-by-repro (case `no-space`)
- **Where:** `SglangProvider.php:769`, `CustomProvider.php:291`.
- **Code:** `if (str_starts_with($line, 'data: '))`
- **Failure scenario:** the SSE spec allows `data:{...}`, and some OpenAI-compatible gateways emit it. Every chunk is then dropped and the reply is empty.
- **Fix:** strip the `data:` prefix and then `ltrim` a single optional space.
- **Test:** a body using `data:{...}` should yield content.

### A6 — One non-UTF-8 byte in any tool output makes the next provider request fail, non-transiently
- **Severity:** High · **Confidence:** Verified-by-repro (`repro_utf8.php`)
- **Where:** request encoding: Guzzle `'json' => $params` at `SglangProvider.php:648` and `:686`, and `CustomProvider.php:194` and `:258`. The input is not scrubbed: `src/Tools/Concerns/CapturesProcessOutput.php:176-217` (Bash) has no `mb_scrub`, and neither does `TruncatesOutput`.
- **Failure scenario:** Bash runs `printf 'caf\xe9'`, or `cat`/`grep`/`git diff` touches a Latin-1 file, or a binary file is read. **Repro:** the Bash tool result is not valid UTF-8. The next `completeStream()` throws `RuntimeException: SGLANG request failed: json_encode error: Malformed UTF-8 characters`, and `TransientFailure::isTransient()` returns `false`. The turn dies with a confusing error. Any transcript that replays that tool row will fail the same way, on every later step and on Task resume. `CommandBackend::encodeHistory` (`CommandBackend.php:408-416`) gives `_[error: failed to encode history]_` on every turn once such bytes are in history. Vertex fails differently: `VertexProvider::httpBody()` (`:2410`) and `protobufMessage()`/`toProtobufValues()` (`:2487`, `:2507`) use `(string) json_encode(...)`, so the same bytes send an **empty** body. The 400 that comes back is non-transient (see A19) and is then swallowed (A1).
- **Fix:** scrub at one choke point. Either apply `mb_scrub($content, 'UTF-8')` in `ToolResultMessage::__construct`/`Runtime::settle()`, or encode the body yourself with `JSON_INVALID_UTF8_SUBSTITUTE` and pass it as `body`. Do the same in `CommandBackend`/`StreamingCommandBackend::begin`.
- **Test:** build a `ToolResultMessage` with `"\xe9"`, send it through `SglangProvider::completeStream()` with a history middleware, and assert that the request was sent with `�`.

### A7 — `formatToolCalls()` re-serialises every list argument as an object (`JSON_FORCE_OBJECT` applies recursively)
- **Severity:** Medium · **Confidence:** Verified-by-repro (`repro_force_object.php`)
- **Where:** `src/Providers/Concerns/ToolSchema.php:186`. This is used by the Sglang, Custom and OpenAI history formatters.
- **Code:** `'arguments' => json_encode($call->arguments(), JSON_FORCE_OBJECT) ?: '{}',`
- **Failure scenario:** the model calls `mcp__git__git_add {"paths":["a.php","b.php"]}` (`GitMcpServer.php:120` declares `paths` as an array). On every later step and turn, the history sent back says `{"paths":{"0":"a.php","1":"b.php"}}`. The model sees its own earlier calls in the wrong shape and tends to copy them. Any MCP tool whose schema requires an array then gets objects, so validation fails or the server misbehaves. Separately, `?: '{}'` silently replays a call as having no arguments when `json_encode` fails (for example on invalid UTF-8; see A6).
- **Fix:** `$args === [] ? '{}' : json_encode($args, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)`. Force object only at the top level.
- **Test:** replay an assistant tool call with a list argument. Assert that the outgoing `arguments` string decodes to a JSON array.

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
- **Failure scenario:** `extra_body` belongs to the OpenAI Python SDK, which merges it into the body before sending. Sent raw, it is an unknown top-level field. Strict OpenAI-compatible servers (api.openai.com answers "Unrecognized request argument supplied: extra_body", and so do several hosted gateways) return 400 on every request. Through A1, that 400 then becomes a silent empty reply. Servers that ignore unknown fields never see `separate_reasoning` either.
- **Fix:** send `'separate_reasoning' => true` at the top level, and only for providers known to accept it, or make it a config flag.
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
- **Test:** a data provider covering each `PRICE_TABLE` key should get a window of at least 128k.

### A14 — OpenAI cost bills cached prompt tokens at the full input rate
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `OpenAIProvider.php:521-535`. The code is `$promptTokens * $input`, while `parseUsage` already separates `cached` at `:433-439`.
- **Failure scenario:** in long agentic sessions most prompt tokens are cache hits, which OpenAI discounts by 50-90%. Reported spend is inflated by up to about 2x, so `/budget` caps trip early.
- **Fix:** `(prompt - cached) * input + cached * input * cachedFactor + completion * output`, with a per-model cached rate.
- **Test:** usage `{prompt_tokens:1000, prompt_tokens_details:{cached_tokens:900}}` should cost less than 1000 × the input rate.

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

### A17 — `embeddings()` swallows transport errors and returns an empty list
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `SglangProvider.php:967-969`, `CustomProvider.php:378-380`.
- **Failure scenario:** any consumer that does semantic search sees "no embeddings" instead of an outage, so it degrades silently.
- **Fix:** rethrow as `ProviderException`.
- **Test:** a 500 from the mock should cause `embeddings()` to throw.

### A18 — New evidence for known #27/#28: the default provider sends `max_tokens: 4096` with DeepSeek-V4 `reasoning_effort: max`
- **Severity:** (sharpens known High/Medium items) · **Confidence:** Suspected — verification pending. Measure `usage.reasoning_tokens` and `finish_reason` on the live SGLang server for a few agentic prompts at effort `max`.
- **Where:** `SglangProvider.php:1030` (`'max_tokens' => $request->maxTokens ?? 4096`) and `:165` (`DEEPSEEK_V4_REASONING_EFFORT = 'max'`). `EngineBackend::maxOutputTokens()` (`:1251-1269`) is null unless the user sets `maxOutputTokens`.
- **Why this is materially new:** Part II #27 places the 4096 default on Custom/OpenAI. It is also the default path. In SGLang, generated reasoning tokens count against `max_tokens`, so a max-effort think can use the whole budget. That gives `finish_reason: length` with empty content, which is exactly known #28's "thinking-only reply ends turn", now with a likely root cause on the default configuration.
- **Fix:** set a model-aware default (for example 32k+ for V4 at effort max), or omit `max_tokens` so the server's default applies.

### A19 — Vertex transport errors are never classified as transient: 429 `RESOURCE_EXHAUSTED` and 503 `UNAVAILABLE` get no retry, and the turn then ends empty (A1)
- **Severity:** Medium · **Confidence:** Verified-by-repro (`repro_vertex_transient.php`)
- **Where:** `src/Providers/TransientFailure.php:197-237` and `:405-422`. The callers are `VertexProvider.php:318-334`, `:404-415` and `:1384-1391`, each of which sets `errorTransient: TransientFailure::isTransient($e)`.
- **Code:**
  ```php
  if ($error instanceof RequestException) { ... }
  if (method_exists($error, 'getStatusCode')) { ... }   // Google\ApiCore\ApiException has none
  return null;
  // ...and no instanceof arm for ApiException, so the chain walk ends with `false`
  ```
- **Failure scenario:** the vendored gax REST transport (`gax/src/Transport/RestTransport.php:176-177`) converts every Guzzle `RequestException` into `Google\ApiCore\ApiException::createFromRequestException()`. That exception carries the **gRPC** code (`getCode()` gives 8, 14 or 13) and `getStatus()` gives `RESOURCE_EXHAUSTED` and so on. It has **no `previous`** and no `getStatusCode()`. **Repro:** HTTP 429, 503, 500 and 401, and a gRPC-style `UNAVAILABLE(14)`, all return `isTransient=false`. Vertex quota 429s are routine on shared projects, and so are model-overloaded 503s. Every one is reported as a permanent `isError`, with no retry, and Runtime then discards the message (A1), so the user sees an empty reply. `VertexProvider.php:1551-1555` documents that `ApiException` "is caught by complete() and classified by TransientFailure::isTransient()". That claim is false.
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

---

## B. Engine fork protocol and process lifecycle

### B1 — MCP stdio connections are opened in the TUI parent and used from forked turn children whose JSON-RPC id counter resets on every fork; a killed call poisons the next turn with the wrong result
- **Severity:** High · **Confidence:** Verified-by-repro (`repro_mcp_ids.php` with `fake_mcp.php`)
- **Where:** the fork is at `src/Backend/EngineBackend.php:1354-1365`. Servers start in the parent at `src/Cli/Bootstrap.php:6337` (`$client->startServers()`). The transport is `sugar-mcp/src/StdioMcpServer.php:414-421` (`$id = (string) $this->nextId++;`) and `:559-587` (`readResponse` skips lines only when the id differs). Kill sites are `EngineBackend.php:1411-1414` (teardown SIGKILL on Esc, cancel or the 120s idle timeout) and `Runtime.php:2040-2043` (parallel deadline).
- **Failure scenario:** turn 1's child calls MCP tool `slow` with id N. The user presses Esc, or the 120s watchdog (known #7) fires, and the child is SIGKILLed. The MCP server still writes `{"id":"N", result-of-slow}` into the pipe the parent holds. Turn 2's child is forked from the parent, which still has `nextId == N` and an empty read buffer. It calls `fast` with id N again, reads the stale line first, the ids match, and it returns **"result of slow"** (repro output: `turn 2 asked for 'fast', got: result of slow`). The real response for `fast` stays in the pipe, so every later call on that server gets the previous call's result for the rest of the session. The model is fed confidently wrong data with no error shown.
- **Fix:** either own MCP clients only in the parent and proxy calls over the (bidirectional) frame socket, or give each process its own connection. Cheap mitigations: generate ids as `getmypid() . '-' . random`; drain and discard any pending unread lines before a request; restart the server connection after any child is killed mid-call. **LSP has the same flaw but it is latent today.** `src/LSP/LspConnection.php:30` and `:381` use the same `(string) $this->nextId++` counter. However, `Bootstrap::lspTool()` (`Bootstrap.php:6624-6627`) wires `LspTool` with a null client, and nothing in `src/` calls `LspConnection::connect()`. The B1 fix must therefore also cover LSP before LSP is wired (synthesis Wave 3.F).
- **Test:** the repro, as a phpunit test: start a fake server in the parent, fork and kill mid-call, fork and call a different tool, and assert the result names the tool that was asked for.

### B2 — Esc or watchdog teardown kills only the turn process; the shell command it was running keeps going as an orphan
- **Severity:** Medium · **Confidence:** Verified-by-repro (`repro_orphan.php`)
- **Where:** `src/Backend/EngineBackend.php:1411-1414` (`posix_kill($pid, SIGKILL)` on the child pid only). The spawn is at `src/Tools/Concerns/CapturesProcessOutput.php:146-148`, which wraps commands in `setsid -w`, so they live in a separate session and process group.
- **Failure scenario:** the agent runs `make`, `npm test`, a migration or `rm -rf build && …` through Bash, and the user presses Esc, or the 120s idle ceiling (known #7) fires. The turn child dies immediately, but `/bin/sh -c …` and its children keep running (repro: `sleep 37.123` was still alive after the SIGKILL). They keep writing files and spending CPU after the user has "stopped" the agent. Because SIGKILL cannot be caught, the child gets no chance to clean up. Parallel tool grandchildren killed at the deadline (`Runtime.php:2040-2043`) leak the same way.
- **Fix:** have the turn child record the pgids of the commands it spawns, for example in an inherited pipe or file that the parent reads. On teardown, send `SIGTERM` to the child first; a handler there terminates the recorded groups through `ProcessReaper`, then SIGKILL follows. Or start commands with `PR_SET_PDEATHSIG` (via `setpriv --pdeathsig` or an FFI `prctl`).
- **Test:** fork a child that runs `Bash` `sleep 30`, SIGKILL it through the teardown path, and assert that no `sleep 30` process remains within 2s.

### B3 — Both ends of the frame socketpair leak into every process the turn spawns, so a backgrounded command can hold the turn open
- **Severity:** Low · **Confidence:** Verified-by-repro (`repro_fd_inherit.php`)
- **Where:** `EngineBackend.php:1343-1370`. The child never closes `$parentSocket`, and PHP socketpairs are not close-on-exec.
- **Failure scenario:** repro output: a `proc_open`'d `ls /proc/self/fd` shows both socket fds. If the child dies without writing a `result` frame (a fatal error, OOM, or `exitNow` before the write), EOF on the parent end is delayed until every process that inherited the write end exits. A `php -S … &` or `npm run dev &` started by Bash keeps the turn "in flight" until the 120s idle timeout. In the repro, EOF arrived only after the background `sleep 4` exited. The leaked `$parentSocket` copy in the child also means a parent `fclose` never sends EPIPE to the child.
- **Fix:** `fclose($parentSocket)` first thing in the child. Spawn tools with fds above 2 closed: wrap with `/bin/sh -c 'exec 3>&- …'`, use `closefrom` through the setsid wrapper, or set FD_CLOEXEC via `ext-sockets`' `socket_create_pair` plus `socket_export_stream` and `fcntl`.
- **Test:** in a forked child, `proc_open` `ls -l /proc/self/fd` and assert that no `socket:` entries exist beyond stdio.

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

### B6 — The frame writer gives up silently mid-frame, and the reader re-syncs by discarding without tearing down
- **Severity:** Low (it needs a parent stall longer than `default_socket_timeout`, 60s by default) · **Confidence:** Verified-by-repro (`repro_frame_stall.php`, using the real `writeFrame`/`drainFrames` through reflection)
- **Where:** `EngineBackend.php:1742-1755` (`if ($n === false || $n === 0) { return; }`, which can leave a partial frame on the wire) and `:1776-1778` (a bad header gives `$buffer = ''` and parsing continues).
- **Failure scenario:** the child's socket is blocking and subject to PHP's `default_socket_timeout`. **Repro** (timeout 2s): the parent stalls for 4s, and both frames arrive intact. The parent stalls for 9s: `writeFrame` gives up after about 4s with a 4 MB `token` frame only partly written. It returns silently, the child then writes its `result` frame and exits 0, and the parent reads 1,059,776 bytes and decodes **zero frames**. The `result` frame is lost inside the remainder of the truncated frame's declared length. In production this takes a TUI event loop blocked for more than 60s while the child fills the socket buffer (about 200 KB). The turn then ends as "exited without a result" even though the child finished its work, and every streamed token after the cut is dropped.
- **Fix:** on a short write, mark the stream dead and exit the child non-zero. On a bad header, call `teardown('frame stream corrupted')` instead of continuing.
- **Test:** feed `drainFrames` a truncated frame followed by a valid frame, and assert that the stream is declared corrupt rather than silently producing nothing.

---

## C. Runtime

These are covered above: A1 (Runtime discards `errorMessage`), B2 (deadline kill orphans), and B4 (no usage channel on tool results).

### C1 — A parallel batch made only of deadline-exempt jobs never settles if `waitpid` returns -1 (latent; not reachable today)
- **Severity:** Info (latent hardening) · **Confidence:** Verified-by-reading that it is **not reachable** in the current tree. Downgraded from Low/Suspected.
- **Where:** `Runtime.php:2012-2014` settles only when `waitpid` returns the pid; `-1` (ECHILD) is ignored. `:2034` exempts `ExemptFromParallelDeadline` (Task) from the deadline kill.
- **Why it cannot fire now:** the only `pcntl_signal(SIGCHLD, …)` in the monorepo is `candy-pty/src/SignalForwarder.php:165` (`attachSigchld`), and neither sugar-crush nor anything it loads calls it. `candy-pty`'s `ChildPollTrait` uses `waitpid($pid, …)` on its own pid. `AgentWorkerPool` waits per pid. `proc_close()` reaps only its own child. Nothing steals the status of a parallel job's pid. The hazard appears only if a future embedder installs `SIGCHLD=SIG_IGN` or a blanket `pcntl_wait()`. `AgentWorkerPool.php:901` already guards against exactly that case.
- **Fix (cheap hardening):** treat `-1` as settled (and let `collectChildResult` report "status lost").
- **Test:** reap the job pid externally before polling, and assert the generator finishes.

### C2 — Engine-side diagnostics go to `error_log()`, which in the TUI is the terminal itself: they paint raw text over the alt-screen frame
- **Severity:** Medium · **Confidence:** Verified-by-reading. The destination was checked on this machine: `php -r 'error_log("probe-line");'` with the stock ini (`error_log` unset, `log_errors=1`) writes `probe-line` to fd 2. There was no live TUI capture.
- **Where:**
  - `src/Diagnostics/RuntimeNoticeSink.php:366-369`: `warn()` **always** calls `error_log($message)` before queueing the in-transcript notice.
  - `src/Providers/SglangProvider.php:1014` → `:2387-2422`: `flagTruncationRiskInLatestToolResults()` calls `error_log()` on **every request** whose latest tool-result batch contains `</parameter>`, for every non-DeepSeek-V4 model (MiniMax, Qwen and others).
  - Direct `error_log()` calls in `DsmlToolCallParser.php` (`:242`, `:320`, `:354`, `:365`, `:385`, `:467`, `:486`) and `MinimaxXmlFallbackToolCallParser.php` (`:145`, `:224`, `:234`).
  - Nothing redirects fd 2. There is no `ini_set('error_log', …)` and no stderr re-open anywhere in `src/` or `bin/`. The forked turn child (`EngineBackend.php:1349-1360`) inherits the TUI's stderr unchanged.
- **Failure scenario:** in the interactive TUI, stdout and stderr are the same tty. A parser warning, a worktree warning or the per-request `</parameter>` heuristic arrives mid-turn from the forked child as an unframed `PHP …`/`sugarcrush: …` line. It is written at whatever cursor position the renderer left. The diff renderer assumes it owns every cell, so the line stays (or tears the layout) until a full repaint. `Chat.php:14543-14548` already describes this ("`error_log()`, i.e. fd 2, i.e. a frame the renderer believes it owns"), but `RuntimeNoticeSink::warn()` still double-writes to it. The `</parameter>` heuristic is the noisiest case: once a session has Read any file containing that string (any XML or PHP tool-schema code, this repo included), every later request writes a ~500-byte line to the screen until a non-matching tool batch follows.
- **Fix:** at TUI start (`Bootstrap` before `Program::run`), point `error_log` at a session log file (for example `~/.sugar-crush/logs/<session>.log`, mode 0600). Have `RuntimeNoticeSink::warn()` skip `error_log()` while the sink is armed with a transport. Rate-limit the `</parameter>` warning to once per tool-call id.
- **Test:** an integration test runs `bin/sugarcrush` under a PTY (candy-pty) with a scripted provider that triggers a DSML warning, and asserts no `sugarcrush:` bytes appear outside the rendered frame. A unit test asserts that `RuntimeNoticeSink::warn()` writes nothing to `php://stderr` while armed.

### C3 — Project instructions (`CLAUDE.md`/`AGENTS.md` + every `@import`) have no byte budget, while rules have 64 KiB
- **Severity:** Low · **Confidence:** Verified-by-reading
- **Where:** `src/Runtime.php:3009-3041` adds every `loadRoot()`/`loadForced()` document in full. Compare `:2963-2999` and `:3050+`, where user and project rules share `MAX_STANDING_RULE_BYTES = 65_536` (`:161`) with pointer deferral. `src/Context/InstructionFileLoader.php:841-871` expands `@imports` through `ImportResolver` with a depth cap but no size cap. Ancestor `CLAUDE.md`/`AGENTS.md` files (`:401-461`) are added on top.
- **Failure scenario:** a checked-in `CLAUDE.md` that writes `@docs/ARCHITECTURE.md` or `@README.md`, or a parent-directory `AGENTS.md`, puts hundreds of KB into **every** request's system prompt, with no notice. Known #21 says the token estimate ignores the system prompt, so Chat's tiers never see the cost. On a 128k or 200k window this overflows silently: on SGLang via A2, and on Vertex/Custom via A1. On this monorepo, the root `CLAUDE.md` with its two imports is already 25,110 B (`InstructionFileLoader.php:176`).
- **Fix:** charge instruction documents against the same per-build budget (or their own, for example 64 KiB). When a document doesn't fit, defer it to a pointer line the way rules are deferred, and report the overflow as a runtime notice.
- **Test:** a fixture repo whose `CLAUDE.md` imports a 200 KB file should produce a system prompt under the budget, containing a pointer to the deferred import.

---

## Summary table (by severity)

| ID | Severity | Confidence | Title | Location |
|---|---|---|---|---|
| A1 | High | repro | Provider `isError` responses become silent empty replies; `errorMessage` never read (two existing tests pin the bug) | Runtime.php:1419-1459, 1496-1567 |
| A2 | High | repro | SGLang in-stream `{"error":…}` event ignored; turn "succeeds" | SglangProvider.php:769-818 (also Custom) |
| A6 | High | repro | Non-UTF-8 tool output fails the next request permanently (non-transient); Vertex sends an empty body | SglangProvider.php:648/686; CustomProvider; VertexProvider.php:2410; CapturesProcessOutput |
| B1 | High | repro | MCP ids reset per fork; a killed call gives the next turn the wrong result, permanently off by one (LSP: same flaw, latent) | EngineBackend.php:1354; sugar-mcp StdioMcpServer.php:416/582 |
| A3 | Medium | repro | Stream dropped with no `finish_reason` treated as a complete answer (also Custom, Vertex) | SglangProvider.php:836-904; VertexProvider.php:380-403 |
| A4 | Medium | repro | Tool calls dropped when `finish_reason='stop'` or `delta:null` | SglangProvider.php:779, 2048; CustomProvider.php:308, 620 |
| A7 | Medium | repro | `JSON_FORCE_OBJECT` replays list arguments as objects | ToolSchema.php:186 |
| A8 | Medium | repro | Recovered DSML/XML markup stays in content (painted, then sent twice) | SglangProvider.php:785-871 |
| A9 | Medium | repro | MiniMax fallback makes JSON-looking strings into arrays (Write of composer.json breaks) | MinimaxXmlFallbackToolCallParser.php:278-297 |
| A10 | Medium | reading | `CustomProvider` sends a literal `extra_body` key | CustomProvider.php:172, 240 |
| A12 | Medium | repro+reading | claude-code streaming cannot work (no `--verbose`, wrong framing, argv > 128 KiB) | ClaudeCodeProvider.php:99-310 |
| A13 | Medium | reading | OpenAI window is 8k for gpt-4o-mini/4.1 | OpenAIProvider.php:103-112 |
| A15 | Medium | reading | Vertex priced at $0 (spend cap inert); Bedrock invents $0.01 | VertexProvider.php:278; BedrockProvider.php:158 |
| **A19** | Medium | repro | Vertex `ApiException` (429/503/500) never classified transient: no retry, then empty reply | TransientFailure.php:197-237, 405-422; VertexProvider.php:318, 404, 1384 |
| **A20** | Medium | reading | Bedrock tables match only bare ids: real versioned/profile ids get an 8k window and an invented $0.01/1k | BedrockProvider.php:46, 146-169 |
| **A21** | Medium | suspected | Gemini 2.5 default thinking: thought tokens missing from Usage and sharing the 4096 `maxOutputTokens` | VertexProvider.php:1459, 1770-1793 |
| B2 | Medium | repro | Esc/watchdog SIGKILL leaves setsid'd Bash commands running | EngineBackend.php:1411-1414 |
| B4 | Medium (High paid) | reading | Task sub-agent spend never reaches the parent, session total or cap | TaskTool.php:604-608; ToolResult.php; EngineBackend.php:890-941 |
| **C2** | Medium | reading | `error_log()` diagnostics (notice sink, parsers, per-request `</parameter>` warning) paint over the TUI frame | RuntimeNoticeSink.php:366-369; SglangProvider.php:2409; Dsml/Minimax parsers |
| A18 | (sharpens #27/#28) | suspected | Default SGLang `max_tokens` 4096 with effort `max` | SglangProvider.php:1030, 165 |
| A5 | Low | repro | `data:` with no space ignored | SglangProvider.php:769; CustomProvider.php:291 |
| A11 | Low | reading | Malformed argument JSON runs the tool with `[]`; model not told | CustomProvider.php:628; SglangProvider.php:2221 |
| A14 | Low | reading | OpenAI bills cached tokens at full rate | OpenAIProvider.php:521-535 |
| A16 | Low | repro (shape) | Bedrock: no same-role merge, blank text blocks | BedrockProvider.php:316-332 |
| A17 | Low | reading | `embeddings()` swallows errors | SglangProvider.php:967; CustomProvider.php:378 |
| B3 | Low | repro | Socketpair fds leak into spawned processes and delay EOF | EngineBackend.php:1343-1370 |
| B5 | Low | reading | Two withers drop the spend cap (latent) | EngineBackend.php:542, 575 |
| B6 | Low | repro | Frame write times out mid-frame silently; the following `result` frame is swallowed | EngineBackend.php:1742-1796 |
| **C3** | Low | reading | Project instructions and `@imports` have no byte budget (rules have 64 KiB) | Runtime.php:3009-3041; InstructionFileLoader.php:841-871 |
| C1 | Info | reading | `waitpid -1` never settles an exempt parallel job: latent, no SIGCHLD reaper exists | Runtime.php:2012, 2034 |

New in the final pass: **A19, A20, A21, C2, C3**. Re-graded: B6 (Suspected → Verified-by-repro), A16 (Suspected → wire shape verified by repro), C1 (Low/Suspected → Info, not reachable). Sharpened: A1 (the tests that pin the bug; the fix placement that keeps `ReasoningProgressTest` green), A3 (Vertex has no end-of-stream check either), A6 (Vertex empty-body variant), A13 (fix aligned with the `contextWindow()` contract), B1 (LSP is latent with the same flaw).

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
1. LSP fork hazard: **same flaw, latent.** `LspTool` is wired with a null client and nothing calls `LspConnection::connect()`. Folded into B1.
2. `ProviderException` exit code read as an HTTP status: **dropped.** `ProviderException` exposes `exitCode` as a property, not `getStatusCode()`, so `TransientFailure::statusCode()` returns null and a CLI failure is correctly non-transient. Following this lead turned up the real classification gap for Vertex `ApiException`, written up as **A19**.
3. Vertex `defaultStreamer()` SSE parsing: **no new framing defect.** The streamer accepts `data:` without a space, CRLF via `trim`, and a trailing unterminated event. Errors arrive as `isError` chunks and so run into A1. The missing end-of-stream check was added to A3, and the classification gap is A19.
4. Uncapped project instructions: **confirmed** as C3. The other half of the lead (`RuleLoader` re-reading disk every step) is a performance cost only and was not reported. `InstructionFileLoader::loadRoot()` is cached per session.
5. A16 and A18 live validation: A16's wire shape is now verified by repro; the server rejection is not (no AWS credentials). A18 and A21 still need a live model. The SGLang and Vertex endpoints were not called from this audit, so both stay **Suspected**.
6. C1 `SIGCHLD` reaper: **dropped to Info.** The only SIGCHLD handler in the monorepo (`candy-pty` `SignalForwarder::attachSigchld`) is never installed by sugar-crush.
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
