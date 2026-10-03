# 15a — Defect audit: agent engine, fork protocol, Runtime, providers, messages, support

Scope: `sugar-crush/src/Backend/`, `src/Runtime.php`, `src/Providers/` (all providers, ProviderFactory, TransientFailure, usage parsing, SSE parsing, ToolCallParser/*), `src/Messages/`, `src/Support/`, `src/Usage.php`.
Method: read every file in scope with comments stripped (line numbers preserved), then wrote throwaway repro scripts for each candidate under `/home/sites/crush-research-repos/_audit-scratch/15a/`, using mocked Guzzle handlers, real forks and a fake MCP server. Known items from `99-synthesis.md` Part II are not repeated.

**No finding from this audit remains open.** Fixed findings have been removed from this appendix. Live checks still owed are listed once, in `99-synthesis.md` Part IX.

---

## Coverage

**Audited in full:**
- `src/Backend/`: `EngineBackend.php`, `CommandBackend.php`, `StreamingCommandBackend.php`, `EchoBackend.php`, `ObservesReasoning.php`, `ReportsContextWindow.php`, `CancellationToken.php`, `TranscriptTurn.php`, `TurnInterrupted.php`.
- `src/Runtime.php` (all code, including `systemPromptSections()`).
- `src/Providers/`: `SglangProvider.php`, `CustomProvider.php`, `OpenAIProvider.php`, `VertexProvider.php` (including the gax predictor/streamer, the SSE decoder and the protobuf builders), `BedrockProvider.php`, `ClaudeCodeProvider.php`, `ClaudeCodeInvocation.php`, `ProviderFactory.php`, `TransientFailure.php`, `ProviderException.php`, `ProviderInterface.php`, `CompleteRequest.php`, `CompleteResponse.php`, `EchoProvider.php`, `EmbeddingsRequest.php`, `EmbeddingsResponse.php`, `CacheBreakpoints.php`, `Concerns/*` and `ToolCallParser/*`.
- `src/Messages/` (all six files), `src/Support/` (all thirteen files), `src/Usage.php`.

**Checked and found sound (no finding):** Bedrock `AwsException` classification (`getStatusCode()` exists, the chain is preserved, 429/5xx retry); `ContainedPath` (realpath on both sides, separator-anchored prefix); `HookContextFiles` (owner/mode/symlink checks, umask, atomic rename; retention is deliberate); `SystemClipboard` (bounded write, terminate ladder); `HomeDirectory::owned()`; `Frontmatter` repair pass; `EchoProvider`/`EchoBackend`; `CancellationToken`; `TurnInterrupted`. A `ProviderException` exit code is not misread as an HTTP status (`TransientFailure::statusCode()` returns null, so a CLI failure is correctly non-transient).

**Not reported, because they are known or out of scope:**
- Vertex's legacy `:predict` arm (PaLM / `chat-bison`) drops `parameters` (`VertexProvider.php:2126-2139`). The docblock admits it and Google has retired those models, so it is dead-arm debt.
- Gemini tool calling is absent by design: `supportsFunctionCalling()` honestly returns false (`:241-256`).
- The real gax REST server-stream decoding of `streamRawPredict` SSE bodies is exercised only through `VertexProviderTest`'s seams, not against Google.
